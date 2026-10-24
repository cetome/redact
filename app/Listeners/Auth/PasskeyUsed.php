<?php

namespace App\Listeners\Auth;

use App\Auth\SecurityLog;
use App\Models\User;
use Laravel\Passkeys\Events\PasskeyVerified;

/** A passkey assertion was verified (sign-in or confirmation). */
class PasskeyUsed
{
    public function handle(PasskeyVerified $event): void
    {
        $user = $event->user;
        if (! $user instanceof User) {
            return;
        }

        SecurityLog::log('passkey used', $user, [
            'passkey_id' => $event->passkey->getKey(),
            'passkey' => $event->passkey->name,
        ], $user);
    }
}
