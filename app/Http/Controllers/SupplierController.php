<?php

namespace App\Http\Controllers;

use App\Http\Requests\Suppliers\SupplierRequest;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** M06 «Поставщики» — legacy page-suppliers (L3238–3342), loadSuppliersFromDB/renderSupplierList/openSupplierCard/saveSupplierCard/deleteSupplier. */
class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $service) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', Supplier::class);
        // Legacy loads suppliers ordered by name (order=name).
        $suppliers = Supplier::query()->active()->with('expenseCategory')->orderBy('name')->orderBy('id')->get();
        $referenced = $suppliers->pluck('expense_category_id')->filter()->unique();

        return Inertia::render('Suppliers', [
            'suppliers' => $suppliers->map(fn (Supplier $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'category' => $s->category,
                'status' => $s->status,
                'contact' => $s->contact,
                'phone' => $s->phone,
                'email' => $s->email,
                'bin' => $s->bin,
                'address' => $s->address,
                'lead_time_days' => $s->lead_time_days,
                'turnover' => $s->turnover === null ? null : (float) $s->turnover,
                'defect_pct' => $s->defect_pct === null ? null : (float) $s->defect_pct,
                'comment' => $s->comment,
                'expense_category_id' => $s->expenseCategory?->parent_id ?? $s->expenseCategory?->id,
                'expense_article_id' => $s->expenseCategory?->parent_id !== null ? $s->expenseCategory?->id : null,
                'expense_source' => $s->expense_source,
                'lock_version' => $s->lock_version,
            ])->values(),
            'expenseCategories' => $this->expenseOptions($referenced->all()),
        ]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        Gate::authorize('create', Supplier::class);
        $this->service->create($request->fields(), $this->id($request, 'expense_category_id'), $this->id($request, 'expense_article_id'), $request->user());

        return back();
    }

    public function update(SupplierRequest $request, int $supplier): RedirectResponse
    {
        $this->service->update($supplier, $request->fields(), $this->id($request, 'expense_category_id'), $this->id($request, 'expense_article_id'),
            (int) $request->validated('lock_version'), $request->user());

        return back();
    }

    public function destroy(Request $request, int $supplier): RedirectResponse
    {
        $this->service->deactivate($supplier, $request->user());

        return back();
    }

    /** Legacy «Объединить» (mergeSupplierGroup). */
    public function merge(Request $request): RedirectResponse
    {
        Gate::authorize('create', Supplier::class);
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'merged_ids' => ['required', 'array', 'min:1', 'max:100'],
            'merged_ids.*' => ['integer', 'distinct'],
        ]);
        $this->service->merge((int) $data['supplier_id'], $data['merged_ids'], $request->user());

        return back();
    }

    /** Legacy «📥 Импорт CSV» (importSuppliersCSV): the answer is the legacy alert or confirmation text. */
    public function import(Request $request): RedirectResponse
    {
        Gate::authorize('create', Supplier::class);
        $file = $request->file('file');
        $dialog = match (true) {
            $file === null || ! $file->isValid() => ['type' => 'alert', 'text' => 'Файл не получен — выберите CSV ещё раз.'],
            $file->getSize() > 2 * 1024 * 1024 => ['type' => 'alert', 'text' => 'Файл больше 2 МБ — разделите его на части.'],
            default => $this->service->importCsv((string) file_get_contents($file->getRealPath()), $request->boolean('apply'), $request->user()),
        };

        return back()->with('dialog', $dialog);
    }

    /**
     * Categories and articles of «Статьи расходов» for the card (legacy populateSupCardExpCat/updateSupCardExpSubcat), in the
     * directory order; an item deleted there but still linked to a supplier stays selectable, marked as deleted.
     *
     * @param  list<int>  $referenced
     * @return list<array{id: int, name: string, deleted: bool, articles: list<array{id: int, name: string, deleted: bool}>}>
     */
    private function expenseOptions(array $referenced): array
    {
        $items = ExpenseCategory::query()
            ->where(fn ($q) => $q->where('active', true)->orWhereIn('id', $referenced)
                ->orWhereIn('id', ExpenseCategory::query()->select('parent_id')->whereIn('id', $referenced)))
            ->orderBy('sort_order')->orderBy('id')->get();
        $option = fn (ExpenseCategory $c) => ['id' => $c->id, 'name' => $c->name, 'deleted' => ! $c->active];

        return $items->whereNull('parent_id')->map(fn (ExpenseCategory $top) => $option($top) + [
            'articles' => $items->where('parent_id', $top->id)->map($option)->values()->all(),
        ])->values()->all();
    }

    private function id(SupplierRequest $request, string $field): ?int
    {
        $value = $request->validated($field);

        return $value === null ? null : (int) $value;
    }
}
