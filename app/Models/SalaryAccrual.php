<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * A payroll record (legacy salary_accruals): an accrual of the salary (type salary) or a payment to the employee (type
 * payment), named by the employee's name as legacy keeps it. Amounts are kept exactly as stored — legacy computes some in
 * the browser (up to 15 decimals). details is the legacy JSON as stored (deductions, accruals, the source of a payment).
 *
 * @property int $id
 * @property string $uuid
 * @property string $emp_name
 * @property string $type
 */
class SalaryAccrual extends Model
{
    use HasPreciseTimestamps;

    public const TYPES = ['salary', 'payment'];

    public const NUMBER_FIELDS = ['amount', 'deducted', 'net'];

    protected function casts(): array
    {
        return ['accrual_date' => 'date:Y-m-d'];
    }
}
