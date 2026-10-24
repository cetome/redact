<?php

namespace App\Support;

use App\Billing\Plan;
use App\Enums\ProductRole;
use App\Enums\VersionStatus;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;

/**
 * What one person may do on one product. The single source of truth for
 * authorisation, used by the policies, the controllers and the views
 * (same idea as CRAted's resolveProductAccess).
 *
 *               view  edit  manage  review  ai
 * owner          ✔     ✔      ✔       –     ✔
 * contributor    ✔     ✔      –       –     ✔
 * reviewer       ✔     –      –       ✔     –
 * assessor       ✔     –      –       –     –   (records the assessment units only: canRecordUnits)
 * org admin      ✔     ✔      ✔       –     ✔   (no product role needed)
 * cetome staff   ✔     –      –       –     –   (read-only support access, audited)
 *
 * Editing also requires the version to be a draft. A reviewer never edits. An assessor (external,
 * e.g. a test laboratory engineer) never edits either: they record the assessment units, nothing else.
 * A paid product the subscription no longer covers (App\Billing\Plan) is read-only for
 * everyone (view and downloads stay, deleting it stays with the owner): `billingLocked`.
 * The demo product an account loads (products.is_demo, App\Guest\DemoProduct) is read-only for
 * everyone too (no edit, no team, no AI, no import): viewing and downloads stay, its owner and the
 * organisation's administrators remove it: `demo`.
 */
final readonly class ProductAccess
{
    private function __construct(
        public ?ProductRole $role,
        public bool $orgAdmin,
        public bool $staff,
        public bool $external,
        public ?string $billingLocked = null,
        public bool $demo = false,
        // Locked as a free product beyond the plan (not a subscription that ended): the banner differs.
        public bool $overPlan = false,
    ) {}

    public static function for(User $user, Product $product): self
    {
        $role = $product->roleOf($user);
        $membership = $role && $role !== ProductRole::Owner
            ? $product->members()->where('user_id', $user->getKey())->first()
            : null;

        return new self(
            role: $role,
            orgAdmin: $product->organisation->isAdmin($user),
            staff: $user->isStaff(),
            external: (bool) $membership?->external,
            // Grandfathered products (no slot) and the demo never query the plan. A free product is locked
            // only beyond the free allowance (e.g. a sandbox merged into an account that has its free product).
            billingLocked: in_array($product->plan_slot, [Plan::SLOT_PAID, Plan::SLOT_GRANTED, Plan::SLOT_FREE], true)
                && Plan::for($product->organisation)->isLocked($product) ? $product->organisation_id : null,
            demo: (bool) $product->is_demo,
            overPlan: $product->plan_slot === Plan::SLOT_FREE,
        );
    }

    public function canView(): bool
    {
        return $this->role !== null || $this->orgAdmin || $this->staff;
    }

    /** Fill in scoping, risks, inventory, decision trees, E.Info. */
    public function canEdit(?ProductVersion $version = null): bool
    {
        $allowed = in_array($this->role, [ProductRole::Owner, ProductRole::Contributor], true) || $this->orgAdmin;

        return $allowed && $this->billingLocked === null && ! $this->demo && ($version === null || $version->isEditable());
    }

    /** Product settings, team, versions, submission for review. */
    public function canManage(): bool
    {
        return ($this->role === ProductRole::Owner || $this->orgAdmin) && $this->billingLocked === null && ! $this->demo;
    }

    /** The demo product (read-only for everyone, removed by its owner or an organisation admin). */
    public function isDemo(): bool
    {
        return $this->demo;
    }

    /** Read-only whatever the role (subscription ended, or the demo): settings stay open for deleting it. */
    public function isReadOnlyProduct(): bool
    {
        return $this->billingLocked !== null || $this->demo;
    }

    /** Read-only because the subscription that paid for it ended or shrank (banner: reactivate to edit). */
    public function isBillingLocked(): bool
    {
        return $this->billingLocked !== null;
    }

    public function canReview(): bool
    {
        return $this->role === ProductRole::Reviewer;
    }

    /** An assessor of the product (never a reviewer): their unit results are independent. */
    public function isAssessor(): bool
    {
        return $this->role === ProductRole::Assessor && ! $this->orgAdmin;
    }

    /**
     * Record assessment unit results (the "Assessment units" step): the editors, and the product's
     * assessors, on a draft or in review (the step's own window); never the demo or a locked product.
     */
    public function canRecordUnits(ProductVersion $version): bool
    {
        $window = in_array($version->status, [VersionStatus::Draft, VersionStatus::InReview], true);
        $assessor = $this->isAssessor() && $this->billingLocked === null && ! $this->demo;

        return $window && ($this->canEdit() || $assessor);
    }

    public function canUseAi(?ProductVersion $version = null): bool
    {
        return $this->canEdit($version);
    }

    public function canDelete(): bool
    {
        return $this->role === ProductRole::Owner || ($this->demo && $this->orgAdmin);
    }

    /**
     * The product's History page (App\History), read-only: its owner, the organisation's admins
     * and its contributors (owner, 2026-10-23). Reviewers and assessors never see it, even as cetome staff; a
     * reviewer who is also an organisation admin sees it as admin. cetome staff without a role
     * read it as support access. A billing lock never hides it.
     */
    public function canSeeHistory(): bool
    {
        if ($this->orgAdmin) {
            return true;
        }
        if (in_array($this->role, [ProductRole::Reviewer, ProductRole::Assessor], true)) {
            return false;
        }

        return in_array($this->role, [ProductRole::Owner, ProductRole::Contributor], true) || $this->staff;
    }

    /** The history as CSV: the owner and the organisation's admins only. */
    public function canExportHistory(): bool
    {
        return $this->role === ProductRole::Owner || $this->orgAdmin;
    }

    /** Staff looking at a customer product without a role: shown as a banner and logged. */
    public function isSupportAccess(): bool
    {
        return $this->staff && $this->role === null && ! $this->orgAdmin;
    }
}
