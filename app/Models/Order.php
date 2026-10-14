<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Заказ покупателя (M09, legacy orders). The channel tells whose order it is; channel-bound roles see only theirs
 * (App\Support\ChannelScope). The paid share is the legacy percentage (`payment`), the total is the sum of the lines.
 *
 * @property int $id
 * @property string $num
 * @property string $channel
 * @property string|null $customer
 * @property string|null $end_client
 * @property string|null $seller
 * @property Carbon|null $order_date
 * @property Carbon|null $due_date
 * @property Carbon|null $delivery_date
 * @property Carbon|null $payment_due
 * @property int $payment_pct
 * @property string $status
 * @property string|null $comment
 * @property int $lock_version
 */
class Order extends Model
{
    use HasPreciseTimestamps;

    /** Legacy markOrderDone: a dealer order realized to 99 % of its total may be completed by hand. */
    public const REALIZED_SHARE = '0.99';

    protected $fillable = ['num', 'channel', 'customer', 'end_client', 'seller', 'order_date', 'due_date', 'delivery_date', 'payment_due', 'payment_pct', 'status', 'comment'];

    protected function casts(): array
    {
        return [
            'order_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'delivery_date' => 'date:Y-m-d',
            'payment_due' => 'date:Y-m-d',
            'payment_pct' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('line_no');
    }

    /** @return HasMany<Realization, $this> */
    public function realizations(): HasMany
    {
        return $this->hasMany(Realization::class);
    }
}
