<?php

namespace Tests\Feature\Auth;

use App\Auth\EmailChallenge;
use App\Auth\SignIn;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Mail\Auth\SignInMail;
use App\Models\LoginToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailSignInTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        Mail::fake();
    }

    /** @return array{0: SignInMail, 1: string} the mail and the bind cookie */
    private function requestMail(User $user): array
    {
        $response = $this->post('/login', ['email' => $user->email])->assertRedirect(route('login.code'));
        $bind = $this->bindCookie($response);

        $sent = null;
        Mail::assertSent(SignInMail::class, function (SignInMail $mail) use (&$sent) {
            $sent = $mail;

            return true;
        });

        return [$sent, $bind];
    }

    private function linkToken(SignInMail $mail): string
    {
        return basename(parse_url($mail->url, PHP_URL_PATH));
    }

    public function test_login_page_offers_passkeys_and_e_mail(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('autocomplete="username webauthn"', false)
            ->assertSee('x-data="passkeyLogin"', false);
    }

    public function test_answers_are_the_same_whether_or_not_the_account_exists(): void
    {
        $user = $this->makeUser();

        $known = $this->post('/login', ['email' => $user->email]);
        $unknown = $this->post('/login', ['email' => 'nobody@example.test']);

        foreach ([$known, $unknown] as $response) {
            $response->assertRedirect(route('login.code'))->assertSessionHas('status', __('auth.login.sent'));
            $this->assertNotNull($response->getCookie(EmailChallenge::BIND_COOKIE));
        }

        Mail::assertSentCount(1);
        Mail::assertSent(SignInMail::class, fn (SignInMail $mail) => $mail->hasTo($user->email));
        $this->assertSame(1, LoginToken::count());
    }

    public function test_only_hashes_are_stored(): void
    {
        $user = $this->makeUser();
        [$mail] = $this->requestMail($user);

        $token = LoginToken::firstOrFail();
        $this->assertSame(hash('sha256', $this->linkToken($mail)), $token->token_hash);
        $this->assertNotSame($mail->code, $token->code_hash);
        $this->assertSame(64, strlen($token->code_hash));
    }

    public function test_opening_the_link_does_nothing(): void
    {
        $user = $this->makeUser();
        [$mail] = $this->requestMail($user);

        $this->get($mail->url)->assertOk()->assertSee(__('auth.link.continue'));

        $this->assertGuest();
        $this->assertNull(LoginToken::firstOrFail()->used_at);
    }

    public function test_the_link_signs_in_once_and_verifies_the_address(): void
    {
        $user = $this->makeUser(['email_verified_at' => null]);
        [$mail] = $this->requestMail($user);
        $token = $this->linkToken($mail);

        $this->post(route('login.link.consume', ['token' => $token]))
            ->assertRedirect(route('auth.passkey.suggest'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(SignIn::EMAIL_LINK, $user->fresh()->last_login_method);
        $this->assertSame(SignIn::EMAIL_LINK, session(EnsureSessionIsValid::METHOD));
        $this->assertSame((int) $user->session_epoch, session(EnsureSessionIsValid::EPOCH));
        $this->assertEqualsWithDelta(time(), session(EnsureSessionIsValid::SIGNED_IN_AT), 5);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'sign-in']);

        $this->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();

        $this->post(route('login.link.consume', ['token' => $token]))
            ->assertRedirect(route('login'))->assertSessionHas('error', __('auth.link.invalid'));
        $this->assertGuest();
    }

    public function test_the_link_works_in_another_browser(): void
    {
        $user = $this->makeUser();
        [$mail] = $this->requestMail($user);
        $this->flushSession();

        $this->post(route('login.link.consume', ['token' => $this->linkToken($mail)]))->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_link_expires(): void
    {
        $user = $this->makeUser();
        [$mail] = $this->requestMail($user);
        $this->travel(16)->minutes();

        $this->post(route('login.link.consume', ['token' => $this->linkToken($mail)]))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_code_signs_in_from_the_requesting_browser(): void
    {
        $user = $this->makeUser();
        $user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'abc', 'credential' => []]);
        [$mail, $bind] = $this->requestMail($user);

        $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
            ->post('/login/code', ['code' => $mail->code])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(SignIn::EMAIL_CODE, session(EnsureSessionIsValid::METHOD));
    }

    public function test_the_code_needs_the_bind_cookie(): void
    {
        $user = $this->makeUser();
        [$mail] = $this->requestMail($user);

        $this->post('/login/code', ['code' => $mail->code])->assertSessionHasErrors('code');
        $this->withCookie(EmailChallenge::BIND_COOKIE, str_repeat('a', 64))
            ->post('/login/code', ['code' => $mail->code])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_the_code_needs_the_requesting_session(): void
    {
        $user = $this->makeUser();
        [$mail, $bind] = $this->requestMail($user);
        $this->flushSession();

        $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
            ->post('/login/code', ['code' => $mail->code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_five_wrong_codes_burn_the_token(): void
    {
        $user = $this->makeUser();
        [$mail, $bind] = $this->requestMail($user);
        $wrong = $mail->code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
                ->post('/login/code', ['code' => $wrong])->assertSessionHasErrors('code');
        }

        $this->assertNotNull(LoginToken::firstOrFail()->used_at);

        $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
            ->post('/login/code', ['code' => $mail->code])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'sign-in code failed']);
    }

    public function test_mails_per_address_are_limited(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 7; $i++) {
            $this->post('/login', ['email' => $user->email])->assertRedirect(route('login.code'));
        }

        Mail::assertSentCount(5);
    }

    public function test_inactive_accounts_get_no_mail(): void
    {
        $user = $this->makeUser(['active' => false]);

        $this->post('/login', ['email' => $user->email])->assertRedirect(route('login.code'));
        Mail::assertNothingSent();
    }

    public function test_the_code_page_needs_a_pending_request(): void
    {
        $this->get('/login/code')->assertRedirect(route('login'));
    }
}
