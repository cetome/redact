<?php

namespace App\Http\Controllers\Auth;

use App\Auth\EmailChallenge;
use App\Auth\SignIn;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The link from the sign-in e-mail. Opening it (GET) changes nothing, so mail
 * scanners that follow links cannot use it up: the page only shows a
 * "Continue" button, which POSTs (CSRF) and signs in. The link works in any
 * browser; it is single use and valid 15 minutes.
 */
class MagicLinkController extends Controller
{
    public function show(string $token): Response
    {
        return response()
            ->view('auth.link', ['token' => $token])
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store');
    }

    public function consume(Request $request, string $token, EmailChallenge $challenges, SignIn $signIn): RedirectResponse
    {
        $login = $challenges->consumeLink($token);

        if ($login === null || ! $login->user->active) {
            return redirect()->route('login')->with('error', __('auth.link.invalid'));
        }

        // "Keep me signed in" applies when the link is opened in the browser that ticked it.
        $signIn->complete($request, $login->user, SignIn::EMAIL_LINK, $challenges->remembers($request, 'login', $login->id));

        return redirect($signIn->homeAfterEmail($request, $login->user));
    }
}
