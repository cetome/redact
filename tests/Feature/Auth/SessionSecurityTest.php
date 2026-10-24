<?php

namespace Tests\Feature\Auth;

use App\Auth\EmailChallenge;
use App\Auth\SignIn;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Mail\Auth\VerificationCodeMail;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        Mail::fake();
    }

    public function test_a_revoked_session_is_signed_out(): void
    {
        $user = $this->makeUser();
        $this->signedIn($user)->get('/account')->assertOk();

        $user->revokeSessions();

        $this->get('/account')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_session_without_sign_in_keys_is_signed_out(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get('/account')->assertRedirect(route('login'));
    }

    public function test_sessions_end_after_the_absolute_lifetime(): void
    {
        $user = $this->makeUser();
        $old = time() - (int) config('redact.auth.session_absolute') * 60 - 10;

        $this->signedIn($user, SignIn::PASSKEY, $old)->get('/account')->assertRedirect(route('login'));
    }

    public function test_signing_out_other_devices_keeps_this_one(): void
    {
        $user = $this->makeUser();

        $this->signedIn($user)->post('/account/sessions/revoke')->assertRedirect(route('account.tab', 'sessions'));

        $this->assertSame(1, $user->fresh()->session_epoch);
        $this->assertSame(1, session(EnsureSessionIsValid::EPOCH));
        $this->get('/account')->assertOk();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'sessions revoked']);
    }

    public function test_logout_signs_out(): void
    {
        $user = $this->makeUser();

        $this->signedIn($user)->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();
        $this->assertNull(session(EnsureSessionIsValid::SIGNED_IN_AT));
    }

    public function test_sensitive_actions_send_old_sessions_to_confirm(): void
    {
        $user = $this->makeUser();

        $this->signedIn($user, SignIn::EMAIL_LINK, time() - 3600)
            ->get('/account/confirm')
            ->assertRedirect(route('auth.confirm'));

        $this->get('/account/security')->assertOk()->assertSee(route('account.reconfirm', ['tab' => 'security']), false);
    }

    public function test_confirming_with_an_e_mail_code(): void
    {
        $user = $this->makeUser();
        $this->signedIn($user, SignIn::EMAIL_LINK, time() - 3600)->get('/account/confirm')->assertRedirect(route('auth.confirm'));

        $this->get('/auth/confirm')->assertOk()->assertSee(__('auth.confirm.send_code'));

        $response = $this->post('/auth/confirm/send')->assertRedirect(route('auth.confirm'));
        $bind = $this->bindCookie($response);

        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$code, $user) {
            $code = $mail->code;

            return $mail->hasTo($user->email) && $mail->purpose === 'confirm';
        });

        $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
            ->post('/auth/confirm', ['code' => $code])
            ->assertRedirect(url('/account/confirm'));

        $this->assertEqualsWithDelta(time(), session('auth.confirmed_at'), 5);
        $this->assertSame(SignIn::EMAIL_LINK, session(EnsureSessionIsValid::METHOD), 'An e-mail code never lifts the method.');
        $this->get('/account/confirm')->assertRedirect(route('account.show'));
    }

    public function test_a_wrong_confirmation_code_is_refused(): void
    {
        $user = $this->makeUser();
        $response = $this->signedIn($user)->post('/auth/confirm/send');
        $bind = $this->bindCookie($response);

        $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
            ->post('/auth/confirm', ['code' => '12345x'])->assertSessionHasErrors('code');
        $this->assertNull(session('auth.confirmed_at'));
    }

    public function test_the_console_is_invisible_to_customers(): void
    {
        $user = $this->makeUser();

        $this->signedIn($user, SignIn::PASSKEY)->get('/admin')->assertNotFound();
    }

    public function test_staff_need_a_passkey_session_for_the_console(): void
    {
        (new RolesSeeder)->run();
        $staff = $this->makeUser();
        $staff->assignRole('staff');

        $this->signedIn($staff, SignIn::EMAIL_LINK)->get('/admin')
            ->assertRedirect(route('auth.confirm'))
            ->assertSessionHas('error', __('auth.staff_passkey_required'));

        $this->signedIn($staff, SignIn::PASSKEY_UNVERIFIED)->get('/admin')->assertRedirect(route('auth.confirm'));

        $this->signedIn($staff, SignIn::PASSKEY)->get('/admin')->assertOk();
    }

    public function test_make_staff_creates_roles_and_grants_them(): void
    {
        $user = $this->makeUser(['email' => 'ops@cetome.test']);

        $this->artisan('redact:make-staff', ['email' => 'ops@cetome.test'])->assertSuccessful();
        $this->assertTrue($user->fresh()->hasRole('staff'));

        $this->artisan('redact:make-staff', ['email' => 'ops@cetome.test', '--super' => true])->assertSuccessful();
        $this->assertTrue($user->fresh()->hasRole('super-admin'));

        $this->artisan('redact:make-staff', ['email' => 'nobody@cetome.test'])->assertFailed();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'staff granted']);
    }

    public function test_purge_deletes_old_unverified_accounts_only(): void
    {
        $old = $this->makeUser(['email_verified_at' => null]);
        $old->forceFill(['created_at' => now()->subDays(8)])->save();
        $org = Organisation::create(['name' => 'Ghost Ltd', 'created_by' => $old->id]);
        $org->members()->attach($old->id, ['role' => 'admin']);

        $recent = $this->makeUser(['email_verified_at' => null]);
        $verified = $this->makeUser();
        $verified->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->artisan('redact:purge-unverified')->assertSuccessful();

        $this->assertNull(User::find($old->id));
        $this->assertNull(Organisation::find($org->id));
        $this->assertNotNull(User::find($recent->id));
        $this->assertNotNull(User::find($verified->id));
    }
}
