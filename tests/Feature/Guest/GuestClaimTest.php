<?php

namespace Tests\Feature\Guest;

use App\Actions\CreateProduct;
use App\Auth\EmailChallenge;
use App\Auth\SignIn;
use App\Billing\Plan;
use App\Mail\Auth\VerificationCodeMail;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * "Keep my work": a code to an address turns the sandbox into an account
 * (new address) or moves it into the account of that address.
 */
class GuestClaimTest extends TestCase
{
    use GuestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuest();
        Mail::fake();
    }

    /** @param  array<string, mixed>  $overrides */
    private function send(array $overrides = []): TestResponse
    {
        return $this->post(route('guest.claim.send'), array_merge([
            'name' => 'Grace Hopper',
            'email' => 'Grace@Example.test',
            'organisation' => 'Hopper Radio Ltd',
            'terms' => '1',
            'product_news' => '1',
            'altcha' => 'ok',
        ], $overrides));
    }

    private function sentCode(string $to): string
    {
        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use ($to, &$code) {
            $code = $mail->code;

            return $mail->hasTo($to) && $mail->purpose === 'guest_claim';
        });

        return (string) $code;
    }

    public function test_the_claim_page_asks_for_the_account_details(): void
    {
        $this->startSandbox();

        $this->get(route('guest.claim'))->assertOk()
            ->assertSee(__('guest.claim.existing'))
            ->assertSee('altcha-widget', false)
            ->assertSee(route('guest.altcha'), false);
        $this->send(['terms' => null, 'altcha' => 'forged'])->assertSessionHasErrors('terms');
        $this->send(['altcha' => 'forged'])->assertSessionHasErrors('altcha');
        $this->send(['email' => 'me@guest.invalid'])->assertSessionHasErrors(['email' => __('guest.claim.reserved')]);
        Mail::assertNothingSent();
    }

    public function test_a_new_address_converts_the_guest_in_place(): void
    {
        $guest = $this->startSandbox();
        $version = $this->guestProduct($guest);
        $productId = $version->product_id;
        $organisationId = $guest->organisations()->firstOrFail()->id;

        $response = $this->send()->assertRedirect(route('guest.claim'))->assertSessionHas('status', __('guest.claim.sent'));
        $bind = $this->bindCookie($response);
        $this->get(route('guest.claim'))->assertOk()->assertSee(__('guest.claim.code_heading'))->assertSee('grace@example.test');

        $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
            ->post(route('guest.claim.verify'), ['code' => $this->sentCode('grace@example.test')])
            ->assertRedirect(route('auth.passkey.suggest'));

        $user = User::findOrFail($guest->id);
        $this->assertFalse($user->is_guest);
        $this->assertNull($user->guest_expires_at);
        $this->assertSame('grace@example.test', $user->email);
        $this->assertSame('Grace Hopper', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertTrue($user->wants_product_news);
        $this->assertSame(SignIn::EMAIL_CODE, $user->last_login_method);
        $this->assertAuthenticatedAs($user);

        $this->assertSame($productId, Product::where('owner_id', $user->id)->sole()->id);
        $this->assertSame('Hopper Radio Ltd', Organisation::findOrFail($organisationId)->name);
        $this->assertSame('converted', Activity::where('log_name', 'guest')->where('description', 'sandbox kept')->sole()->properties['path']);

        // A real account now: no banner, the account page opens, AI and a second product are allowed.
        $this->get(route('auth.passkey.suggest'))->assertOk();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('data-guest-banner', false);
        $this->get(route('account.show'))->assertOk();
        $this->get(route('products.create'))->assertOk();
        $this->artisan('redact:purge-guests');
        $this->assertNotNull(User::find($user->id));
    }

    public function test_an_existing_address_receives_the_work_and_the_guest_goes(): void
    {
        $account = $this->makeUser(['email' => 'grace@example.test', 'name' => 'Grace Existing']);
        $home = Organisation::create(['name' => 'Hopper HQ', 'created_by' => $account->id]);
        $home->members()->attach($account->id, ['role' => 'admin']);

        $guest = $this->startSandbox();
        $version = $this->guestProduct($guest);
        $workspace = $guest->organisations()->firstOrFail();

        $response = $this->send()->assertRedirect(route('guest.claim'))->assertSessionHas('status', __('guest.claim.sent'));
        $bind = $this->bindCookie($response);

        $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
            ->post(route('guest.claim.verify'), ['code' => $this->sentCode('grace@example.test')])
            ->assertRedirect(route('auth.passkey.suggest'));

        $this->assertAuthenticatedAs($account);
        $this->assertNull(User::find($guest->id));
        $this->assertNull(Organisation::find($workspace->id));

        $product = Product::findOrFail($version->product_id);
        $this->assertSame($account->id, $product->owner_id);
        $this->assertSame($home->id, $product->organisation_id);
        $this->assertSame($account->id, $version->fresh()->created_by);
        $this->assertSame('Grace Existing', $account->fresh()->name, 'The existing account is not renamed.');
        $this->assertSame('merged', Activity::where('log_name', 'guest')->where('description', 'sandbox kept')->sole()->properties['path']);
        // Opening the product leads to its latest version's Overview.
        $this->get(route('products.show', $product))->assertRedirect(route('versions.show', $version));
        $this->get(route('versions.show', $version))->assertOk();
    }

    public function test_a_merged_sandbox_product_beyond_the_free_plan_is_read_only(): void
    {
        // The loophole: an account with its free product keeps a sandbox into the same address, again
        // and again. Each merged product lands on the free slot: beyond the plan it must be read-only.
        $account = $this->makeUser(['email' => 'grace@example.test', 'name' => 'Grace Existing']);
        $home = Organisation::create(['name' => 'Hopper HQ', 'created_by' => $account->id]);
        $home->members()->attach($account->id, ['role' => 'admin']);
        $first = app(CreateProduct::class)($account, ['organisation_id' => $home->id, 'name' => 'Mine'])->product;

        $guest = $this->startSandbox();
        $version = $this->guestProduct($guest);
        $bind = $this->bindCookie($this->send());
        $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
            ->post(route('guest.claim.verify'), ['code' => $this->sentCode('grace@example.test')])->assertRedirect();

        $merged = Product::findOrFail($version->product_id);
        $this->assertSame($home->id, $merged->organisation_id);
        $this->assertTrue(Plan::fresh($home)->isLocked($merged), 'Beyond the free plan: read-only.');
        $this->assertFalse(Plan::fresh($home)->isLocked($first), 'The first product stays editable.');
        $this->assertFalse(ProductAccess::for($account, $merged)->canEdit());
        $this->assertTrue(Plan::fresh($home)->productLimitReached(), 'No new product either.');
        $this->get(route('versions.show', $version))->assertOk()->assertSee(__('plans.locked.over_banner'));

        // A slot granted by cetome (or a paid one) covers it.
        $home->forceFill(['limits' => ['extra_products' => 1]])->save();
        $this->assertFalse(Plan::fresh($home)->isLocked($merged));
        $this->assertTrue(Plan::fresh($home)->productLimitReached(), 'The granted slot is taken by the merged product.');

        // Without it, deleting the first product frees the free slot.
        $home->forceFill(['limits' => null])->save();
        $first->forceDelete();
        $this->assertFalse(Plan::fresh($home)->isLocked($merged));
    }

    public function test_an_existing_account_without_an_admin_organisation_gets_one(): void
    {
        $account = $this->makeUser(['email' => 'grace@example.test']);
        $guest = $this->startSandbox();
        $version = $this->guestProduct($guest);

        $bind = $this->bindCookie($this->send());
        $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
            ->post(route('guest.claim.verify'), ['code' => $this->sentCode('grace@example.test')])->assertRedirect();

        $organisation = Product::findOrFail($version->product_id)->organisation;
        $this->assertSame('Hopper Radio Ltd', $organisation->name);
        $this->assertTrue($organisation->isAdmin($account));
    }

    public function test_the_answer_is_the_same_whether_or_not_the_address_has_an_account(): void
    {
        $this->makeUser(['email' => 'taken@example.test']);

        $this->startSandbox();
        $first = $this->send(['email' => 'taken@example.test']);
        Auth::guard('web')->logout();
        $this->flushSession();
        $this->startSandbox();
        $second = $this->send(['email' => 'free@example.test']);

        foreach ([$first, $second] as $response) {
            $response->assertRedirect(route('guest.claim'))->assertSessionHas('status', __('guest.claim.sent'));
            $this->assertNotNull($response->getCookie(EmailChallenge::BIND_COOKIE));
        }
        Mail::assertSent(VerificationCodeMail::class, 2);
    }

    public function test_five_wrong_codes_burn_the_code(): void
    {
        $guest = $this->startSandbox();
        $bind = $this->bindCookie($this->send());
        $code = $this->sentCode('grace@example.test');
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
                ->post(route('guest.claim.verify'), ['code' => $wrong])
                ->assertSessionHasErrors(['code' => __('auth.code.invalid')]);
        }

        $this->withCookie(EmailChallenge::BIND_COOKIE, $bind)
            ->post(route('guest.claim.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertTrue($guest->fresh()->is_guest);
        $this->assertSame(0, User::where('email', 'grace@example.test')->count());
    }

    public function test_the_code_only_works_in_the_browser_that_asked_for_it(): void
    {
        $guest = $this->startSandbox();
        $this->send();

        $this->withCookie(EmailChallenge::BIND_COOKIE, 'another-browser')
            ->post(route('guest.claim.verify'), ['code' => $this->sentCode('grace@example.test')])
            ->assertSessionHasErrors('code');
        $this->assertTrue($guest->fresh()->is_guest);
    }
}
