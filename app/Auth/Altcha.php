<?php

namespace App\Auth;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as AltchaLib;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Self-hosted ALTCHA proof of work for the sign-up form (no third party).
 * The HMAC key comes from ALTCHA_HMAC_KEY (config redact.altcha.hmac_key),
 * else it is derived from APP_KEY. Each solution is accepted once.
 * A subclass with its own SCOPE (App\Auth\ContactAltcha) signs with its own keys, so a proof
 * solved for one form is refused by another.
 */
class Altcha
{
    /** Empty: the sign-up and sandbox keys (unchanged); otherwise part of the key derivation. */
    protected const SCOPE = '';

    /** PBKDF2 iterations per attempt and the range of attempts the browser must make. */
    public const COST = 2000;

    public const MIN_COUNTER = 300;

    public const MAX_COUNTER = 1200;

    /** Minutes a challenge stays valid. */
    public const TTL = 10;

    /**
     * @return array<string, mixed>
     */
    public function challenge(): array
    {
        return $this->lib()->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2,
            cost: self::COST,
            counter: random_int(self::MIN_COUNTER, self::MAX_COUNTER),
            expiresAt: time() + self::TTL * 60,
        ))->toArray();
    }

    public function verify(mixed $payload): bool
    {
        if (! is_string($payload) || $payload === '' || strlen($payload) > 4096) {
            return false;
        }

        try {
            $decoded = Payload::fromBase64($payload);
            $result = $this->lib()->verifySolution(new VerifySolutionOptions($decoded, new Pbkdf2));
        } catch (Throwable) {
            return false;
        }

        if (! $result->verified) {
            return false;
        }

        $spent = 'altcha-spent:'.hash('sha256', $decoded->challenge->parameters->nonce.'|'.$decoded->challenge->signature);

        return Cache::add($spent, true, now()->addMinutes(self::TTL + 5));
    }

    public function lib(): AltchaLib
    {
        return new AltchaLib(
            hmacSignatureSecret: $this->key('challenge'),
            hmacKeySignatureSecret: $this->key('key'),
        );
    }

    private function key(string $purpose): string
    {
        $configured = (string) config('redact.altcha.hmac_key', '');
        $base = $configured !== '' ? $configured : (string) config('app.key');

        $scope = static::SCOPE !== '' ? '-'.static::SCOPE : '';

        return hash_hmac('sha256', 'redact-altcha-'.$purpose.$scope, $base);
    }
}
