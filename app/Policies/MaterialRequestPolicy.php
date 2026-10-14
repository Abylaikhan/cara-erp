<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Erp;

/** Legacy material requests have no checks of their own: every role with «Закупки». */
class MaterialRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'purchases');
    }

    public function create(User $user): bool
    {
        return Erp::canAccess($user, 'purchases');
    }

    public function update(User $user): bool
    {
        return Erp::canAccess($user, 'purchases');
    }
}
