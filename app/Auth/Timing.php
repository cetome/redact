<?php

namespace App\Auth;

/**
 * Pads a response to a minimum duration, so the time taken does not tell
 * whether an account exists (a mail was sent, a token was looked up).
 */
final class Timing
{
    /** Minimum duration of a "send me an e-mail" request (milliseconds). */
    public static function sendMs(): int
    {
        return (int) config('redact.auth.pad_send_ms', 1000);
    }

    /** Minimum duration of a code check (milliseconds). */
    public static function codeMs(): int
    {
        return (int) config('redact.auth.pad_code_ms', 300);
    }

    public static function padSince(float $start, int $minimumMs): void
    {
        $remaining = $minimumMs / 1000 - (microtime(true) - $start);
        if ($remaining > 0) {
            usleep((int) ($remaining * 1_000_000) + random_int(0, 50_000));
        }
    }
}
