<?php

namespace Tests\Feature\Auth;

use App\Auth\Responses\PasskeyLoginResponse;
use App\Auth\SignIn;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Mail\Auth\SignInMail;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Tests\TestCase;

/** "Keep me signed in" (App\Auth\Remember). */
class RememberMeTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        Mail::fake();
    }

    private function recallerName(): string
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        return $guard->getRecallerName();
    }

    /** @return array{0: SignInMail, 1: string} */
    private function requestMail(User $user, bool $remember): array
    {
        $response = $this->post('/login', ['email' => $user->email] + ($remember ? ['remember' => '1'] : []));
        $bind = $this->bindCookie($response);

        $sent = null;
        Mail::assertSent(SignInMail::class, function (SignInMail $mail) use (&$sent) {
            $sent = $mail;

            return true;
        });

        return [$sent, $bind];
    }

    private function viaLink(User $user, bool $remember): TestResponse
    {
        [$mail] = $this->requestMail($user, $remember);

        return $this->post(route('login.link.consume', ['token' => basename((string) parse_url($mail->url, PHP_URL_PATH))]));
    }

    private function assertRemembered(TestResponse $response): SymfonyCookie
    {
        $cookie = $response->getCookie($this->recallerName());
        $this->assertNotNull($cookie, 'No remember-me cookie.');
        $this->assertEqualsWithDelta(time() + 30 * 86400, $cookie->getExpiresTime(), 120);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());

        return $cookie;
    }

    /** A new browser session: the session expired, only the cookie is left. */
    private function sessionExpired(): void
    {
        $this->flushSession();
        Auth::forgetGuards();
    }

    public function test_the_box_is_on_the_sign_in_page_unticked_and_hidden_when_off(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Keep me signed in for 30 days')
            ->assertSee('name="remember"', false)
            ->assertDontSee('name="remember" value="1" class="c-check mt-1" data-remember checked', false);

        config(['redact.auth.remember_days' => 0]);
        $this->get('/login')->assertOk()->assertDontSee('name="remember"', false);
    }

    public function test_unticked_sets_no_cookie(): void
    {
        $response = $this->viaLink($this->makeUser(), false)->assertRedirect();

        $this->assertAuthenticated();
        $response->assertCookieMissing($this->recallerName());
        $this->assertNull(session(EnsureSessionIsValid::REMEMBER_UNTIL));
    }

    public function test_ticked_e_mail_link_remembers_for_30_days(): void
    {
        $this->assertRemembered($this->viaLink($this->makeUser(), true));
        $this->assertEqualsWithDelta(time() + 30 * 86400, session(EnsureSessionIsValid::REMEMBER_UNTIL), 5);
    }

    public function test_the_link_opened_in_another_browser_does_not_remember(): void
    {
        $user = $this->makeUser();
        [$mail] = $this->requestMail($user, true);
        $this->flushSession();

        $this->post(route('login.link.consume', ['token' => basename((string) parse_url($mail->url, PHP_URL_PATH))]))
            ->assertRedirect()->assertCookieMissing($this->recallerName());
        $this->assertAuthenticatedAs($user);
    }

    public function test_ticked_e_mail_code_remembers(): void
    {
        $user = $this->makeUser();
        [$mail, $bind] = $this->requestMail($user, true);

        $this->assertRemembered(
            $this->withCookie('redact_bind', $bind)->post(route('login.verify'), ['code' => $mail->code])->assertRedirect()
        );
    }

    public function test_ticked_passkey_remembers_and_a_forged_tick_is_ignored_when_off(): void
    {
        foreach ([[30, true], [0, false]] as [$days, $expected]) {
            config(['redact.auth.remember_days' => $days]);
            $user = $this->makeUser();
            $request = Request::create('/passkeys/login', 'POST', ['remember' => true, 'credential' => ['response' => []]],
                server: ['HTTP_ACCEPT' => 'application/json']);
            $request->setLaravelSession($this->app['session.store']);
            $this->app->instance('request', $request);
            Auth::forgetGuards();
            Auth::guard('web')->login($user, true); // what laravel/passkeys does with "remember"
            $request->setUserResolver(fn () => $user);

            app(PasskeyLoginResponse::class)->toResponse($request);

            $queued = Cookie::queued($this->recallerName());
            $this->assertSame($expected, $queued !== null);
            if ($queued) {
                $this->assertEqualsWithDelta(time() + 30 * 86400, $queued->getExpiresTime(), 120);
            }
            Cookie::flushQueuedCookies();
        }
    }

    public function test_an_expired_session_comes_back_from_the_cookie_and_stays(): void
    {
        $user = $this->makeUser();
        $cookie = $this->assertRemembered($this->viaLink($user, true));

        $this->sessionExpired();
        $this->withCookie($cookie->getName(), (string) $cookie->getValue())->get('/account')->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(SignIn::REMEMBER, session(EnsureSessionIsValid::METHOD));
        $this->assertEqualsWithDelta(time() + 30 * 86400, session(EnsureSessionIsValid::REMEMBER_UNTIL), 5);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'sign-in', 'properties->method' => 'remember']);

        // Past the 10-hour absolute limit of a normal session: still in. Not a recent sign-in though.
        $this->withSession([EnsureSessionIsValid::SIGNED_IN_AT => time() - 11 * 3600])->get('/account')->assertOk();
        $this->delete('/account')->assertRedirect(route('auth.confirm'));
        $this->assertNotNull($user->fresh());
    }

    public function test_the_30_days_count_from_the_original_sign_in(): void
    {
        $user = $this->makeUser();
        $cookie = $this->assertRemembered($this->viaLink($user, true));

        // The same cookie, issued 31 days ago (the browser would have dropped it; a copy would not).
        $segments = explode('|', (string) $cookie->getValue());
        $segments[3] = (string) (time() - 31 * 86400);

        $this->sessionExpired();
        $this->withCookie($cookie->getName(), implode('|', $segments))->get('/account')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_signing_out_clears_this_device_only(): void
    {
        $user = $this->makeUser();
        $cookie = $this->assertRemembered($this->viaLink($user, true));
        $token = $user->fresh()->getRememberToken();

        $logout = $this->withCookie($cookie->getName(), (string) $cookie->getValue())->post('/logout');
        $this->assertGuest();
        $forgotten = $logout->getCookie($this->recallerName(), false);
        $this->assertNotNull($forgotten, 'This device forgets its cookie.');
        $this->assertLessThan(time(), $forgotten->getExpiresTime());
        $this->assertSame($token, $user->fresh()->getRememberToken(), 'Other remembered devices are not signed out.');

        // Another device holding its own cookie stays signed in ("Sign out everywhere" ends it).
        $this->sessionExpired();
        $this->withCookie($cookie->getName(), (string) $cookie->getValue())->get('/account')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_revoking_sessions_voids_every_remembered_device(): void
    {
        $user = $this->makeUser();
        $cookie = $this->assertRemembered($this->viaLink($user, true));

        $user->fresh()->revokeSessions();

        $this->sessionExpired();
        $this->withCookie($cookie->getName(), (string) $cookie->getValue())->get('/account')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_signing_out_other_devices_keeps_this_one_remembered(): void
    {
        $user = $this->makeUser();
        $this->assertRemembered($this->viaLink($user, true));

        $fresh = $this->assertRemembered($this->post('/account/sessions/revoke')->assertRedirect());

        $this->sessionExpired();
        $this->withCookie($fresh->getName(), (string) $fresh->getValue())->get('/account')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_sessions_tab_and_privacy_policy_mention_it(): void
    {
        $this->signedIn($this->makeUser())->get('/account/sessions')->assertOk()
            ->assertSee('check your passkeys. This also ends “Keep me signed in” on them.', false);
        $this->get(route('legal.privacy'))->assertOk()->assertSee('keeps you signed in for 30 days');
    }

    public function test_guests_are_never_remembered(): void
    {
        $guest = $this->makeUser(['is_guest' => true, 'email' => 'guest-1@sandbox.invalid']);
        $this->app->instance('request', $request = Request::create('/'));
        $request->setLaravelSession($this->app['session.store']);
        Auth::forgetGuards();

        app(SignIn::class)->complete($request, $guest, SignIn::GUEST, true);
        $this->assertNull(Cookie::queued($this->recallerName()));
        Cookie::flushQueuedCookies();

        // Even a well-formed cookie for a guest is refused.
        $guest->forceFill(['remember_token' => str_repeat('a', 60)])->save();
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $value = $guest->id.'|'.str_repeat('a', 60).'|'.$guard->hashPasswordForCookie($guest->getAuthPassword()).'|'.time();
        Auth::logout();
        $this->sessionExpired();
        $this->withCookie($this->recallerName(), $value)->get('/account')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
