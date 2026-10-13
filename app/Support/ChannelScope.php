<?php

namespace App\Support;

use App\Models\SalesChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data scope by sales channel — legacy getChannelFilter/channelMatches (L15269–15281), enforced by the server before props,
 * exports and AI context are built (ТЗ AUTH-01, AC-11). A salon or dealer account sees only its channel; the head of the
 * salons (manager role without a channel) sees both salons; office roles see every channel.
 */
class ChannelScope
{
    /** Legacy marker of «both salons» returned by getChannelFilter. */
    public const SALONS = '__salons__';

    /** Legacy getChannelFilter: null — every channel, a channel code, or SALONS. */
    public static function filterFor(User $user): ?string
    {
        $role = Erp::role($user->role);
        $channel = $user->channel ?? ($role['channel'] ?? null);
        if ($channel === null && ($role['is_manager'] ?? false)) {
            return self::SALONS;
        }

        return $channel;
    }

    /**
     * Channels the user may see; null means all of them. Legacy hard-codes the two salons for SALONS; here they come from
     * the directory (group «salon»), which today holds exactly those two.
     *
     * @return list<string>|null
     */
    public static function channelsFor(User $user): ?array
    {
        $filter = self::filterFor($user);

        return match ($filter) {
            null => null,
            self::SALONS => SalesChannel::query()->where('channel_group', 'salon')->ordered()->pluck('code')->all(),
            default => [$filter],
        };
    }

    /** Legacy channelMatches. */
    public static function allows(User $user, ?string $channel): bool
    {
        $channels = self::channelsFor($user);

        return $channels === null || in_array($channel, $channels, true);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, User $user, string $column = 'channel'): Builder
    {
        $channels = self::channelsFor($user);

        return $channels === null ? $query : $query->whereIn($column, $channels);
    }
}
