<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Канал продаж / центр прибыли (legacy SALES_CHANNELS): офис, салоны, дилеры. The code is the legacy id stored in orders,
 * customers, leads and user accounts. Dealers of the «Покупатели» page are the channels of the group «dealer».
 *
 * @property string $code
 * @property string $name
 * @property string $icon
 * @property string $channel_group
 * @property string|null $region
 * @property int $sort_order
 * @property bool $active
 * @property int $lock_version
 */
class SalesChannel extends Model
{
    use HasPreciseTimestamps;

    public const OFFICE = 'office';

    public const DEALER = 'dealer';

    /** Legacy CH_GROUPS. */
    public const GROUPS = ['direct' => 'Прямые продажи', 'salon' => 'Салоны', 'dealer' => 'Дилеры'];

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['code', 'name', 'icon', 'channel_group', 'region', 'sort_order', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
            'lock_version' => 'integer',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('code');
    }

    /** @param Builder<self> $query */
    public function scopeDealers(Builder $query): void
    {
        $query->where('channel_group', self::DEALER)->where('active', true);
    }
}
