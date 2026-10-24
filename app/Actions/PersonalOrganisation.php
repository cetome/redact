<?php

namespace App\Actions;

use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;

/**
 * Everyone has an organisation, except someone invited to one (owner, 2026-10-23: "people
 * invited to an organisation cannot get their own"):
 *
 *  - sign-up (CreateAccount) and "Keep my work" without sandbox products (ClaimSandbox) create
 *    no organisation while an organisation invitation is pending for the address; the person
 *    lands on that invitation (EnsureOrganisation) and joins by accepting it;
 *  - when that invitation is declined, revoked or expires before it is accepted, ensure()
 *    creates their own organisation, so nobody is ever left without one (also someone removed
 *    from their only organisation).
 */
class PersonalOrganisation
{
    /** The newest organisation invitation waiting for $email (null when none). */
    public static function pendingInvitation(string $email): ?Invitation
    {
        return Invitation::query()
            ->whereNotNull('organisation_id')->whereNull('product_id')
            ->where('email', mb_strtolower($email))
            ->whereNull('accepted_at')->where('expires_at', '>', now())
            ->whereHas('organisation')
            ->latest('created_at')->first();
    }

    /** Their own organisation when they have none and no invitation is waiting; null otherwise. */
    public static function ensure(User $user): ?Organisation
    {
        if ($user->is_guest || $user->organisations()->exists() || self::pendingInvitation((string) $user->email)) {
            return null;
        }

        return self::create($user);
    }

    public static function create(User $user): Organisation
    {
        $organisation = Organisation::create([
            'name' => CreateAccount::organisationName((string) $user->name, $user->organisation_name),
            'created_by' => $user->getKey(),
        ]);
        $organisation->members()->attach($user->getKey(), ['role' => 'admin']);

        return $organisation;
    }
}
