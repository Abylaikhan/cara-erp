<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Статья расходов (M14). Two levels: categories (parent_id = null) and articles. Deleting deactivates the row:
 * historical operations keep their classification, codes are never reused.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $parent_id
 * @property bool $is_fixed
 * @property int $sort_order
 * @property bool $active
 * @property int $lock_version
 */
class ExpenseCategory extends Model
{
    protected $fillable = ['code', 'name', 'parent_id', 'is_fixed', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_fixed' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
            'lock_version' => 'integer',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isTopLevel(): bool
    {
        return $this->parent_id === null;
    }
}
