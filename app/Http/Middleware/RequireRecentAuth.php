<?php

namespace App\Http\Middleware;

use App\Auth\SignIn;
use App\Support\BackUrl;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive actions (adding or removing a passkey, changing the e-mail
 * address, deleting the account) need a sign-in from the last few minutes.
 * REDact has no passwords, so this replaces Laravel's password.confirm.
 * Forms marked data-recent-auth ask for the passkey on the page first (modules/recent-auth.js,
 * the meta redact-recent): this redirect is the fallback (no passkey, e-mail code).
 */
class RequireRecentAuth
{
    /** Session flag: a form was stopped for the confirmation, it has to be sent again. */
    public const AGAIN = 'auth.confirm_again';

    public function handle(Request $request, Closure $next): Response
    {
        $window = (int) config('redact.auth.recent_auth') * 60;

        if (time() - self::lastProof($request->session()) <= $window) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('auth.recent_required')], 423);
        }

        // A page (GET) is opened again after the confirmation. A form (DELETE, POST…) cannot be replayed
        // by a redirect, which would GET its address (405): back to the page it was sent from, where
        // the person clicks again (SignIn::confirmed says so).
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        } else {
            // The page the form was sent from, on this site only (App\Support\BackUrl): never the session's
            // last URL, which can be a picture.
            $request->session()->put('url.intended', BackUrl::from($request, route('dashboard')));
            $request->session()->put(self::AGAIN, true);
        }

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
