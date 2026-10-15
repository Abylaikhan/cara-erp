<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Проект CRM (M10, legacy projects): a request for a calculation of non-standard furniture from a salon, a dealer or the
 * office. Statuses request → calc → sent → approved → order, or closed. The executors are logins of staff users.
 *
 * @property int $id
 * @property string|null $num
 * @property string $title
 * @property string $channel
 * @property string $status
 * @property string|null $customer_name
 * @property string|null $description
 * @property Carbon|null $due_date
 * @property list<string> $executors
 * @property string|null $responsible_name
 * @property string|null $order_num
 * @property string|null $total_amount
 * @property bool $active
 * @property string|null $created_by_login
 * @property string|null $created_by_name
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Project extends Model
{
    use HasPreciseTimestamps;

    public const STATUSES = ['request', 'calc', 'sent', 'approved', 'order', 'closed'];

    /** Legacy: a project is open until it became an order or was closed. */
    public const OPEN_STATUSES = ['request', 'calc', 'sent', 'approved'];

    protected $fillable = ['title', 'channel', 'customer_name', 'description', 'due_date', 'status', 'order_num'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'executors' => 'array',
            'total_amount' => 'decimal:2',
            'active' => 'boolean',
            'lock_version' => 'integer',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ProjectCalc, $this> */
    public function calcs(): HasMany
    {
        return $this->hasMany(ProjectCalc::class);
    }

    /** @return HasMany<ProjectFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class);
    }

    /** @return HasMany<ProjectComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(ProjectComment::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
