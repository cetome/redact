<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Support\ProductAccess;

/**
 * Thin wrapper over ProductAccess so routes can use `can:` middleware and
 * controllers `Gate::authorize()`. A refusal is a 404, so a product's
 * existence never leaks.
 */
class ProductPolicy
{
    public function view(User $user, Product $product): bool
    {
        return ProductAccess::for($user, $product)->canView();
    }

    public function update(User $user, Product $product): bool
    {
        return ProductAccess::for($user, $product)->canManage();
    }

    public function manage(User $user, Product $product): bool
    {
        return ProductAccess::for($user, $product)->canManage();
    }

    public function delete(User $user, Product $product): bool
    {
        return ProductAccess::for($user, $product)->canDelete();
    }
}
