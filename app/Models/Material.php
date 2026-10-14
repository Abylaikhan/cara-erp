<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Материал склада (M03, legacy table warehouse / warehouseData). Directory fields are edited in place; the quantity changes
 * only through warehouse documents (ERP-039, D-04/D-05). Deleting deactivates a material without stock.
 *
 * @property int $id
 * @property string $name
 * @property string $category
 * @property string $unit
 * @property string|null $sku
 * @property string $qty
 * @property string $price
 * @property string $min_qty
 * @property string|null $supplier_name
 * @property string|null $warehouse_code
 * @property bool $active
 * @property int $lock_version
 */
class Material extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['name', 'category', 'unit', 'sku', 'price', 'min_qty', 'supplier_name', 'warehouse_code'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'price' => 'decimal:2',
            'min_qty' => 'decimal:3',
            'active' => 'boolean',
            'lock_version' => 'integer',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
