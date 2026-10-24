<?php

namespace App\Http\Middleware;

use App\Actions\PersonalOrganisation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the pages someone reaches after signing in (dashboard, New product, /team): a person
 * without an organisation is sent to the organisation invitation waiting for them, or, when
 * none is waiting (declined, revoked, expired, removed from their only organisation), gets
 * their own organisation (App\Actions\PersonalOrganisation). Guests keep their sandbox.
 */
class EnsureOrganisation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->is_guest || $user->organisations()->exists()) {
            return $next($request);
        }

        if (PersonalOrganisation::pendingInvitation((string) $user->email) !== null) {
            abort_unless($request->isMethod('GET') && ! $request->expectsJson(), 409);

            return redirect()->route('invitations.pending');
        }

        PersonalOrganisation::create($user);

        return $next($request);
    }
}
