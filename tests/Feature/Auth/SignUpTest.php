<?php

namespace Tests\Feature\Auth;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Auth\Altcha;
use App\Mail\Auth\SignInMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SignUpTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->fakeAltcha();
        Mail::fake();
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Grace Hopper',
            'email' => 'Grace@Example.test',
            'organisation' => 'Hopper Radio Ltd',
            'terms' => '1',
            'product_news' => '1',
            'altcha' => 'ok',
        ], $overrides);
    }

    public function test_register_page_renders_the_altcha_widget(): void
    {
        $this->get('/register')->assertOk()->assertSee('altcha-widget', false)->assertSee(route('altcha.challenge'), false);
    }

    public function test_sign_up_creates_an_unverified_user_and_their_organisation(): void
    {
        $this->post('/register', $this->form())->assertRedirect(route('login.code'));

        $user = User::where('email', 'grace@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame(config('redact.auth.terms_version'), $user->terms_version);
        $this->assertTrue($user->wants_product_news);

        $organisation = $user->organisations()->firstOrFail();
        $this->assertSame('Hopper Radio Ltd', $organisation->name);
        $this->assertSame('admin', $organisation->pivot->role);
        $this->assertTrue($organisation->isAdmin($user));

        Mail::assertSent(SignInMail::class, fn (SignInMail $mail) => $mail->hasTo('grace@example.test') && $mail->newAccount);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'sign-up']);
    }

    public function test_an_existing_address_gets_a_sign_in_mail_and_the_same_answer(): void
    {
        $existing = $this->makeUser(['email' => 'grace@example.test']);

        $first = $this->post('/register', $this->form());
        $this->flushSession();
        $second = $this->post('/register', $this->form(['email' => 'new@example.test']));

        $first->assertRedirect(route('login.code'))->assertSessionHas('status', __('auth.register.sent'));
        $second->assertRedirect(route('login.code'))->assertSessionHas('status', __('auth.register.sent'));

        $this->assertSame(2, User::count());
        $this->assertSame(0, $existing->organisations()->count(), 'No organisation is created for an existing account.');
        Mail::assertSent(SignInMail::class, fn (SignInMail $mail) => $mail->hasTo('grace@example.test') && ! $mail->newAccount);
    }

    public function test_sign_up_requires_terms_and_the_proof_of_work(): void
    {
        $this->post('/register', $this->form(['terms' => null]))->assertSessionHasErrors('terms');
        $this->post('/register', $this->form(['altcha' => 'forged']))->assertSessionHasErrors('altcha');
        $this->assertSame(0, User::count());
        Mail::assertNothingSent();
    }

    public function test_sign_ups_are_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', $this->form(['email' => "person{$i}@example.test"]))->assertSessionHasNoErrors();
        }

        $this->post('/register', $this->form(['email' => 'sixth@example.test']))->assertSessionHasErrors('email');
        $this->assertNull(User::where('email', 'sixth@example.test')->first());
    }

    public function test_sign_up_can_be_switched_off(): void
    {
        config(['redact.auth.signup' => false]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->form())->assertNotFound();
    }

    public function test_altcha_solutions_are_verified_and_spent_once(): void
    {
        $altcha = new Altcha;
        $lib = $altcha->lib();
        $challenge = $lib->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2, cost: 10, counter: 3, expiresAt: time() + 600,
        ));
        $solution = $lib->solveChallenge(new SolveChallengeOptions(challenge: $challenge, algorithm: new Pbkdf2));
        $this->assertNotNull($solution);

        $payload = (new Payload($challenge, $solution))->toBase64();

        $this->assertTrue($altcha->verify($payload));
        $this->assertFalse($altcha->verify($payload), 'A solution must not be accepted twice.');
        $this->assertFalse($altcha->verify('bm90IGEgcGF5bG9hZA=='));
    }

    public function test_the_challenge_endpoint_returns_a_signed_challenge(): void
    {
        $this->getJson('/altcha/challenge')
            ->assertOk()
            ->assertJsonStructure(['parameters' => ['algorithm', 'nonce', 'salt', 'cost', 'keyPrefix'], 'signature']);
    }
}
