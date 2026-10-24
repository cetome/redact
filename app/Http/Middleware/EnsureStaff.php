<?php

namespace App\Http\Middleware;

use App\Auth\SignIn;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * cetome staff only. Answers 404 to everyone else so the console stays invisible.
 *
 * Staff also need a session signed in (or re-confirmed) with a user-verified
 * passkey: an e-mail sign-in alone never opens the console.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isStaff(), 404);

        if ($request->session()->get(EnsureSessionIsValid::METHOD) !== SignIn::PASSKEY) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('auth.staff_passkey_required')], 423);
            }

            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('auth.confirm')->with('error', __('auth.staff_passkey_required'));
        }

        return $next($request);
    }
}
