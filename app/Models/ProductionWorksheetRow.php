<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An order line on «Рабочий лист производства» (legacy production_worksheet): the order number and the line's place in it,
 * linked to the line where it exists.
 *
 * @property int $id
 * @property int $legacy_id
 * @property string $order_num
 * @property int $item_idx
 * @property int|null $order_item_id
 */
class ProductionWorksheetRow extends Model
{
    use HasPreciseTimestamps;

    protected function casts(): array
    {
        return ['added_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
