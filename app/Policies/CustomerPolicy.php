<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Support\ChannelScope;
use App\Support\Erp;

/**
 * Rights of legacy openPersonModal/savePerson (L18925–18984): every role with the «Покупатели» page adds and edits
 * customers. A customer of another channel is not visible to a channel-bound account, so it cannot be edited either.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'customers');
    }

    public function create(User $user): bool
    {
        return Erp::canAccess($user, 'customers');
    }

    public function update(User $user, Customer $customer): bool
    {
        return Erp::canAccess($user, 'customers') && ChannelScope::allows($user, $customer->channel);
    }
}
