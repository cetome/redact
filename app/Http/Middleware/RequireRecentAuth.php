<?php

namespace App\Http\Middleware;

use App\Auth\SignIn;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive actions (adding or removing a passkey, changing the e-mail
 * address, deleting the account) need a sign-in from the last few minutes.
 * REDact has no passwords, so this replaces Laravel's password.confirm.
 */
class RequireRecentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $window = (int) config('redact.auth.recent_auth') * 60;

        if (time() - self::lastProof($request->session()) <= $window) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('auth.recent_required')], 423);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('auth.confirm');
    }

    /**
     * When the person last proved it's them: sign-in or confirmation. A session
     * re-created by "Keep me signed in" proved nothing, so only a confirmation counts.
     */
    public static function lastProof(Session $session): int
    {
        $signedInAt = $session->get(EnsureSessionIsValid::METHOD) === SignIn::REMEMBER
            ? 0
            : (int) $session->get(EnsureSessionIsValid::SIGNED_IN_AT, 0);

        return max($signedInAt, (int) $session->get('auth.confirmed_at', 0));
    }
}
