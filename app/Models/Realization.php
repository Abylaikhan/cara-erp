<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Реализация (M09, legacy realizations): a posted shipment line. Legacy links it to the order by number only, and some
 * lines name orders that do not exist; `order_id` is set when the number is found.
 *
 * @property int $id
 * @property string|null $order_num
 * @property int|null $order_id
 * @property string|null $doc_num
 * @property Carbon $doc_date
 * @property string|null $customer
 * @property string $name
 * @property string $qty
 * @property string|null $unit
 * @property string $revenue
 */
class Realization extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['order_num', 'doc_num', 'doc_date', 'customer', 'name', 'qty', 'unit', 'revenue'];

    protected function casts(): array
    {
        return ['doc_date' => 'date:Y-m-d', 'qty' => 'decimal:4', 'revenue' => 'decimal:2', 'order_id' => 'integer'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
