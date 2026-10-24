<?php

namespace App\Auth;

use App\Http\Middleware\EnsureSessionIsValid;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The one place where a session becomes signed in, whatever the method, and
 * where a sensitive action is re-confirmed.
 *
 * Methods: email-link, email-code, passkey (user verified: PIN or biometric),
 * passkey-unverified (presence only), guest (a throwaway sandbox account,
 * App\Guest: no credential at all, the session is the only way back in),
 * remember (a session re-created by "Keep me signed in", App\Auth\Remember).
 */
class SignIn
{
    public const EMAIL_LINK = 'email-link';

    public const EMAIL_CODE = 'email-code';

    public const PASSKEY = 'passkey';

    public const PASSKEY_UNVERIFIED = 'passkey-unverified';

    public const GUEST = 'guest';

    public const REMEMBER = 'remember';

    /** Session key: the e-mail sign-in should be followed by the passkey suggestion. */
    public const SUGGEST_PASSKEY = 'auth.suggest_passkey';

    /** $remember: "Keep me signed in" was ticked (ignored for guests and when the feature is off). */
    public function complete(Request $request, User $user, string $method, bool $remember = false): void
    {
        $guard = Auth::guard('web');
        if ($guard->id() !== $user->getKey()) {
            $guard->login($user, $remember && Remember::allowedFor($user));
        }

        $request->session()->regenerate();
        $this->stamp($request, $user, $method);
        app(Remember::class)->afterSignIn($user, $remember);

        $changes = ['last_login_at' => now(), 'last_login_method' => $method];
        if ($user->email_verified_at === null && in_array($method, [self::EMAIL_LINK, self::EMAIL_CODE], true)) {
            $changes['email_verified_at'] = now();
        }
        $user->forceFill($changes)->save();

        SecurityLog::log('sign-in', $user, ['method' => $method], $user);
    }

    /** Session keys checked on every request by EnsureSessionIsValid. */
    public function stamp(Request $request, User $user, string $method): void
    {
        $session = $request->session();
        $session->put(EnsureSessionIsValid::SIGNED_IN_AT, time());
        $session->put(EnsureSessionIsValid::EPOCH, (int) $user->session_epoch);
        $session->put(EnsureSessionIsValid::METHOD, $method);
        $session->forget(['auth.confirmed_at', EnsureSessionIsValid::REMEMBER_UNTIL]);
    }

    /**
     * Laravel has just signed $user in from the "Keep me signed in" cookie
     * (a new session: once per session, App\Listeners\Auth\RememberedSignIn).
     * The session ends when the remembered period does.
     */
    public function recalled(Request $request, User $user, int $until): void
    {
        $this->stamp($request, $user, self::REMEMBER);
        $request->session()->put(EnsureSessionIsValid::REMEMBER_UNTIL, $until);

        $user->forceFill(['last_login_at' => now(), 'last_login_method' => self::REMEMBER])->save();

        SecurityLog::log('sign-in', $user, ['method' => self::REMEMBER], $user);
    }

    /**
     * The signed-in person proved it again (recent.auth). A verified passkey
     * also lifts the session to the "passkey" method, which the console needs.
     */
    public function confirmed(Request $request, User $user, string $method): void
    {
        $session = $request->session();

        if ($method === self::PASSKEY && $session->get(EnsureSessionIsValid::METHOD) !== self::PASSKEY) {
            $session->regenerate();
            $session->put(EnsureSessionIsValid::METHOD, self::PASSKEY);
        }

        $session->put('auth.confirmed_at', time());

        SecurityLog::log('identity confirmed', $user, ['method' => $method], $user);
    }

    /** Where to go after an e-mail sign-in: the passkey suggestion while the account has none. */
    public function homeAfterEmail(Request $request, User $user): string
    {
        if (! $user->passkeys()->exists()) {
            $request->session()->put(self::SUGGEST_PASSKEY, true);

            return route('auth.passkey.suggest');
        }

        return redirect()->intended(route('dashboard'))->getTargetUrl();
    }

    /**
     * Reads the UV ("user verified") flag from WebAuthn authenticator data,
     * which the passkeys library has already validated for this request.
     */
    public static function userVerified(?string $authenticatorData): bool
    {
        if (! is_string($authenticatorData) || $authenticatorData === '') {
            return false;
        }

        $raw = base64_decode(strtr($authenticatorData, '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 37) {
            return false;
        }

        // rpIdHash (32 bytes), then the flags byte: bit 2 = UV.
        return (ord($raw[32]) & 0x04) === 0x04;
    }
}
