<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A row of the salon worksheet (legacy salon_worksheet): what the salon recorded — the order, the payment in cash or by card,
 * the payment on delivery, the salon's expense and the money handed to the office. Amounts are kept exactly as entered.
 *
 * @property int $id
 * @property string $uuid
 * @property string $salon
 * @property bool $transferred_to_db
 * @property Carbon|null $transferred_at
 */
class SalonWorksheetRow extends Model
{
    use HasPreciseTimestamps;

    public const SALONS = ['armada', 'nazarbayeva'];

    public const DATE_FIELDS = ['order_date', 'payment_date', 'delivery_date'];

    public const NUMBER_FIELDS = ['balance_before', 'cash_amount', 'card_amount', 'balance_after', 'cod_amount', 'expense_amount', 'transferred_to_office'];

    public const TEXT_FIELDS = ['order_num', 'manager', 'note', 'recipient_desc'];

    protected $fillable = ['uuid', 'salon', 'order_date', 'order_num', 'manager', 'balance_before', 'payment_date', 'cash_amount', 'card_amount', 'balance_after',
        'note', 'delivery_date', 'cod_amount', 'expense_amount', 'transferred_to_office', 'recipient_desc'];

    protected function casts(): array
    {
        return [
            'order_date' => 'date:Y-m-d',
            'payment_date' => 'date:Y-m-d',
            'delivery_date' => 'date:Y-m-d',
            'transferred_to_db' => 'boolean',
            'transferred_at' => 'immutable_datetime',
            'transferred_tx_ids' => 'array',
        ];
    }
}
