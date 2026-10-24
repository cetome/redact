<?php

namespace App\Auth;

use App\Http\Middleware\EnsureSessionIsValid;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * "Keep me signed in" (ticked at sign-in, off by default): Laravel's
 * remember-me cookie, valid redact.auth.remember_days from that sign-in.
 *
 *  - The cookie is Laravel's recaller (id|token|hash, encrypted and MAC'd by
 *    EncryptCookies, same Secure/SameSite flags as the session cookie, HttpOnly)
 *    with one more segment Laravel ignores: the time of the ticked sign-in. A
 *    session re-created from it (method "remember") ends at that time plus the
 *    lifetime, so 30 days always count from the original sign-in, per device,
 *    and a copied cookie cannot outlive them either.
 *  - "hash" is the HMAC of User::getAuthPassword(), which is the session epoch
 *    (no passwords): revoking sessions voids every remembered device, and
 *    User::revokeSessions() also cycles the remember token, as does signing out.
 *  - Never for sandbox guests. A remembered session is not a recent sign-in
 *    (RequireRecentAuth) nor a passkey session (staff console).
 */
class Remember
{
    public static function days(): int
    {
        return max(0, (int) config('redact.auth.remember_days', 30));
    }

    public static function enabled(): bool
    {
        return self::days() > 0;
    }

    public static function allowedFor(User $user): bool
    {
        return self::enabled() && ! $user->is_guest;
    }

    /**
     * After a sign-in (SignIn::complete): keeps this device signed in when the
     * person ticked the box and may be remembered, otherwise makes sure no
     * recaller cookie leaves (laravel/passkeys honours a "remember" field itself).
     */
    public function afterSignIn(User $user, bool $wanted): void
    {
        Cookie::unqueue(self::guard()->getRecallerName());

        if ($wanted && self::allowedFor($user)) {
            $this->issue($user, time() + self::days() * 86400);
        }
    }

    /**
     * Queues the recaller cookie for this device, valid until $until (a Unix
     * time), and marks the session as remembered until then.
     */
    public function issue(User $user, int $until): void
    {
        $guard = self::guard();

        if (empty($user->getRememberToken())) {
            $user->forceFill(['remember_token' => bin2hex(random_bytes(30))]);
            $user->timestamps = false;
            $user->save();
            $user->timestamps = true;
        }

        $issuedAt = $until - self::days() * 86400;
        $value = $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'
            .$guard->hashPasswordForCookie($user->getAuthPassword()).'|'.$issuedAt;

        Cookie::queue(Cookie::make($guard->getRecallerName(), $value, max(1, (int) ceil(($until - time()) / 60))));

        request()->session()->put(EnsureSessionIsValid::REMEMBER_UNTIL, $until);
    }

    /**
     * Sign out other devices (Sessions::revokeOthers) voids every recaller,
     * this device's too: give a remembered device a new one, same end.
     */
    public function keepThisDevice(Request $request, User $user): void
    {
        $until = (int) $request->session()->get(EnsureSessionIsValid::REMEMBER_UNTIL, 0);

        if ($until > time() && self::allowedFor($user)) {
            $this->issue($user, $until);
        }
    }

    /**
     * When Laravel has just signed $user in from the recaller cookie: the end
     * of the remembered period, or null when the cookie must be refused
     * (no issue time, expired, feature off, guest).
     */
    public function recalledUntil(Request $request, User $user): ?int
    {
        if (! self::allowedFor($user)) {
            return null;
        }

        $value = $request->cookies->get(self::guard()->getRecallerName());
        $segments = is_string($value) ? explode('|', $value) : [];
        $issuedAt = isset($segments[3]) && ctype_digit($segments[3]) ? (int) $segments[3] : 0;
        $until = $issuedAt + self::days() * 86400;

        return $issuedAt > 0 && $issuedAt <= time() && $until > time() ? $until : null;
    }

    private static function guard(): SessionGuard
    {
        /** @var SessionGuard */
        return Auth::guard('web');
    }
}
