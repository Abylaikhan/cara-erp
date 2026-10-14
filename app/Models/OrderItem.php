<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Позиция заказа (M09, legacy order_items). `line_no` is the line's place in the order as legacy lists it; production
 * progress and the worksheets refer to a line by that index.
 *
 * @property int $id
 * @property int $order_id
 * @property int $line_no
 * @property string $name
 * @property string|null $item_type
 * @property string|null $category
 * @property string $qty
 * @property string $price
 * @property int $progress
 * @property string $status
 * @property string|null $resp
 * @property Carbon|null $item_date
 * @property array<array-key, mixed> $attrs legacy «attributes» (characteristics of the line)
 */
class OrderItem extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['line_no', 'name', 'item_type', 'category', 'qty', 'price', 'progress', 'status', 'resp', 'item_date', 'attrs'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'price' => 'decimal:2',
            'progress' => 'integer',
            'line_no' => 'integer',
            'item_date' => 'date:Y-m-d',
            'attrs' => 'array',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
