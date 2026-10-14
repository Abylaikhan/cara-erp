<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Денежная операция (M12, legacy money_tx): income or expense of a cash desk or bank account, with its category, the expense
 * item (legacy «subcategory»), the counterparty and the order it pays.
 *
 * @property int $id
 * @property int $account_id
 * @property Carbon $tx_date
 * @property string $flow
 * @property string|null $category
 * @property int|null $expense_category_id
 * @property string $amount
 * @property string|null $amount_fx
 * @property string|null $fx_currency
 * @property string|null $description
 * @property string|null $counterparty
 * @property string|null $bin
 * @property string|null $order_num
 * @property int|null $order_id
 */
class MoneyTransaction extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['account_id', 'tx_date', 'flow', 'category', 'expense_category_id', 'amount', 'amount_fx', 'fx_currency', 'description', 'counterparty', 'bin', 'order_num'];

    protected function casts(): array
    {
        return ['tx_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'amount_fx' => 'decimal:2', 'account_id' => 'integer', 'expense_category_id' => 'integer', 'order_id' => 'integer'];
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }
}
