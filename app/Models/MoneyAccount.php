<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Касса или расчётный счёт (M12, legacy bankAccounts). The account number is not stored; the opening balance is the one
 * legacy adds to a bank account's balance.
 *
 * @property int $id
 * @property string $name
 * @property string|null $bank
 * @property string|null $bik
 * @property string $currency
 * @property string $type
 * @property string|null $icon
 * @property string $opening_balance
 * @property int $sort_order
 */
class MoneyAccount extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['name', 'bank', 'bik', 'currency', 'type', 'icon', 'opening_balance', 'sort_order'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'sort_order' => 'integer'];
    }
}
