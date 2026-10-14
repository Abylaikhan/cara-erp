<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Физический склад (legacy PHYSICAL_WAREHOUSES, L27578). `categories` are the material categories legacy shows under this
 * warehouse on «По складам»; the stock row itself points to a warehouse by `warehouse_code`.
 *
 * @property string $code
 * @property string $name
 * @property string $icon
 * @property string $color
 * @property string $type
 * @property list<string> $categories
 * @property int $sort_order
 */
class Warehouse extends Model
{
    use HasPreciseTimestamps;

    public const TYPES = ['material', 'semi', 'product'];

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['categories' => 'array', 'sort_order' => 'integer'];
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('code');
    }
}
