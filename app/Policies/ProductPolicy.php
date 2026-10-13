<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Support\Erp;

/**
 * Rights of legacy openProductModal/deleteProduct (L1047–1143), enforced by the server:
 * - anyone with the catalog page sees it and adds products;
 * - fields of a product are edited by its author, when it has no author, or by director/accountant;
 * - others may only mark a product for deletion; deleting (deactivating) is for director/accountant.
 */
class ProductPolicy
{
    private const ACCOUNTANTS = ['director', 'accountant'];

    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'catalog');
    }

    public function create(User $user): bool
    {
        return Erp::canAccess($user, 'catalog');
    }

    public function update(User $user, Product $product): bool
    {
        return Erp::canAccess($user, 'catalog');
    }

    public function editFields(User $user, Product $product): bool
    {
        return Erp::canAccess($user, 'catalog') && (
            in_array($user->role, self::ACCOUNTANTS, true)
            || ($product->created_by_id === null && $product->created_by_name === null)
            || $product->created_by_id === $user->id
        );
    }

    public function delete(User $user, Product $product): bool
    {
        return Erp::canAccess($user, 'catalog') && in_array($user->role, self::ACCOUNTANTS, true);
    }
}
