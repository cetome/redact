<?php

namespace App\Listeners\Auth;

use App\Auth\Remember;
use App\Auth\SignIn;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;

/**
 * Laravel signed someone in from the "Keep me signed in" cookie (the session
 * had expired): stamp the new session (method "remember", ends with the
 * remembered period) and log it, or refuse the cookie (App\Auth\Remember).
 * Explicit sign-ins go through SignIn::complete instead.
 */
class RememberedSignIn
{
    public function __construct(
        private readonly Remember $remember,
        private readonly SignIn $signIn,
    ) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->remember) {
            return;
        }

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $request = request();

        if (! $guard->viaRemember() || $request->attributes->get(self::class)) {
            return;
        }
        $request->attributes->set(self::class, true);

        $user = $event->user;
        $until = $user instanceof User && $user->active ? $this->remember->recalledUntil($request, $user) : null;

        if ($until === null) {
            $guard->logoutCurrentDevice();

            return;
        }

        $this->signIn->recalled($request, $user, $until);
    }
}
