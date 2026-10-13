<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Erp;

/**
 * Legacy has no finer rights inside «Статьи расходов»: whoever sees the page manages the directory.
 * Provisional until DEC-13 / DEC-15 (docs/adr/0001-provisional-role-matrix.md).
 */
class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'expense_cats');
    }

    public function manage(User $user): bool
    {
        return Erp::canAccess($user, 'expense_cats');
    }
}
