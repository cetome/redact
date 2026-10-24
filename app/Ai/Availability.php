<?php

namespace App\Ai;

use App\Billing\Plan;
use App\Models\Organisation;
use App\Models\ProductVersion;
use App\Models\User;
use App\Support\ProductAccess;
use Illuminate\Support\Facades\Gate;

/**
 * Can this person use the AI on this version, and if not, why (shown to them
 * instead of the AI buttons, so an organisation's opt-out is never a mystery).
 */
final class Availability
{
    public static function allows(User $user, ProductVersion $version): bool
    {
        return self::reason($user, $version) === null;
    }

    /** `null` when the AI may be used, else the reason in the person's words. */
    public static function reason(User $user, ProductVersion $version): ?string
    {
        // Pages show many AI buttons: work it out once per request.
        $memo = app()->bound('request') ? request()->attributes : null;
        $key = 'ai.availability.'.$user->getKey().'.'.$version->getKey();
        if ($memo?->has($key)) {
            return $memo->get($key);
        }
        $reason = self::compute($user, $version);
        $memo?->set($key, $reason);

        return $reason;
    }

    /**
     * Before a product exists ("New product"): may this person use the AI for a product of
     * one of these organisations? `null` when at least one of them allows it.
     *
     * @param  iterable<Organisation>  $organisations
     */
    public static function reasonForNew(User $user, iterable $organisations): ?string
    {
        $reason = $user->is_guest ? __('ai.unavailable.guest') : __('ai.unavailable.organisation');
        foreach ($organisations as $organisation) {
            $reason = self::reasonForOrganisation($user, $organisation);
            if ($reason === null) {
                return null;
            }
        }

        return $reason;
    }

    /** `null` when this person may use the AI for a new product of this organisation. */
    public static function reasonForOrganisation(User $user, Organisation $organisation): ?string
    {
        if ($user->is_guest) {
            return __('ai.unavailable.guest');
        }
        if (! config('redact.ai.enabled')) {
            return __('ai.unavailable.disabled');
        }
        if (! $organisation->ai_enabled) {
            return __('ai.unavailable.organisation');
        }
        if (! $user->organisations()->whereKey($organisation->getKey())->exists()) {
            return __('ai.unavailable.role');
        }

        // The plan first: the free plan has no AI ("AI comes with a paid plan"), never "unavailable right now".
        if ($refusal = Plan::for($organisation)->aiRefusal()) {
            return $refusal;
        }

        return app(AiClient::class)->configured($organisation) ? null : __('ai.unavailable.not_configured');
    }

    /**
     * When the AI is off because of the plan (free plan, allowance used up), where an organisation
     * admin can change that (App\Billing\Plan::aiUpgradeUrl); null otherwise.
     */
    public static function upgradeUrl(?User $user, ProductVersion|Organisation $scope): ?string
    {
        if ($user === null || $user->is_guest) {
            return null;
        }
        $organisation = $scope instanceof ProductVersion ? $scope->product->organisation : $scope;

        return Plan::for($organisation)->aiUpgradeUrl($user);
    }

    private static function compute(User $user, ProductVersion $version): ?string
    {
        if ($user->is_guest) {
            return __('ai.unavailable.guest');
        }

        if (Gate::forUser($user)->allows('useAi', $version)) {
            // The plan first: the free plan has no AI ("AI comes with a paid plan").
            if ($refusal = Plan::for($version->product->organisation)->aiRefusal()) {
                return $refusal;
            }
            if (app(AiClient::class)->configured($version)) {
                return null;
            }
        }

        if (! config('redact.ai.enabled')) {
            return __('ai.unavailable.disabled');
        }
        if (! $version->product->organisation->ai_enabled) {
            return __('ai.unavailable.organisation');
        }
        $access = ProductAccess::for($user, $version->product);
        if ($access->isBillingLocked()) {
            return __('plans.locked.banner');
        }
        if (! $access->canUseAi()) {
            return __('ai.unavailable.role');
        }
        if (! $version->isEditable()) {
            return __('ai.unavailable.not_draft');
        }

        return __('ai.unavailable.not_configured');
    }
}
