<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A change of the conditions of work of an employee (legacy staff_changes): the salary and the deductions from a day on. */
class EmployeeChange extends Model
{
    use HasPreciseTimestamps;

    public const NUMBER_FIELDS = ['base_salary', 'deduction_meal', 'deduction_opv_pct', 'deduction_osms_pct', 'deduction_so_pct'];

    protected function casts(): array
    {
        return ['change_date' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
