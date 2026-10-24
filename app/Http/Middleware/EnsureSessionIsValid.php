<?php

namespace App\Http\Middleware;

use App\Auth\Remember;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks on every request what a stolen or stale session must not survive
 * (same model as FAST/ProSecCo):
 *  - absolute lifetime from sign-in, whatever the activity (with "Keep me
 *    signed in": the remembered period from the original sign-in, see
 *    App\Auth\Remember; the recaller cookie re-creates an idle-expired session);
 *  - the account is still active;
 *  - sessions were not revoked (session_epoch unchanged).
 */
class EnsureSessionIsValid
{
    public const SIGNED_IN_AT = 'auth.signed_in_at';

    public const EPOCH = 'auth.session_epoch';

    public const METHOD = 'auth.method';

    /** Unix time a remembered session ends ("Keep me signed in"). */
    public const REMEMBER_UNTIL = 'auth.remember_until';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $session = $request->session();
        $signedInAt = (int) $session->get(self::SIGNED_IN_AT, 0);
        $maxAge = (int) config('redact.auth.session_absolute') * 60;
        $rememberUntil = Remember::enabled() ? (int) $session->get(self::REMEMBER_UNTIL, 0) : 0;

        $expired = $signedInAt === 0 || ($rememberUntil > 0 ? time() > $rememberUntil : (time() - $signedInAt) > $maxAge);
        $revoked = (int) $session->get(self::EPOCH, -1) !== (int) $user->session_epoch;

        if ($expired || $revoked || ! $user->active) {
            // An expired session only ends this device: logout() would also cycle the
            // remember token, and so forget the account's other remembered devices.
            if ($revoked || ! $user->active) {
                Auth::guard('web')->logout();
            } else {
                Auth::guard('web')->logoutCurrentDevice();
            }
            $session->invalidate();
            $session->regenerateToken();

            if ($request->expectsJson()) {
                abort(401);
            }

            return redirect()->guest(route('login'))
                ->with('status', __('auth.session_ended'));
        }

        return $next($request);
    }
}
