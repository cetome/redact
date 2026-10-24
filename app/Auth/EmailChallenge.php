<?php

namespace App\Auth;

use App\Models\LoginToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;

/**
 * E-mail challenges (FAST/ProSecCo model): one mail carries a magic link and
 * a 6-digit code.
 *
 *  - The link is 32 random bytes; only its SHA-256 is stored. It works in any
 *    browser (people often read mail on another device): it is long, single
 *    use and short-lived, and opening it (GET) does nothing until the person
 *    presses "Continue" (POST + CSRF), so mail scanners cannot use it up.
 *  - The code is short, so it is fenced in: it is stored as HMAC-SHA256 with
 *    a per-request secret kept in the requesting session, and the requesting
 *    browser also holds a bind cookie whose hash is stored. A code typed in
 *    any other browser fails. 5 wrong codes burn the token, 10 per account
 *    and day lock code sign-in for the day.
 *  - Unknown addresses get the same session state, cookie and response.
 *
 * Purposes: login, signup (link + code), confirm, email_change and
 * guest_claim (code only: a sandbox guest proving the address of the account
 * that keeps their work, App\Http\Controllers\GuestController).
 */
class EmailChallenge
{
    public const BIND_COOKIE = 'redact_bind';

    private const SESSION = 'auth.challenge.';

    /** Session slot => token purposes a code typed there may consume. */
    private const SLOTS = [
        'login' => ['login', 'signup'],
        'confirm' => ['confirm'],
        'email_change' => ['email_change'],
        'guest_claim' => ['guest_claim'],
    ];

    /** Wrong codes per account and day before code sign-in stops until tomorrow. */
    public const DAILY_FAILURES = 10;

    /**
     * Starts a challenge for $user (null: unknown address, nothing is stored).
     *
     * @return array{token: LoginToken, link: string, code: string}|null
     */
    public function start(Request $request, string $purpose, ?User $user, string $email, ?string $newEmail = null, bool $remember = false): ?array
    {
        $secret = random_bytes(32);
        $bind = $this->bindCookie($request);

        $state = ['secret' => base64_encode($secret), 'token' => null, 'email' => $email, 'failures' => 0, 'remember' => $remember];
        $result = null;

        if ($user !== null) {
            $link = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            $token = $user->loginTokens()->create([
                'purpose' => $purpose,
                'token_hash' => hash('sha256', $link),
                'code_hash' => hash_hmac('sha256', $code, $secret),
                'bind_hash' => hash('sha256', $bind),
                'new_email' => $newEmail,
                'expires_at' => now()->addMinutes((int) config('redact.auth.token_ttl', 15)),
            ]);

            $state['token'] = $token->id;
            $result = ['token' => $token, 'link' => $link, 'code' => $code];
        }

        $request->session()->put(self::SESSION.self::slot($purpose), $state);

        return $result;
    }

    /** The address the pending challenge of this browser (slot: login, confirm, email_change) was sent to. */
    public function pendingEmail(Request $request, string $slot): ?string
    {
        return $request->session()->get(self::SESSION.$slot.'.email');
    }

    /**
     * "Keep me signed in" was ticked when this browser asked for its pending
     * challenge ($tokenId: and that challenge is this token, for a link).
     */
    public function remembers(Request $request, string $slot, ?int $tokenId = null): bool
    {
        $state = $request->session()->get(self::SESSION.$slot);

        return is_array($state) && ($state['remember'] ?? false) === true
            && ($tokenId === null || (int) ($state['token'] ?? 0) === $tokenId);
    }

    public function hasPending(Request $request, string $slot): bool
    {
        return $request->session()->has(self::SESSION.$slot);
    }

    private static function slot(string $purpose): string
    {
        return $purpose === 'signup' ? 'login' : $purpose;
    }

    /**
     * Checks a code typed in this browser. Returns the consumed token, or
     * null whatever the reason (wrong, expired, other browser, unknown
     * address, locked), so the answer never tells more.
     */
    public function verifyCode(Request $request, string $slot, string $code): ?LoginToken
    {
        $state = $request->session()->get(self::SESSION.$slot);
        if (! is_array($state)) {
            return null;
        }

        $token = $state['token'] ? LoginToken::with('user')->find($state['token']) : null;

        if ($token === null) {
            // Unknown address: count the tries like a real one, then nothing.
            $request->session()->put(self::SESSION.$slot.'.failures', (int) $state['failures'] + 1);

            return null;
        }

        if (! in_array($token->purpose, self::SLOTS[$slot] ?? [], true) || ! $token->isUsable()) {
            return null;
        }

        $bind = (string) $request->cookie(self::BIND_COOKIE);
        if ($bind === '' || ! hash_equals($token->bind_hash, hash('sha256', $bind))) {
            return null;
        }

        $user = $token->user;
        $dailyKey = 'auth-code-failures:'.$user->id;

        if (RateLimiter::tooManyAttempts($dailyKey, self::DAILY_FAILURES)) {
            $this->burn($token);

            return null;
        }

        $expected = hash_hmac('sha256', $code, (string) base64_decode((string) $state['secret'], true));

        if (! preg_match('/^\d{6}$/', $code) || ! hash_equals($token->code_hash, $expected)) {
            $token->increment('attempts');
            RateLimiter::hit($dailyKey, 86400);

            if ($token->attempts >= (int) config('redact.auth.code_attempts', 5)) {
                $this->burn($token);
            }

            SecurityLog::log('sign-in code failed', $user, ['purpose' => $token->purpose, 'attempts' => $token->attempts], $user);

            return null;
        }

        if (! $this->claim($token)) {
            return null;
        }

        $request->session()->forget(self::SESSION.$slot);

        return $token;
    }

    /**
     * Consumes a magic link (sign-in or sign-up only). Null when unknown,
     * expired or already used.
     */
    public function consumeLink(string $link): ?LoginToken
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/', $link)) {
            return null;
        }

        $token = LoginToken::with('user')->where('token_hash', hash('sha256', $link))->first();

        if ($token === null || ! in_array($token->purpose, ['login', 'signup'], true) || ! $token->isUsable()) {
            return null;
        }

        return $this->claim($token) ? $token : null;
    }

    /** Single use, even with two requests racing. */
    private function claim(LoginToken $token): bool
    {
        $claimed = LoginToken::whereKey($token->id)->whereNull('used_at')->update(['used_at' => now()]) === 1;
        if ($claimed) {
            $token->used_at = now();
        }

        return $claimed;
    }

    private function burn(LoginToken $token): void
    {
        LoginToken::whereKey($token->id)->whereNull('used_at')->update(['used_at' => now()]);
    }

    /** A fresh random value for the bind cookie (HttpOnly, Lax, Secure on https, 1 hour). */
    private function bindCookie(Request $request): string
    {
        $value = bin2hex(random_bytes(32));

        Cookie::queue(Cookie::make(
            self::BIND_COOKIE,
            $value,
            60,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));

        return $value;
    }
}
