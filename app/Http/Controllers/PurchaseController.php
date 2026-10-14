<?php

namespace App\Http\Controllers;

use App\Http\Requests\Purchases\MaterialRequestRequest;
use App\Models\Material;
use App\Models\MaterialRequest;
use App\Services\MaterialRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M04 «Закупки» — legacy page-purchases (L2884–2993): initPurchasesExtra, renderMaterialRequests(Kanban), updatePurchKpis,
 * renderPlanFact (L34416–34620). The old purchase orders are switched off in legacy (D-07) and are not shown there either.
 * Goods receipts (ERP-046, D-04) and consumption norms (technological cards, ERP-028) are empty in legacy and come later.
 */
class PurchaseController extends Controller
{
    public function __construct(private readonly MaterialRequestService $service) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', MaterialRequest::class);
        // Legacy loads requests newest first (order=created_at.desc).
        $requests = MaterialRequest::query()->orderByDesc('created_at')->orderByDesc('id')->get();

        return Inertia::render('Purchases', [
            'requests' => $requests->map(fn (MaterialRequest $r) => [
                'id' => $r->id,
                // Legacy cut the date off the UTC timestamp; the business date is shown here (D-14).
                'date' => $r->created_at?->setTimezone(config('erp.business_timezone'))->format('d.m.Y'),
                'material_name' => $r->material_name,
                'qty' => (float) $r->qty,
                'unit' => $r->unit,
                'order_num' => $r->order_num,
                'item_name' => $r->item_name,
                'workshop' => $r->workshop,
                'requested_by' => $r->requested_by,
                'status' => $r->status,
                'lock_version' => $r->lock_version,
            ])->values(),
            'planFact' => $this->planFact($requests),
            'workshops' => config('erp.piece_workshops'),
        ]);
    }

    public function store(MaterialRequestRequest $request): RedirectResponse
    {
        Gate::authorize('create', MaterialRequest::class);
        $this->service->create($request->validated(), $request->user());

        return back();
    }

    public function status(Request $request, int $materialRequest): RedirectResponse
    {
        Gate::authorize('update', MaterialRequest::class);
        $data = $request->validate([
            'status' => ['required', Rule::in(['in_purchase', 'received'])],
            'lock_version' => ['required', 'integer'],
        ]);
        $this->service->advance($materialRequest, $data['status'], (int) $data['lock_version'], $request->user());

        return back();
    }

    /**
     * Legacy renderPlanFact: «Нужно» adds the open requests (not yet arrived) by material name, the unit is the first request's;
     * «Куплено» comes from goods receipts, which legacy has none of; «Остаток» is the stock of the material with that exact
     * name; «К покупке» = Нужно − Остаток, not below zero; most to buy first.
     *
     * @param  Collection<int, MaterialRequest>  $requests
     * @return list<array<string, mixed>>
     */
    private function planFact($requests): array
    {
        $byMaterial = [];
        foreach ($requests->where('status', '!=', 'received') as $r) {
            $byMaterial[$r->material_name] ??= ['name' => $r->material_name, 'unit' => $r->unit ?? '', 'need' => '0'];
            $byMaterial[$r->material_name]['need'] = bcadd($byMaterial[$r->material_name]['need'], $r->qty, 4);
        }
        $stock = Material::query()->active()->whereIn('name', array_keys($byMaterial))->orderBy('id')->get(['name', 'qty', 'unit'])
            ->unique('name')->keyBy('name');
        $rows = [];
        foreach ($byMaterial as $name => $m) {
            $material = $stock->get($name);
            $have = $material === null ? '0' : (string) $material->qty;
            $toBuy = bccomp($m['need'], $have, 4) > 0 ? bcsub($m['need'], $have, 4) : '0';
            $rows[] = ['name' => $name, 'unit' => $m['unit'] !== '' ? $m['unit'] : ($material->unit ?? ''), 'need' => (float) $m['need'], 'bought' => 0.0,
                'stock' => (float) $have, 'to_buy' => (float) $toBuy];
        }
        usort($rows, fn ($a, $b) => $b['to_buy'] <=> $a['to_buy']);

        return $rows;
    }
}
