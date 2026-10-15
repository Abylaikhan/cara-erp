<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A line of a bank statement (legacy bank_statement_raw): Kaspi or Halyk, income or expense, the counterparty as the bank names
 * it. It is compared with the journal by day and does not change the journal.
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property Carbon|null $row_date
 * @property string $flow
 * @property string $amount
 * @property bool $transferred_to_db
 */
class BankStatementRow extends Model
{
    use HasPreciseTimestamps;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['row_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'transferred_to_db' => 'boolean', 'transferred_at' => 'immutable_datetime', 'account_id' => 'integer'];
    }
}
