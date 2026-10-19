<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

/** A line of the salon payroll of a month (legacy salon_payroll): salary, commission, bonus, vacation, advance, total, remainder. */
class SalonPayrollRow extends Model
{
    use HasPreciseTimestamps;

    public const NUMBER_FIELDS = ['salary', 'commission', 'bonus', 'vacation', 'advance', 'total', 'remainder'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer'];
    }
}
