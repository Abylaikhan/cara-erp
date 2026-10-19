<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An employee (legacy staff, M17): position, type (АУП, офис, производство), workshop, salary and the conditions of work
 * the payroll reads. Payroll records name the employee by text, as legacy does. No PIN here (ADR-0004).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property bool $active
 */
class Employee extends Model
{
    use HasPreciseTimestamps;

    public const TYPES = ['aup', 'office', 'prod'];

    public const NUMBER_FIELDS = ['salary', 'base_salary', 'commission_pct', 'deduction_meal', 'deduction_opv_fixed', 'deduction_opv_pct',
        'deduction_osms_fixed', 'deduction_osms_pct', 'deduction_so_pct', 'piece_goal'];

    public const DATE_FIELDS = ['hired', 'fired', 'fired_date'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'hired' => 'date:Y-m-d', 'fired' => 'date:Y-m-d', 'fired_date' => 'date:Y-m-d'];
    }

    /** @return HasMany<EmployeeSalaryHistory, $this> */
    public function salaryHistory(): HasMany
    {
        return $this->hasMany(EmployeeSalaryHistory::class);
    }

    /** @return HasMany<EmployeeChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(EmployeeChange::class);
    }
}
