<?php

namespace App\Guest;

use App\Auth\Altcha;
use App\Auth\ClientKey;
use App\Auth\SignIn;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Opens a sandbox for a visitor: the per-network limit (redact.guest.per_ip_per_day),
 * the ALTCHA proof of work (checked here, server-side, and accepted once), then a new
 * guest (StartSandbox) signed in on this browser. Used by /try and by the home page.
 * A refusal is a ValidationException on the "altcha" field (back to the form, input kept).
 */
class OpenSandbox
{
    public function __construct(
        private readonly Altcha $altcha,
        private readonly StartSandbox $start,
        private readonly SignIn $signIn,
    ) {}

    public function __invoke(Request $request, mixed $payload): User
    {
        $ipKey = 'guest-ip:'.ClientKey::ip($request);
        if (RateLimiter::tooManyAttempts($ipKey, (int) config('redact.guest.per_ip_per_day', 5))) {
            throw ValidationException::withMessages(['altcha' => __('guest.start.too_many')]);
        }

        if (! $this->altcha->verify($payload)) {
            throw ValidationException::withMessages(['altcha' => __('auth.register.altcha_failed')]);
        }
        RateLimiter::hit($ipKey, 86400);

        $user = ($this->start)(app()->getLocale());
        $this->signIn->complete($request, $user, SignIn::GUEST);

        activity(Sandbox::LOG)->causedBy($user)->log('sandbox started');

        return $user;
    }
}
