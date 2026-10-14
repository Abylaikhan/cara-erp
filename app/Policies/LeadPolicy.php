<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Support\ChannelScope;
use App\Support\Erp;

/** Legacy saveLead/quickChangeLeadStatus/convertLeadToClient/leadWon/leadLost have no checks of their own: every role with «CRM». */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'crm');
    }

    public function create(User $user): bool
    {
        return Erp::canAccess($user, 'crm');
    }

    /** A lead of another channel is not visible to a channel-bound account, so it cannot be changed either. */
    public function update(User $user, Lead $lead): bool
    {
        return Erp::canAccess($user, 'crm') && ChannelScope::allows($user, $lead->channel);
    }
}
