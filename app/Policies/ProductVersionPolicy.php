<?php

namespace App\Policies;

use App\Assessment\ReviewRights;
use App\Models\ProductVersion;
use App\Models\User;
use App\Support\ProductAccess;

class ProductVersionPolicy
{
    public function view(User $user, ProductVersion $version): bool
    {
        return ProductAccess::for($user, $version->product)->canView();
    }

    /** Change assessment data: draft versions only, never a reviewer. */
    public function edit(User $user, ProductVersion $version): bool
    {
        return ProductAccess::for($user, $version->product)->canEdit($version);
    }

    public function manage(User $user, ProductVersion $version): bool
    {
        return ProductAccess::for($user, $version->product)->canManage();
    }

    /** Review (view and validate): the product's reviewers, or its owner and organisation admins while it has none (ReviewRights). */
    public function review(User $user, ProductVersion $version): bool
    {
        return ReviewRights::for($user, $version)->canReview();
    }

    /** AI assistance. Never for a sandbox guest (owner decision: the sandbox has no AI). */
    public function useAi(User $user, ProductVersion $version): bool
    {
        return ! $user->is_guest
            && config('redact.ai.enabled')
            && $version->product->organisation->ai_enabled
            && ProductAccess::for($user, $version->product)->canUseAi($version);
    }
}
