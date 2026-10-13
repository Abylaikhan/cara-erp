<?php

namespace App\Http\Controllers;

use App\Http\Requests\Products\ProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** M07 «Каталог» — legacy page-catalog (L3551) and loadCatalogFromDB/openProductModal/saveProduct/deleteProduct/renderCatalog. */
class ProductController extends Controller
{
    public function __construct(private readonly ProductService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Product::class);
        $user = $request->user();
        $products = Product::query()->active()->orderBy('category')->orderBy('name')->orderBy('id')->get();

        return Inertia::render('Catalog', [
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category' => $p->category,
                'price_retail' => (float) $p->price_retail,
                'price_wholesale' => (float) $p->price_wholesale,
                'unit' => $p->unit,
                'sku' => $p->sku,
                'description' => $p->description,
                'delete_requested' => $p->delete_requested,
                'delete_requested_by' => $p->delete_requested_by_name,
                'created_by' => $p->created_by_name,
                'updated_by' => $p->updated_by_name,
                'created_at' => $p->created_at?->toIso8601String(),
                'can_edit' => $user->can('editFields', $p),
                'lock_version' => $p->lock_version,
            ])->values(),
            'canDelete' => Gate::allows('delete', new Product),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        Gate::authorize('create', Product::class);
        $this->service->create($request->fields(), $request->boolean('delete_requested'), $request->user());

        return back()->with('toast', '✅ Товар добавлен');
    }

    public function update(ProductRequest $request, int $product): RedirectResponse
    {
        $this->service->update($product, $request->fields(), $request->boolean('delete_requested'), (int) $request->validated('lock_version'), $request->user());

        return back()->with('toast', '✅ Товар обновлён');
    }

    public function destroy(Request $request, int $product): RedirectResponse
    {
        $this->service->deactivate($product, $request->user());

        return back();
    }
}
