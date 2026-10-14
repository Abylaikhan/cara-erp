<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Прогресс позиции на рабочем листе производства (M02, legacy production_progress): stages done on an order line and the
 * materials taken for it, as legacy stores them.
 *
 * @property int $id
 * @property int $order_item_id
 * @property list<array<string, mixed>> $stages
 * @property list<array<string, mixed>> $materials
 * @property bool $completed
 */
class ProductionProgress extends Model
{
    use HasPreciseTimestamps;

    protected $table = 'production_progress';

    protected function casts(): array
    {
        return ['stages' => 'array', 'materials' => 'array', 'completed' => 'boolean', 'order_item_id' => 'integer'];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /** Legacy mrpEffectiveStatus: a recorded stage, a taken material or completion means the line is being worked on. */
    public function hasActivity(): bool
    {
        return $this->stages !== [] || $this->materials !== [] || $this->completed;
    }
}
