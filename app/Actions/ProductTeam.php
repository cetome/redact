<?php

namespace App\Actions;

use App\Billing\Plan;
use App\Enums\ProductRole;
use App\Models\Invitation;
use App\Models\Product;
use App\Models\ProductMember;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Putting someone on a product's team, whatever page does it: the product Team page
 * (TeamController) and the organisation Team page (OrganisationTeamController). One place for
 * the rules: the plan's team limit (App\Billing\Plan: the free product holds its owner and N
 * people; someone already counted is never refused), who is already on the team, and who is
 * internal (a member of the product's organisation) or external.
 */
class ProductTeam
{
    /** The roles a team member can be given (the owner is products.owner_id). */
    public const ROLES = [ProductRole::Contributor, ProductRole::Reviewer, ProductRole::Assessor];

    /** @return list<string> */
    public static function roleValues(): array
    {
        return array_map(fn (ProductRole $r) => $r->value, self::ROLES);
    }

    /** Why $email cannot take a seat on $product now (the plan), null when it can. */
    public function refusal(Product $product, string $email): ?string
    {
        $plan = Plan::fresh($product->organisation);

        return $plan->teamWouldExceed($product, Str::lower($email)) ? $plan->teamRefusal($product) : null;
    }

    /** Owner or member of $product already (an inviting no-op). */
    public function isOnTeam(Product $product, User $user): bool
    {
        return $user->id === $product->owner_id || $product->members()->where('user_id', $user->id)->exists();
    }

    public function isExternal(Product $product, ?User $user): bool
    {
        return ! ($user && $product->organisation->hasMember($user));
    }

    /**
     * A pending invitation to $product. The caller checked refusal() and sends the e-mail (with
     * the token returned), unless $parent carries it (organisation invitation).
     *
     * @return array{0: Invitation, 1: ?string} the invitation and its token (null for a child)
     */
    public function invite(Product $product, string $email, ProductRole $role, User $inviter, ?Invitation $parent = null): array
    {
        $email = Str::lower($email);
        $token = $parent ? null : Str::random(48);

        $invitation = $product->invitations()->create([
            'email' => $email,
            'role' => $role,
            'external' => $this->isExternal($product, User::query()->where('email', $email)->first()),
            'token_hash' => $token ? hash('sha256', $token) : null,
            'invited_by' => $inviter->id,
            'expires_at' => $parent ? $parent->expires_at : now()->addDays(14),
            'parent_id' => $parent?->id,
        ]);

        // The product's history names who was invited and as what (never the token).
        activity('team')->causedBy($inviter)->performedOn($product)->event('invited')
            ->withProperties(['invitation_id' => $invitation->id, 'email' => $email, 'role' => $role->value, 'external' => $invitation->external])
            ->log('invited');

        return [$invitation, $token];
    }

    /**
     * Put $user on $product with $role now (an organisation admin adding a member, or an
     * invitation accepted). The owner keeps owning; the caller checked refusal() for a new seat.
     */
    public function add(Product $product, User $user, ProductRole $role, ?int $grantedBy): ?ProductMember
    {
        if ($user->id === $product->owner_id) {
            return null;
        }

        $member = $product->members()->firstOrNew(['user_id' => $user->id]);
        $member->fill([
            'role' => $role,
            'external' => $this->isExternal($product, $user),
            'granted_by' => $grantedBy,
        ]);
        if (! $member->exists) {
            $member->expires_at = null;
        }
        $member->save();

        return $member;
    }
}
