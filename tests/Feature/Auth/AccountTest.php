<?php

namespace Tests\Feature\Auth;

use App\Auth\EmailChallenge;
use App\Auth\SignIn;
use App\Mail\Auth\EmailChangedMail;
use App\Mail\Auth\VerificationCodeMail;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        Mail::fake();
    }

    public function test_updating_the_profile(): void
    {
        $user = $this->makeUser();

        $this->signedIn($user)->put('/account', [
            'name' => 'Ada Lovelace',
            'locale' => 'fr',
            'timezone' => 'Europe/London',
            'product_news' => '1',
        ])->assertRedirect(route('account.show'));
        $this->assertTrue($user->fresh()->shortcuts_enabled);

        $user->refresh();
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('fr', $user->locale);
        $this->assertSame('Europe/London', $user->timezone);
        $this->assertTrue($user->wants_product_news);

        $this->put('/account', ['name' => 'X', 'locale' => 'de', 'timezone' => 'Mars/Base'])
            ->assertSessionHasErrors(['locale', 'timezone']);
    }

    public function test_changing_the_e_mail_address(): void
    {
        $user = $this->makeUser(['email' => 'old@example.test']);

        $response = $this->signedIn($user)->post('/account/email', ['email' => 'New@Example.test'])
            ->assertRedirect(route('account.email.confirm'));
        $bind = $this->bindCookie($response);

        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('new@example.test') && $mail->purpose === 'email_change';
        });

        $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
            ->post('/account/email/confirm', ['code' => $code])
            ->assertRedirect(route('account.tab', 'security'));

        $user->refresh();
        $this->assertSame('new@example.test', $user->email);
        $this->assertSame(1, $user->session_epoch);
        Mail::assertSent(EmailChangedMail::class, fn ($mail) => $mail->hasTo('old@example.test'));
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'e-mail changed']);
        $this->get('/account')->assertOk();
    }

    public function test_a_taken_address_gets_the_same_answer_and_no_code(): void
    {
        $this->makeUser(['email' => 'taken@example.test']);
        $user = $this->makeUser();

        $this->signedIn($user)->post('/account/email', ['email' => 'taken@example.test'])
            ->assertRedirect(route('account.email.confirm'))
            ->assertSessionHas('status', __('auth.email_change.sent'));

        Mail::assertNothingSent();
    }

    public function test_changing_the_e_mail_address_needs_a_recent_sign_in(): void
    {
        $user = $this->makeUser();

        $this->signedIn($user, SignIn::EMAIL_LINK, time() - 3600)
            ->post('/account/email', ['email' => 'new@example.test'])
            ->assertRedirect(route('auth.confirm'));
        Mail::assertNothingSent();
    }

    public function test_exporting_my_data(): void
    {
        $user = $this->makeUser(['name' => 'Export Me']);

        $response = $this->signedIn($user)->get('/account/export')->assertOk();

        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Export Me', $data['profile']['name']);
        $this->assertArrayHasKey('organisations', $data);
        $this->assertArrayHasKey('activity', $data);
    }

    public function test_deleting_the_account(): void
    {
        $user = $this->makeUser();
        $org = Organisation::create(['name' => 'Solo Ltd', 'created_by' => $user->id]);
        $org->members()->attach($user->id, ['role' => 'admin']);
        activity('default')->causedBy($user)->log('did something');

        $this->signedIn($user)->delete('/account', ['confirm' => '1'])->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull(User::find($user->id));
        $this->assertNull(Organisation::find($org->id));
        $this->assertNull(Activity::where('description', 'did something')->first()->causer_id);
        $this->assertSame((string) $user->id, (string) Activity::where('description', 'account deleted')->first()->causer_id);
    }

    public function test_deleting_the_account_is_refused_while_owning_products(): void
    {
        $user = $this->makeUser();
        $org = Organisation::create(['name' => 'Maker Ltd', 'created_by' => $user->id]);
        DB::table('products')->insert([
            'id' => (string) Str::ulid(),
            'organisation_id' => $org->id,
            'owner_id' => $user->id,
            'name' => 'Router X',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->signedIn($user)->delete('/account', ['confirm' => '1'])
            ->assertRedirect(route('account.tab', 'delete'))
            ->assertSessionHas('error', __('auth.delete.owns_products'));

        $this->assertNotNull(User::find($user->id));
    }

    public function test_deleting_the_account_needs_a_recent_sign_in(): void
    {
        $user = $this->makeUser();

        $this->signedIn($user, SignIn::EMAIL_LINK, time() - 3600)->delete('/account', ['confirm' => '1'])
            ->assertRedirect(route('auth.confirm'));
        $this->assertNotNull(User::find($user->id));
    }
}
