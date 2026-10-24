<?php

namespace App\Auth;

use App\Guest\Sandbox;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends an account e-mail now (inside the padded request, so timing tells
 * nothing). A mail failure is reported, never shown: an error page would
 * reveal that the address has an account.
 */
final class SafeMail
{
    public static function send(string $to, Mailable $mail): void
    {
        // Sandbox guests (*.invalid) never get mail; App\Listeners\Mail\BlockReservedRecipients enforces it for every mail.
        if (Sandbox::isReservedAddress($to)) {
            return;
        }

        try {
            Mail::to($to)->send($mail);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
