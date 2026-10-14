<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Лид CRM (M10, legacy leads). The channel tells whose lead it is; channel-bound roles see only theirs (ChannelScope).
 *
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string $status
 * @property string|null $interest
 * @property string $budget
 * @property string|null $source
 * @property string|null $comment
 * @property string $channel
 * @property string|null $manager
 * @property int|null $segment
 * @property list<string> $tags
 * @property Carbon|null $next_date
 * @property string|null $next_action
 * @property bool $active
 * @property int $lock_version
 */
class Lead extends Model
{
    use HasPreciseTimestamps;

    public const CLOSED = ['won', 'lost'];

    protected $fillable = ['name', 'phone', 'status', 'interest', 'budget', 'source', 'comment', 'manager', 'segment', 'tags', 'next_date', 'next_action'];

    protected function casts(): array
    {
        return [
            'budget' => 'decimal:2',
            'segment' => 'integer',
            'tags' => 'array',
            'next_date' => 'date:Y-m-d',
            'active' => 'boolean',
            'lock_version' => 'integer',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
