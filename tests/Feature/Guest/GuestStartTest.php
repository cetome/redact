<?php

namespace Tests\Feature\Guest;

use App\Auth\SignIn;
use App\Guest\Sandbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class GuestStartTest extends TestCase
{
    use GuestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuest();
    }

    public function test_the_try_page_explains_the_sandbox_and_shows_the_altcha_widget(): void
    {
        $this->get('/try')->assertOk()
            ->assertSee(__('guest.start.no_ai'))
            ->assertSee(__('guest.start.kept', ['hours' => 24]))
            ->assertSee('altcha-widget', false)
            ->assertSee(route('guest.altcha'), false);

        $this->get(route('guest.altcha'))->assertOk()->assertJsonStructure(['parameters', 'signature']);
    }

    public function test_home_starts_the_sandbox_and_login_and_register_link_to_it(): void
    {
        // The home page is the tool: its own form opens the sandbox (HomeStartTest). Every "Try REDact"
        // opens the sandbox from the page itself (x-try-link: the hidden form#sandbox-open, /try without script).
        $this->get('/')->assertOk()->assertSee('action="'.route('home.start').'"', false)->assertSee(__('public.tool.start'))
            ->assertSee('id="sandbox-open"', false);
        $this->get('/about')->assertOk()->assertSee('href="'.route('guest.start').'" class="c-btn c-btn-on-dark c-btn-lg"', false)
            ->assertSee('data-sandbox-open', false)->assertSee(__('public.try_button'));
        $this->get('/login')->assertOk()->assertSee(route('guest.start'), false)->assertSee(__('guest.try_link'));
        $this->get('/register')->assertOk()->assertSee(route('guest.start'), false);
    }

    public function test_starting_requires_the_proof_of_work(): void
    {
        $this->post(route('guest.create'), [])->assertSessionHasErrors('altcha');
        $this->post(route('guest.create'), ['altcha' => 'forged'])->assertSessionHasErrors('altcha');

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_starting_creates_a_signed_in_guest_with_its_workspace(): void
    {
        $guest = $this->startSandbox();

        $this->assertAuthenticatedAs($guest);
        $this->assertTrue($guest->is_guest);
        $this->assertStringEndsWith('@'.Sandbox::EMAIL_DOMAIN, $guest->email);
        $this->assertSame(__('guest.name'), $guest->name);
        $this->assertNull($guest->email_verified_at);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $guest->guest_expires_at->timestamp, 5);
        $this->assertFalse($guest->isStaff());

        $organisation = $guest->organisations()->firstOrFail();
        $this->assertSame(__('guest.organisation'), $organisation->name);
        $this->assertTrue($organisation->isAdmin($guest));

        $this->assertSame(SignIn::GUEST, $guest->last_login_method);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'guest', 'description' => 'sandbox started']);
        $this->assertSame(SignIn::GUEST, Activity::where('log_name', 'security')->where('description', 'sign-in')->latest('id')->first()->properties['method']);

        // Back on /try: straight to the work.
        $this->get('/try')->assertRedirect(route('products.create'));
    }

    public function test_five_sandboxes_per_address_and_day(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->startSandbox();
            Auth::guard('web')->logout();
        }

        $this->post(route('guest.create'), ['altcha' => 'ok'])
            ->assertSessionHasErrors(['altcha' => __('guest.start.too_many')]);
        $this->assertSame(5, User::where('is_guest', true)->count());

        // Another network is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::1'])->post(route('guest.create'), ['altcha' => 'ok'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_signed_in_account_is_sent_to_its_dashboard(): void
    {
        $user = $this->makeUser();

        $this->signedIn($user)->get('/try')->assertRedirect(route('dashboard'));
        $this->post(route('guest.create'), ['altcha' => 'ok'])->assertRedirect(route('dashboard'));
        $this->assertSame(0, User::where('is_guest', true)->count());
    }

    public function test_the_sandbox_can_be_switched_off(): void
    {
        config(['redact.guest.enabled' => false]);

        $this->get('/try')->assertNotFound();
        $this->post(route('guest.create'), ['altcha' => 'ok'])->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee(route('guest.start'), false);
    }
}
