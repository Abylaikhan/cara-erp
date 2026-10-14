<?php

namespace App\Http\Controllers;

use App\Http\Requests\Warehouse\MaterialAliasRequest;
use App\Http\Requests\Warehouse\MaterialCellRequest;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\MaterialService;
use App\Support\LegacyNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M03 «Склад» — legacy page-warehouse (L2470–2884): loadWarehouseFromDB, renderWhWarehouses/renderWhStocks/renderWhWorkshops,
 * renderWarehouse, startEditCell, deleteWarehouseRow, loadAliasesFromDB/renderAliases/saveAlias/deleteAlias.
 */
class WarehouseController extends Controller
{
    public function __construct(private readonly MaterialService $service) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', Material::class);
        $materials = Material::query()->active()->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'category', 'unit', 'sku', 'qty', 'price', 'min_qty', 'warehouse_code', 'lock_version']);

        return Inertia::render('Warehouse', [
            // Legacy loads the directory ordered by name and shows quantities rounded to 0.01; the value of a row is
            // computed here exactly (numeric), the browser only formats and adds it up for display.
            'materials' => $materials->map(fn (Material $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'category' => $m->category,
                'unit' => $m->unit,
                'sku' => $m->sku,
                'qty' => (float) LegacyNumber::round2($m->qty),
                'price' => (float) $m->price,
                'min_qty' => (float) $m->min_qty,
                'value' => (float) bcmul(LegacyNumber::round2($m->qty), (string) $m->price, 4), // exact: 2 + 2 decimals
                'warehouse' => $m->warehouse_code,
                'lock_version' => $m->lock_version,
            ])->values(),
            'stockValue' => $materials->reduce(fn (string $sum, Material $m) => bcadd($sum, bcmul(LegacyNumber::round2($m->qty), (string) $m->price, 4), 4), '0'),
            'warehouses' => Warehouse::query()->ordered()->get()->map(fn (Warehouse $w) => $w->only(['code', 'name', 'icon', 'color', 'type', 'categories']))->values(),
            'workshops' => config('erp.workshops'),
            // Legacy order: material_name, alias_name.
            'aliases' => MaterialAlias::query()->orderBy('material_name')->orderBy('alias_name')->orderBy('id')
                ->get(['id', 'material_name', 'alias_name', 'category'])->values(),
            'suppliers' => Supplier::query()->active()->orderBy('name')->orderBy('id')->get(['id', 'name'])->values(),
        ]);
    }

    public function updateMaterial(MaterialCellRequest $request, int $material): RedirectResponse
    {
        $this->service->update($material, $request->changes(), (int) $request->validated('lock_version'), $request->user());

        return back();
    }

    public function destroyMaterial(Request $request, int $material): RedirectResponse
    {
        $this->service->deactivate($material, $request->user());

        return back();
    }

    public function storeAlias(MaterialAliasRequest $request): RedirectResponse
    {
        Gate::authorize('manageAliases', Material::class);
        $this->service->createAlias($request->validated(), $request->user());

        return back();
    }

    public function destroyAlias(Request $request, int $alias): RedirectResponse
    {
        Gate::authorize('manageAliases', Material::class);
        $this->service->deleteAlias($alias, $request->user());

        return back();
    }
}
