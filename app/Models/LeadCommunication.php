<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Запись истории коммуникации с лидом (M10, legacy commHistory of the lead card): a call, letter, meeting, WhatsApp, e-mail
 * or note with its date. Legacy kept the history in page memory only.
 *
 * @property int $id
 * @property int $lead_id
 * @property string $type
 * @property Carbon|null $happened_on
 * @property string $text
 * @property string|null $manager
 * @property bool $active
 * @property-read Lead $lead
 */
class LeadCommunication extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['type', 'happened_on', 'text', 'manager'];

    protected function casts(): array
    {
        return [
            'happened_on' => 'date:Y-m-d',
            'lead_id' => 'integer',
            'active' => 'boolean',
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
}
