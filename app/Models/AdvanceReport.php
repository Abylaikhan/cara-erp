<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Авансовый отчёт (M12, legacy advance_reports): money issued to an employee, what was spent (by lines) and returned.
 *
 * @property int $id
 * @property string|null $doc_num
 * @property string $employee_name
 * @property Carbon $report_date
 * @property string|null $purpose
 * @property string $issued
 * @property string $spent
 * @property string $returned
 * @property string $status
 * @property int|null $account_id
 * @property int|null $transaction_id
 * @property list<array<string, mixed>> $lines
 */
class AdvanceReport extends Model
{
    use HasPreciseTimestamps;

    protected function casts(): array
    {
        return ['report_date' => 'date:Y-m-d', 'lines' => 'array', 'account_id' => 'integer', 'transaction_id' => 'integer'];
    }
}
