<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Support\ChannelScope;
use App\Support\Erp;

/** Orders of «Продажи»: the page decides who reads them, the channel which ones (legacy getChannelFilter/channelMatches). */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'sales');
    }

    public function update(User $user, Order $order): bool
    {
        return Erp::canAccess($user, 'sales') && ChannelScope::allows($user, $order->channel);
    }
}
