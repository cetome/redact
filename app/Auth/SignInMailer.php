<?php

namespace App\Auth;

use App\Mail\Auth\SignInMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sends the sign-in e-mail (link + code) for an address, if it may: the
 * account exists, is active and the address is under its limits
 * (5 per 15 minutes, 20 per day). Whatever happens, the browser ends up in
 * the same state, so the caller can answer identically.
 */
class SignInMailer
{
    public const PER_15_MINUTES = 5;

    public const PER_DAY = 20;

    public function __construct(private readonly EmailChallenge $challenges) {}

    /** $remember: "Keep me signed in" was ticked, kept with the challenge in this browser's session. */
    public function send(Request $request, string $email, ?User $user, bool $newAccount = false, bool $remember = false): void
    {
        $key = ClientKey::email($email);
        $short = 'signin-mail:15m:'.$key;
        $daily = 'signin-mail:day:'.$key;

        $allowed = ! RateLimiter::tooManyAttempts($short, self::PER_15_MINUTES)
            && ! RateLimiter::tooManyAttempts($daily, self::PER_DAY);
        RateLimiter::hit($short, 900);
        RateLimiter::hit($daily, 86400);

        $recipient = ($allowed && $user !== null && $user->active) ? $user : null;

        $challenge = $this->challenges->start($request, $newAccount ? 'signup' : 'login', $recipient, $email, remember: $remember);

        if ($challenge !== null && $recipient !== null) {
            SafeMail::send($recipient->email, new SignInMail(
                $recipient->name,
                $recipient->locale ?: config('app.locale'),
                route('login.link', ['token' => $challenge['link']]),
                $challenge['code'],
                $newAccount,
            ));
        }
    }
}
