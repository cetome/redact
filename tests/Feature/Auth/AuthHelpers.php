<?php

namespace Tests\Feature\Auth;

use App\Auth\Altcha;
use App\Auth\EmailChallenge;
use App\Auth\SignIn;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Models\User;
use Illuminate\Testing\TestResponse;

trait AuthHelpers
{
    protected function setUpAuth(): void
    {
        // No artificial delays in tests.
        config(['redact.auth.pad_send_ms' => 0, 'redact.auth.pad_code_ms' => 0]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function makeUser(array $attributes = []): User
    {
        static $n = 0;
        $n++;

        return User::forceCreate(array_merge([
            'name' => 'Ada Example '.$n,
            'email' => "ada{$n}@example.test",
            'email_verified_at' => now(),
            'locale' => 'en',
            'timezone' => 'Europe/Paris',
        ], $attributes))->refresh();
    }

    /** Signs in as $user with the session keys a real sign-in sets. */
    protected function signedIn(User $user, string $method = SignIn::EMAIL_LINK, ?int $signedInAt = null): static
    {
        return $this->actingAs($user)->withSession([
            EnsureSessionIsValid::SIGNED_IN_AT => $signedInAt ?? time(),
            EnsureSessionIsValid::EPOCH => (int) $user->session_epoch,
            EnsureSessionIsValid::METHOD => $method,
        ]);
    }

    protected function bindCookie(TestResponse $response): string
    {
        $cookie = $response->getCookie(EmailChallenge::BIND_COOKIE);
        $this->assertNotNull($cookie, 'The bind cookie was not set.');

        return (string) $cookie->getValue();
    }

    /** ALTCHA that accepts the payload "ok" only (the real check has its own test). */
    protected function fakeAltcha(): void
    {
        $this->app->instance(Altcha::class, new class extends Altcha
        {
            public function verify(mixed $payload): bool
            {
                return $payload === 'ok';
            }
        });
    }
}
