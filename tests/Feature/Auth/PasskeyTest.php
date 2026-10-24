<?php

namespace Tests\Feature\Auth;

use App\Auth\Responses\PasskeyConfirmationResponse;
use App\Auth\Responses\PasskeyLoginResponse;
use App\Auth\SignIn;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Mail\Auth\PasskeyChangedMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as LoginResponseContract;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

class PasskeyTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        Mail::fake();
    }

    private function passkey(User $user, string $name = 'Laptop'): Passkey
    {
        return $user->passkeys()->create(['name' => $name, 'credential_id' => bin2hex(random_bytes(8)), 'credential' => []]);
    }

    /** WebAuthn authenticator data: rpIdHash, flags, counter (base64url). */
    private function authenticatorData(bool $userVerified): string
    {
        $flags = 0x01 | ($userVerified ? 0x04 : 0);
        $raw = random_bytes(32).chr($flags).pack('N', 1);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function passkeyRequest(User $user, bool $userVerified): Request
    {
        $request = Request::create('/passkeys/login', 'POST', [
            'credential' => ['response' => ['authenticatorData' => $this->authenticatorData($userVerified)]],
        ], server: ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession($this->app['session.store']);
        Auth::login($user);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return $request;
    }

    public function test_the_package_uses_redacts_responses_and_settings(): void
    {
        $this->assertInstanceOf(PasskeyLoginResponse::class, app(LoginResponseContract::class));
        $this->assertSame(parse_url(config('app.url'), PHP_URL_HOST), config('passkeys.relying_party_id'));
        $this->assertSame(['recent.auth'], config('passkeys.management_middleware'));
        $this->assertSame('/dashboard', config('passkeys.redirect'));
    }

    public function test_a_passkey_sign_in_sets_the_session_keys(): void
    {
        $user = $this->makeUser();
        $request = $this->passkeyRequest($user, true);

        $response = app(PasskeyLoginResponse::class)->toResponse($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(SignIn::PASSKEY, session(EnsureSessionIsValid::METHOD));
        $this->assertSame(0, session(EnsureSessionIsValid::EPOCH));
        $this->assertEqualsWithDelta(time(), session(EnsureSessionIsValid::SIGNED_IN_AT), 5);
        $this->assertSame(SignIn::PASSKEY, $user->fresh()->last_login_method);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'sign-in']);
    }

    public function test_a_passkey_without_user_verification_is_marked(): void
    {
        $user = $this->makeUser();
        $request = $this->passkeyRequest($user, false);

        app(PasskeyLoginResponse::class)->toResponse($request);

        $this->assertSame(SignIn::PASSKEY_UNVERIFIED, session(EnsureSessionIsValid::METHOD));
    }

    public function test_a_passkey_confirmation_opens_the_recent_window_and_lifts_the_method(): void
    {
        $user = $this->makeUser();
        $request = $this->passkeyRequest($user, true);
        session([EnsureSessionIsValid::METHOD => SignIn::EMAIL_LINK]);

        app(PasskeyConfirmationResponse::class)->toResponse($request);

        $this->assertSame(SignIn::PASSKEY, session(EnsureSessionIsValid::METHOD));
        $this->assertEqualsWithDelta(time(), session('auth.confirmed_at'), 5);
    }

    public function test_inactive_accounts_cannot_sign_in_with_a_passkey(): void
    {
        $active = $this->makeUser();
        $inactive = $this->makeUser(['active' => false]);

        $this->assertTrue(Passkeys::allowsLogin(request(), $this->passkey($active->fresh())));
        $this->assertFalse(Passkeys::allowsLogin(request(), $this->passkey($inactive->fresh())));
    }

    public function test_adding_a_passkey_mails_the_owner_and_logs_it(): void
    {
        $user = $this->makeUser();
        PasskeyRegistered::dispatch($user, $this->passkey($user, 'Phone'));

        Mail::assertSent(PasskeyChangedMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->added && $mail->passkeyName === 'Phone');
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'passkey added']);
    }

    public function test_the_account_page_lists_passkeys(): void
    {
        $user = $this->makeUser();
        $this->passkey($user, 'Work laptop');

        $this->signedIn($user)->get('/account/security')->assertOk()->assertSee('Work laptop')->assertSee('x-data="passkeyRegister"', false);
    }

    public function test_renaming_a_passkey(): void
    {
        $user = $this->makeUser();
        $passkey = $this->passkey($user);

        $this->signedIn($user)->patch("/account/passkeys/{$passkey->id}", ['name' => 'Desk key'])->assertRedirect();
        $this->assertSame('Desk key', $passkey->fresh()->name);
    }

    public function test_someone_elses_passkey_is_not_found(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $passkey = $this->passkey($owner);

        $this->signedIn($other)->patch("/account/passkeys/{$passkey->id}", ['name' => 'Mine'])->assertNotFound();
        $this->signedIn($other)->delete("/account/passkeys/{$passkey->id}")->assertNotFound();
        $this->assertNotNull($passkey->fresh());
    }

    public function test_removing_a_passkey_needs_a_recent_sign_in(): void
    {
        $user = $this->makeUser();
        $passkey = $this->passkey($user);

        $this->signedIn($user, SignIn::EMAIL_LINK, time() - 3600)
            ->delete("/account/passkeys/{$passkey->id}")
            ->assertRedirect(route('auth.confirm'));

        $this->assertNotNull($passkey->fresh());
    }

    public function test_removing_a_passkey_revokes_other_sessions_and_mails_the_owner(): void
    {
        $user = $this->makeUser();
        $passkey = $this->passkey($user, 'Old phone');

        $this->signedIn($user)->delete("/account/passkeys/{$passkey->id}")->assertRedirect(route('account.tab', 'security'));

        $this->assertNull($passkey->fresh());
        $this->assertSame(1, $user->fresh()->session_epoch);
        $this->assertSame(1, session(EnsureSessionIsValid::EPOCH), 'The current session is kept.');
        Mail::assertSent(PasskeyChangedMail::class, fn ($mail) => ! $mail->added && $mail->passkeyName === 'Old phone');
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'passkey removed']);

        $this->get('/account')->assertOk();
    }

    public function test_the_suggestion_page_follows_an_e_mail_sign_in_without_passkeys(): void
    {
        $user = $this->makeUser();

        $this->signedIn($user)->get(route('auth.passkey.suggest'))->assertOk()->assertSee(__('auth.suggest.skip'));
        $this->get(route('auth.passkey.skip'))->assertRedirect(route('dashboard'));

        $this->passkey($user);
        $this->get(route('auth.passkey.suggest'))->assertRedirect(route('dashboard'));
    }
}
