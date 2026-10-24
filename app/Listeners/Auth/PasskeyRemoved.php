<?php

namespace App\Listeners\Auth;

use App\Auth\SafeMail;
use App\Auth\SecurityLog;
use App\Auth\Sessions;
use App\Mail\Auth\PasskeyChangedMail;
use App\Models\User;
use Laravel\Passkeys\Events\PasskeyDeleted;

/**
 * A passkey was removed: security log, notice to the owner, and every other
 * session of the account is signed out (a lost device may still be signed in).
 */
class PasskeyRemoved
{
    public function __construct(private readonly Sessions $sessions) {}

    public function handle(PasskeyDeleted $event): void
    {
        $user = $event->user;
        if (! $user instanceof User) {
            return;
        }

        SecurityLog::log('passkey removed', $user, [
            'passkey_id' => $event->passkey->getKey(),
            'passkey' => $event->passkey->name,
        ], $user);

        SafeMail::send($user->email, new PasskeyChangedMail($user->name, $user->locale ?: config('app.locale'), $event->passkey->name, false));

        $this->sessions->revokeOthers(request(), $user, 'passkey removed');
    }
}
