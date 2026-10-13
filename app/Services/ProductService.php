<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Catalog operations (M07): transactions, row locks, lock_version against lost updates, audit trail. */
class ProductService
{
    public const STALE = 'Товар изменён другим пользователем — обновите страницу и повторите.';

    private const FIELDS = ['name', 'category', 'price_retail', 'price_wholesale', 'unit', 'sku', 'description'];

    /** @param array<string, mixed> $data */
    public function create(array $data, bool $deleteRequested, User $by): Product
    {
        return DB::transaction(function () use ($data, $deleteRequested, $by) {
            $product = new Product(array_intersect_key($data, array_flip(self::FIELDS)));
            $product->forceFill([
                'created_by_id' => $by->id, 'created_by_name' => $by->name,
                'updated_by_id' => $by->id, 'updated_by_name' => $by->name,
                'delete_requested' => $deleteRequested,
                'delete_requested_by_id' => $deleteRequested ? $by->id : null,
                'delete_requested_by_name' => $deleteRequested ? $by->name : null,
            ])->save();
            Audit::record('product.created', 'product', $product->id, $product->only(self::FIELDS), $by);

            return $product;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data, bool $deleteRequested, int $lockVersion, User $by): Product
    {
        return DB::transaction(function () use ($id, $data, $deleteRequested, $lockVersion, $by) {
            $product = Product::query()->whereKey($id)->lockForUpdate()->first();
            if ($product === null || ! $product->active) {
                throw ValidationException::withMessages(['product' => 'Товар не найден или уже удалён — обновите страницу.']);
            }
            if ($product->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => self::STALE]);
            }
            $before = $product->only(self::FIELDS);
            $product->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            if ($product->isDirty(self::FIELDS) && ! Gate::forUser($by)->allows('editFields', $product)) {
                // Legacy shows these fields disabled; the server now refuses the change, not only the form.
                throw new AuthorizationException('Изменять поля товара может его автор, директор или главный бухгалтер.');
            }
            $changes = collect($product->getDirty())->map(fn ($new, $key) => ['from' => $before[$key] ?? null, 'to' => $new])->all();
            if ($product->delete_requested !== $deleteRequested) {
                $changes['delete_requested'] = ['from' => $product->delete_requested, 'to' => $deleteRequested];
            }
            $product->forceFill([
                'delete_requested' => $deleteRequested,
                'delete_requested_by_id' => $deleteRequested ? ($product->delete_requested ? $product->delete_requested_by_id : $by->id) : null,
                'delete_requested_by_name' => $deleteRequested ? ($product->delete_requested ? $product->delete_requested_by_name : $by->name) : null,
                'updated_by_id' => $by->id, 'updated_by_name' => $by->name,
            ]);
            $product->lock_version++;
            $product->save();
            if ($changes) {
                Audit::record('product.updated', 'product', $product->id, $changes, $by);
            }

            return $product;
        });
    }

    public function deactivate(int $id, User $by): Product
    {
        return DB::transaction(function () use ($id, $by) {
            $product = Product::query()->whereKey($id)->lockForUpdate()->first();
            if ($product === null || ! $product->active) {
                throw ValidationException::withMessages(['product' => 'Товар не найден или уже удалён — обновите страницу.']);
            }
            Gate::forUser($by)->authorize('delete', $product);
            $product->forceFill(['active' => false, 'deactivated_at' => now(), 'updated_by_id' => $by->id, 'updated_by_name' => $by->name])->lock_version++;
            $product->save();
            Audit::record('product.deactivated', 'product', $product->id, ['name' => $product->name], $by);

            return $product;
        });
    }
}
