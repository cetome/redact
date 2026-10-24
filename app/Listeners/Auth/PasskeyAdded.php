<?php

namespace App\Listeners\Auth;

use App\Auth\SafeMail;
use App\Auth\SecurityLog;
use App\Mail\Auth\PasskeyChangedMail;
use App\Models\User;
use Laravel\Passkeys\Events\PasskeyRegistered;

/** A passkey was added: security log and a notice to the owner. */
class PasskeyAdded
{
    public function handle(PasskeyRegistered $event): void
    {
        $user = $event->user;
        if (! $user instanceof User) {
            return;
        }

        SecurityLog::log('passkey added', $user, [
            'passkey_id' => $event->passkey->getKey(),
            'passkey' => $event->passkey->name,
            'staff' => $user->isStaff(),
        ], $user);

        SafeMail::send($user->email, new PasskeyChangedMail($user->name, $user->locale ?: config('app.locale'), $event->passkey->name, true));
    }
}
