<?php

namespace App\Policies;

use App\Models\IncomingInvoice;
use App\Models\User;
use App\Support\Erp;

/** Legacy ESF functions (initEsfPage, setEsfType, handleEsfXmlFiles, confirmEsfXmlImport) have no checks of their own. */
class IncomingInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'esf');
    }

    public function create(User $user): bool
    {
        return Erp::canAccess($user, 'esf');
    }

    public function update(User $user, IncomingInvoice $invoice): bool
    {
        return Erp::canAccess($user, 'esf');
    }
}
