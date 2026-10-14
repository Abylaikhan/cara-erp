<?php

namespace App\Policies;

use App\Models\CrmTask;
use App\Models\User;
use App\Support\ChannelScope;
use App\Support\Erp;

/** Legacy tasks have no checks of their own: every role with «CRM» except those that see only «Проекты» (legacy prjApplyRole); a task about a lead follows the lead's channel. */
class CrmTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'crm') && ! Erp::crmProjectsOnly($user);
    }

    public function create(User $user): bool
    {
        return Erp::canAccess($user, 'crm') && ! Erp::crmProjectsOnly($user);
    }

    public function update(User $user, CrmTask $task): bool
    {
        return Erp::canAccess($user, 'crm') && ! Erp::crmProjectsOnly($user) && ($task->lead_id === null || ChannelScope::allows($user, $task->lead?->channel));
    }
}
