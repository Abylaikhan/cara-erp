<?php

namespace App\Policies;

use App\Models\CrmTask;
use App\Models\User;
use App\Support\ChannelScope;
use App\Support\Erp;

/** Legacy tasks have no checks of their own: every role with «CRM»; a task about a lead follows the lead's channel. */
class CrmTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'crm');
    }

    public function create(User $user): bool
    {
        return Erp::canAccess($user, 'crm');
    }

    public function update(User $user, CrmTask $task): bool
    {
        return Erp::canAccess($user, 'crm') && ($task->lead_id === null || ChannelScope::allows($user, $task->lead?->channel));
    }
}
