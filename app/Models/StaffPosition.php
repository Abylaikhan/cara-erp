<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Должность штатного расписания (legacy staffPositions; № 76 — ADR-0051): grade, kind of pay, salary range in whole tenge (legacy
 * reads them with parseInt), number of posts. Occupied posts are the employees with exactly this title, as legacy counts them.
 *
 * @property int $id
 * @property string|null $legacy_key
 * @property int $department_id
 * @property string $title
 * @property string $grade
 * @property string $wage_type
 * @property int $salary_min
 * @property int $salary_max
 * @property int $slots
 * @property int $sort_order
 * @property int $lock_version
 */
class StaffPosition extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['legacy_key', 'department_id', 'title', 'grade', 'wage_type', 'salary_min', 'salary_max', 'slots', 'sort_order'];

    protected function casts(): array
    {
        return ['salary_min' => 'integer', 'salary_max' => 'integer', 'slots' => 'integer', 'sort_order' => 'integer', 'lock_version' => 'integer'];
    }
}
