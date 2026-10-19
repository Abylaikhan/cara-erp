<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payroll record as it was before a correction (legacy salary_accruals_history): who changed it and when. */
class SalaryAccrualChange extends Model
{
    use HasPreciseTimestamps;

    public const NUMBER_FIELDS = ['amount', 'deducted', 'net'];

    protected function casts(): array
    {
        return ['accrual_date' => 'date:Y-m-d', 'changed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SalaryAccrual, $this> */
    public function accrual(): BelongsTo
    {
        return $this->belongsTo(SalaryAccrual::class, 'salary_accrual_id');
    }
}
