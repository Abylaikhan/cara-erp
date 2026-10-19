<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A salary of an employee from a day on (legacy staff_salary_history): the payroll of a month takes the one in force. */
class EmployeeSalaryHistory extends Model
{
    use HasPreciseTimestamps;

    protected $table = 'employee_salary_history';

    public const NUMBER_FIELDS = ['salary', 'commission_pct'];

    protected function casts(): array
    {
        return ['valid_from' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
