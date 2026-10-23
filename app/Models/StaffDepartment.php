<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Подразделение штатного расписания (legacy staffDepts; № 76 — ADR-0051).
 *
 * @property int $id
 * @property string|null $legacy_key
 * @property string $name
 * @property string $icon
 * @property int $sort_order
 * @property int $lock_version
 */
class StaffDepartment extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['legacy_key', 'name', 'icon', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'lock_version' => 'integer'];
    }
}
