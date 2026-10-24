<?php

namespace Tests\Feature\Team;

use App\Auth\EmailChallenge;
use App\Enums\ProductRole;
use App\Mail\Auth\VerificationCodeMail;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsProducts;
use Tests\Feature\Guest\GuestHelpers;
use Tests\TestCase;

/**
 * Someone invited to an organisation never gets an organisation of their own (owner,
 * 2026-10-23): sign-up and "Keep my work" create none, they land on the invitation; declined
 * or expired, they get their own then (App\Actions\PersonalOrganisation, EnsureOrganisation).
 */
class InvitedSignUpTest extends TestCase
{
    use BuildsProducts, GuestHelpers, RefreshDatabase;

    private User $admin;

    private Organisation $organisation;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuest();
        Mail::fake();

        $this->admin = $this->owner();
        $this->organisation = $this->admin->organisations()->first();
        $this->product = $this->product($this->admin)->product;
    }

    private function invite(string $email = 'grace@example.test'): void
    {
        $this->signIn($this->admin)->post(route('organisations.team.invite', $this->organisation), [
            'invite_email' => $email, 'organisation_role' => 'member', 'products' => [$this->product->id => 'contributor'],
        ])->assertSessionMissing('error');
        $this->post(route('logout'));
        $this->app['auth']->forgetGuards();
    }

    private function register(): User
    {
        $this->post('/register', [
            'name' => 'Grace Hopper', 'email' => 'Grace@Example.test', 'organisation' => 'Hopper Radio',
            'terms' => '1', 'altcha' => 'ok',
        ])->assertRedirect(route('login.code'));

        return User::query()->where('email', 'grace@example.test')->firstOrFail();
    }

    public function test_an_invited_person_signs_up_without_an_organisation_and_lands_on_the_invitation(): void
    {
        $this->invite();
        $grace = $this->register();
        $this->assertSame(0, $grace->organisations()->count());

        $this->signIn($grace)->get(route('dashboard'))->assertRedirect(route('invitations.pending'));
        $this->get(route('products.create'))->assertRedirect(route('invitations.pending'));
        $invitation = Invitation::query()->whereNull('product_id')->firstOrFail();
        $this->get(route('invitations.pending'))->assertOk()
            ->assertSee(__('orgteam.accept.button', ['organisation' => 'ACME']))->assertSee('Smart lock')
            ->assertSee(route('invitations.accept-pending', $invitation), false);

        $this->post(route('invitations.accept-pending', $invitation))->assertRedirect(route('organisations.team', $this->organisation));
        $this->assertSame([$this->organisation->id], $grace->organisations()->pluck('organisations.id')->all());
        $this->assertSame(ProductRole::Contributor, $this->product->roleOf($grace));
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $grace->organisations()->count());
    }

    public function test_declining_or_an_expired_invitation_gives_them_their_own_organisation(): void
    {
        $this->invite();
        $grace = $this->register();
        $invitation = Invitation::query()->whereNull('product_id')->firstOrFail();

        // Someone else cannot decline it.
        $this->signIn($this->admin)->post(route('invitations.decline', $invitation))->assertNotFound();

        $this->signIn($grace)->post(route('invitations.decline', $invitation))->assertRedirect(route('dashboard'));
        $this->assertSame(0, Invitation::query()->count());
        $own = $grace->organisations()->sole();
        $this->assertSame('Hopper Radio', $own->name);
        $this->assertTrue($own->isAdmin($grace));

        // Expired before it was accepted: their own organisation on the next visit.
        $this->invite('ada@example.test');
        $ada = User::factory()->create(['email' => 'ada@example.test', 'name' => 'Ada']);
        $this->assertSame(0, $ada->organisations()->count());
        $this->signIn($ada)->get(route('dashboard'))->assertRedirect(route('invitations.pending'));
        $this->travel(15)->days();
        $this->signIn($ada)->get(route('dashboard'))->assertOk();
        $this->assertSame('Ada', $ada->organisations()->sole()->name);
    }

    public function test_an_existing_account_keeps_its_organisation_when_it_joins_another(): void
    {
        $grace = $this->owner();
        $grace->update(['email' => 'grace@example.test']);
        $own = $grace->organisations()->sole();
        $this->invite();

        $this->signIn($grace)->get(route('dashboard'))->assertOk();
        $invitation = Invitation::query()->whereNull('product_id')->firstOrFail();
        $this->post(route('invitations.accept-pending', $invitation))->assertRedirect();
        $this->assertEqualsCanonicalizing([$own->id, $this->organisation->id], $grace->organisations()->pluck('organisations.id')->all());
    }

    public function test_someone_removed_from_their_only_organisation_gets_their_own(): void
    {
        $bob = User::factory()->create(['name' => 'Bob']);
        $this->organisation->members()->attach($bob->id, ['role' => 'member']);
        $this->signIn($this->admin)->delete(route('organisations.team.destroy', [$this->organisation, $bob]))->assertSessionMissing('error');

        $this->signIn($bob)->get(route('dashboard'))->assertOk();
        $this->assertSame('Bob', $bob->organisations()->sole()->name);
    }

    private function claim(): void
    {
        $response = $this->post(route('guest.claim.send'), [
            'name' => 'Grace Hopper', 'email' => 'grace@example.test', 'organisation' => '', 'terms' => '1', 'altcha' => 'ok',
        ]);
        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return $mail->purpose === 'guest_claim';
        });
        $this->withCookie(EmailChallenge::BIND_COOKIE, $this->bindCookie($response))
            ->post(route('guest.claim.verify'), ['code' => (string) $code])->assertRedirect(route('auth.passkey.suggest'));
    }

    public function test_keep_my_work_without_sandbox_products_creates_no_organisation(): void
    {
        $this->invite();
        $guest = $this->startSandbox();
        $this->claim();

        $grace = User::findOrFail($guest->id);
        $this->assertFalse($grace->is_guest);
        $this->assertSame(0, $grace->organisations()->count());
        $this->get(route('dashboard'))->assertRedirect(route('invitations.pending'));
    }

    public function test_keep_my_work_with_sandbox_products_keeps_them_in_their_own_organisation(): void
    {
        $this->invite();
        $guest = $this->startSandbox();
        $version = $this->guestProduct($guest);
        $this->claim();

        $grace = User::findOrFail($guest->id);
        $own = $grace->organisations()->sole();
        $this->assertSame($own->id, $version->product->fresh()->organisation_id);
        // The invitation still waits on the dashboard.
        $this->get(route('dashboard'))->assertOk()->assertSee(__('products.invitations'));
    }
}
