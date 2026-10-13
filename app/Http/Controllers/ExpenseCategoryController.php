<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseCategories\MergeExpenseCategoriesRequest;
use App\Http\Requests\ExpenseCategories\StoreExpenseCategoryRequest;
use App\Http\Requests\ExpenseCategories\UpdateExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use App\Services\ExpenseCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** M14 «Статьи расходов» — legacy page-expense_cats (L6786) and its functions L11063–11257. */
class ExpenseCategoryController extends Controller
{
    public function __construct(private readonly ExpenseCategoryService $service) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', ExpenseCategory::class);
        $all = ExpenseCategory::query()->active()->orderBy('sort_order')->orderBy('id')->get();
        $codes = $all->pluck('code', 'id');

        return Inertia::render('ExpenseCats', [
            'categories' => $all->map(fn (ExpenseCategory $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'parent_id' => $c->parent_id,
                'parent_code' => $c->parent_id !== null ? $codes->get($c->parent_id) : null,
                'is_fixed' => $c->is_fixed,
                'sort_order' => $c->sort_order,
                'lock_version' => $c->lock_version,
            ])->values(),
        ]);
    }

    public function store(StoreExpenseCategoryRequest $request): RedirectResponse
    {
        $this->service->create($request->payload(), $request->user());

        return back();
    }

    public function update(UpdateExpenseCategoryRequest $request, int $category): RedirectResponse
    {
        $this->service->update($category, $request->payload(), (int) $request->validated('lock_version'), $request->user());

        return back();
    }

    public function destroy(Request $request, int $category): RedirectResponse
    {
        Gate::authorize('manage', ExpenseCategory::class);
        $this->service->deactivate($category, $request->user());

        return back();
    }

    public function merge(MergeExpenseCategoriesRequest $request): RedirectResponse
    {
        $merge = $this->service->merge((int) $request->validated('from_id'), (int) $request->validated('to_id'), $request->user());
        $codes = ExpenseCategory::query()->whereIn('id', [$merge->from_id, $merge->to_id])->pluck('code', 'id');

        // Same confirmation text as legacy mergeExpCats.
        return back()->with('alert', "✅ Готово — все транзакции \"{$codes[$merge->from_id]}\" перенесены в \"{$codes[$merge->to_id]}\"");
    }
}
