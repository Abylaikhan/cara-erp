<?php

namespace App\Policies;

use App\Models\SalesChannel;
use App\Models\User;
use App\Support\ChannelScope;
use App\Support\Erp;

/**
 * Dealers tab of «Покупатели» (legacy addDealerRow/saveDealer/startEditCell/deleteDealer/importDealersCSV): every role with the
 * page manages dealers, as in legacy. A channel-bound account sees only its own channel and does not add channels.
 */
class SalesChannelPolicy
{
    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'customers');
    }

    public function create(User $user): bool
    {
        return Erp::canAccess($user, 'customers') && ChannelScope::filterFor($user) === null;
    }

    public function update(User $user, SalesChannel $channel): bool
    {
        return Erp::canAccess($user, 'customers')
            && $channel->channel_group === SalesChannel::DEALER
            && ChannelScope::allows($user, $channel->code);
    }

    public function delete(User $user, SalesChannel $channel): bool
    {
        return $this->update($user, $channel);
    }
}
