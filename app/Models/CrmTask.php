<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Задача CRM (M10, legacy tasks): a reminder with a date and time, a manager and a priority, optionally about a lead.
 * A task about a lead is seen by whoever sees the lead; a general task by every CRM user, as in legacy.
 *
 * @property int $id
 * @property string $title
 * @property Carbon|null $due_date
 * @property string|null $due_time
 * @property string|null $manager
 * @property string $priority
 * @property string $status
 * @property int|null $lead_id
 * @property bool $active
 * @property int $lock_version
 * @property-read Lead|null $lead
 */
class CrmTask extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['title', 'due_date', 'due_time', 'manager', 'priority', 'status', 'lead_id'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'lead_id' => 'integer',
            'active' => 'boolean',
            'lock_version' => 'integer',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** The form sends HH:MM, the column returns HH:MM:SS: both are kept as HH:MM:00, so an unchanged time is not a change. */
    protected function dueTime(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : substr($value, 0, 5).':00');
    }

    /** The time as the form shows it (HH:MM); the column keeps seconds. */
    public function timeLabel(): ?string
    {
        return $this->due_time === null ? null : substr($this->due_time, 0, 5);
    }
}
