<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Warehouse directory (M03): inline edits of material fields, deactivation, material aliases. Stock quantities are not changed
 * here — they move only through warehouse documents (ERP-039/040, decisions D-04/D-05).
 */
class MaterialService
{
    public const STALE = 'Материал изменён другим пользователем — обновите страницу и повторите.';

    public const NOT_FOUND = 'Материал не найден или уже удалён — обновите страницу.';

    public const IN_STOCK = 'Материал с остатком удалить нельзя: остаток списывается документом склада.';

    private const FIELDS = ['name', 'sku', 'unit', 'price', 'min_qty'];

    /** @param array<string, mixed> $changes */
    public function update(int $id, array $changes, int $lockVersion, User $by): Material
    {
        return DB::transaction(function () use ($id, $changes, $lockVersion, $by) {
            $material = $this->locked($id);
            if ($material->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => self::STALE]);
            }
            $before = $material->only(self::FIELDS);
            $material->forceFill(array_intersect_key($changes, array_flip(self::FIELDS)));
            $diff = collect($material->getDirty())->map(fn ($new, $key) => ['from' => $before[$key] ?? null, 'to' => $new])->all();
            if ($diff) {
                $material->forceFill(['updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
                $material->lock_version++;
                $material->save();
                Audit::record('material.updated', 'material', $material->id, $diff, $by);
            }

            return $material;
        });
    }

    /** Legacy deleteWarehouseRow removes the row only from the page; here an empty material is deactivated. */
    public function deactivate(int $id, User $by): Material
    {
        return DB::transaction(function () use ($id, $by) {
            $material = $this->locked($id);
            if (bccomp((string) $material->qty, '0', 3) !== 0) {
                throw ValidationException::withMessages(['material' => self::IN_STOCK]);
            }
            $material->forceFill(['active' => false, 'deactivated_at' => now(), 'updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
            $material->lock_version++;
            $material->save();
            Audit::record('material.deactivated', 'material', $material->id, ['name' => $material->name], $by);

            return $material;
        });
    }

    /**
     * Legacy saveAlias stores the material by name with its category. The material must exist; the same supplier name for the
     * same material is not stored twice (the legacy copy has every alias twice).
     *
     * @param  array{material_name: string, alias_name: string, supplier_id: int|string|null, coeff: int|float|string, comment: string|null}  $data
     */
    public function createAlias(array $data, User $by): MaterialAlias
    {
        return DB::transaction(function () use ($data, $by) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('material_aliases')]);
            $material = Material::query()->active()->where('name', $data['material_name'])->orderBy('id')->first();
            if ($material === null) {
                throw ValidationException::withMessages(['material_name' => 'Материал не найден на складе — обновите страницу.']);
            }
            if ($data['supplier_id'] !== null && ! Supplier::query()->active()->whereKey($data['supplier_id'])->exists()) {
                throw ValidationException::withMessages(['supplier_id' => 'Поставщик не найден — обновите страницу.']);
            }
            $duplicate = MaterialAlias::query()->where('material_name', $material->name)
                ->whereRaw('lower(alias_name) = lower(?)', [$data['alias_name']])->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['alias_name' => 'Такой алиас у этого материала уже есть']);
            }
            $alias = new MaterialAlias([
                'material_name' => $material->name, 'alias_name' => $data['alias_name'], 'category' => $material->category,
                'supplier_id' => $data['supplier_id'], 'coeff' => $data['coeff'], 'comment' => $data['comment'],
            ]);
            $alias->forceFill(['created_by_id' => $by->id, 'created_by_name' => $by->name])->save();
            Audit::record('material_alias.created', 'material_alias', $alias->id, $alias->only(['material_name', 'alias_name', 'category', 'supplier_id', 'coeff', 'comment']), $by);

            return $alias;
        });
    }

    /** Legacy deleteAlias deletes the row; the audit trail keeps what it was. */
    public function deleteAlias(int $id, User $by): void
    {
        DB::transaction(function () use ($id, $by) {
            $alias = MaterialAlias::query()->whereKey($id)->lockForUpdate()->first();
            if ($alias === null) {
                throw ValidationException::withMessages(['alias' => 'Алиас не найден или уже удалён — обновите страницу.']);
            }
            Audit::record('material_alias.deleted', 'material_alias', $alias->id, $alias->only(['material_name', 'alias_name', 'category', 'supplier_id', 'coeff', 'comment']), $by);
            $alias->delete();
        });
    }

    private function locked(int $id): Material
    {
        $material = Material::query()->whereKey($id)->lockForUpdate()->first();
        if ($material === null || ! $material->active) {
            throw ValidationException::withMessages(['material' => self::NOT_FOUND]);
        }

        return $material;
    }
}
