<?php

namespace App\Policies;

use App\Models\Material;
use App\Models\User;
use App\Support\Erp;

/** Legacy warehouse functions (renderWarehouse, startEditCell, deleteWarehouseRow, saveAlias, deleteAlias) have no checks of their own. */
class MaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'warehouse');
    }

    public function update(User $user, Material $material): bool
    {
        return Erp::canAccess($user, 'warehouse');
    }

    public function delete(User $user, Material $material): bool
    {
        return Erp::canAccess($user, 'warehouse');
    }

    /** Material aliases belong to the warehouse page as well. */
    public function manageAliases(User $user): bool
    {
        return Erp::canAccess($user, 'warehouse');
    }
}
