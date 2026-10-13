<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;
use App\Support\Erp;

/** Legacy openSupplierCard/saveSupplierCard/deleteSupplier (L18547–18780) have no checks of their own: every role with the page. */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'suppliers');
    }

    public function create(User $user): bool
    {
        return Erp::canAccess($user, 'suppliers');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return Erp::canAccess($user, 'suppliers');
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return Erp::canAccess($user, 'suppliers');
    }
}
