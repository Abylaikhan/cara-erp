<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Товар каталога (M07). Deleting deactivates the row: orders keep their own snapshot of the item, so editing or
 * removing a product never changes a historical order (AC-16).
 *
 * @property int $id
 * @property string $name
 * @property string $category
 * @property string $price_retail
 * @property string $price_wholesale
 * @property string $unit
 * @property bool $active
 * @property bool $delete_requested
 * @property int|null $created_by_id
 * @property string|null $created_by_name
 * @property int $lock_version
 */
class Product extends Model
{
    use HasPreciseTimestamps;

    public const UNITS = ['шт', 'м.п.', 'м²', 'компл'];

    protected $fillable = ['name', 'category', 'price_retail', 'price_wholesale', 'unit', 'sku', 'description'];

    protected function casts(): array
    {
        return [
            'price_retail' => 'decimal:2',
            'price_wholesale' => 'decimal:2',
            'active' => 'boolean',
            'delete_requested' => 'boolean',
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
