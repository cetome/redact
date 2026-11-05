<?php

namespace Tests\Feature\Admin;

use App\Auth\SignIn;
use App\Billing\Plan;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Http\Middleware\RequireRecentAuth;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\After;
use Spatie\Activitylog\Models\Activity;
use Stripe\ApiRequestor;
use Tests\Concerns\BuildsProducts;
use Tests\Concerns\FakesStripe;
use Tests\TestCase;

/**
 * Console: delete an account for good (App\Admin\AccountEraser), with or without refunding the last
 * payment. Stripe stubbed: the refund, then the customer deleted (its subscription ends with it).
 */
class AccountEraseTest extends TestCase
{
    use AdminTestHelpers, BuildsProducts, FakesStripe, RefreshDatabase {
        AdminTestHelpers::signIn insteadof BuildsProducts;
    }

    private User $admin;

    private User $customer;

    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeStripe();
        $this->admin = $this->superAdmin();
        $this->customer = $this->owner();
        $this->organisation = $this->customer->organisations()->first();
        $this->product($this->customer);
    }

    #[After]
    protected function forgetStripeStub(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    private function paying(): void
    {
        $this->organisation->forceFill(['stripe_id' => 'cus_buyer'])->save();
        $subscription = $this->organisation->subscriptions()->create(['type' => 'products', 'stripe_id' => 'sub_buyer', 'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1]);
        $subscription->items()->create(['stripe_id' => 'si_buyer', 'stripe_product' => 'prod_x', 'stripe_price' => 'price_x', 'quantity' => 1]);
        // A 30-day period started 10 days ago, paid 120 € that day: 20 days unused, 80 € back.
        $this->travelTo(now()->startOfMinute());
        $start = now()->subDays(10)->getTimestamp();
        $this->stripeReturns('GET /v1/charges', ['object' => 'list', 'data' => [
            ['id' => 'ch_last', 'object' => 'charge', 'amount' => 12000, 'amount_refunded' => 0, 'currency' => 'eur', 'paid' => true, 'status' => 'succeeded', 'created' => $start],
            ['id' => 'ch_old', 'object' => 'charge', 'amount' => 10000, 'amount_refunded' => 10000, 'currency' => 'eur', 'paid' => true, 'status' => 'succeeded', 'created' => $start - 30 * 86400],
        ], 'has_more' => false]);
        $this->stripeReturns('GET /v1/subscriptions', ['object' => 'list', 'has_more' => false, 'data' => [[
            'id' => 'sub_buyer', 'object' => 'subscription', 'status' => 'active',
            'items' => ['object' => 'list', 'data' => [['id' => 'si_buyer', 'object' => 'subscription_item', 'current_period_start' => $start, 'current_period_end' => $start + 30 * 86400]]],
        ]]]);
        $this->stripeReturns('POST /v1/refunds', ['id' => 're_1', 'object' => 'refund', 'amount' => 8000, 'charge' => 'ch_last']);
        $this->stripeReturns('DELETE /v1/customers/cus_buyer', ['id' => 'cus_buyer', 'object' => 'customer', 'deleted' => true]);
    }

    private function assertGone(): void
    {
        $this->assertNull(User::find($this->customer->id));
        $this->assertNull(Organisation::find($this->organisation->id));
        $this->assertSame(0, Product::withTrashed()->where('organisation_id', $this->organisation->id)->count());
        $this->assertSame(0, DB::table('subscriptions')->where('organisation_id', $this->organisation->id)->count());
        $this->assertSame(1, Activity::query()->where('log_name', 'admin')->where('event', 'user_erased')->count());
    }

    public function test_a_free_account_is_deleted_with_its_organisation_and_products(): void
    {
        $this->signIn($this->admin)->get(route('admin.users.show', $this->customer))->assertOk()
            ->assertSee(__('admin.users.erase.title'))->assertSee(__('admin.users.erase.delete'))->assertDontSee(__('admin.users.erase.no_refund'));

        $this->delete(route('admin.users.erase', $this->customer), ['email' => 'wrong@example.test', 'refund' => '0'])
            ->assertSessionHasErrors(['email'], null, 'erase');
        $this->assertNotNull(User::find($this->customer->id));

        $this->delete(route('admin.users.erase', $this->customer), ['email' => strtoupper($this->customer->email), 'refund' => '0'])
            ->assertRedirect(route('admin.users.index'))->assertSessionHas('status', __('admin.users.erase.done', ['email' => $this->customer->email]));
        $this->assertGone();
        $this->assertSame([], $this->stripeCalls('DELETE', '/v1/customers/cus_buyer'));
    }

    public function test_a_paying_account_is_deleted_with_a_refund_of_the_last_payment(): void
    {
        $this->paying();
        $this->signIn($this->admin)->get(route('admin.users.show', $this->customer))->assertOk()
            ->assertSee(__('admin.users.erase.refund', ['amount' => '80 €', 'days' => 20, 'total' => 30, 'paid' => '120 €', 'date' => now()->subDays(10)->isoFormat('D MMMM YYYY')]))
            ->assertSee(__('admin.users.erase.no_refund'));

        $this->delete(route('admin.users.erase', $this->customer), ['email' => $this->customer->email, 'refund' => '1'])
            ->assertRedirect(route('admin.users.index'))->assertSessionHas('status', __('admin.users.erase.done_refund', ['email' => $this->customer->email, 'amount' => '80 €']));

        $refund = $this->stripeCalls('POST', '/v1/refunds');
        $this->assertCount(1, $refund);
        $this->assertSame('ch_last', $refund[0]['params']['charge']);
        $this->assertEquals(8000, $refund[0]['params']['amount'], 'Prorated: 20 of the 30 days.');
        $this->assertCount(1, $this->stripeCalls('DELETE', '/v1/customers/cus_buyer'));
        $this->assertGone();
    }

    public function test_a_paying_account_is_deleted_without_a_refund(): void
    {
        $this->paying();
        $this->signIn($this->admin)->delete(route('admin.users.erase', $this->customer), ['email' => $this->customer->email, 'refund' => '0'])
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame([], $this->stripeCalls('POST', '/v1/refunds'));
        $this->assertCount(1, $this->stripeCalls('DELETE', '/v1/customers/cus_buyer'));
        $this->assertGone();
    }

    public function test_stripe_refusing_deletes_nothing(): void
    {
        $this->paying();
        // Not stubbed: Stripe answers with an error.
        unset($this->stripeResponses['DELETE /v1/customers/cus_buyer']);
        $this->signIn($this->admin)->delete(route('admin.users.erase', $this->customer), ['email' => $this->customer->email, 'refund' => '0'])
            ->assertSessionHas('error');
        $this->assertNotNull(User::find($this->customer->id));
        $this->assertNotNull(Organisation::find($this->organisation->id));
    }

    public function test_work_in_a_shared_organisation_must_be_handed_over_first(): void
    {
        $colleague = $this->makeUser();
        $this->organisation->members()->attach($colleague->id, ['role' => 'admin']);

        $this->signIn($this->admin)->get(route('admin.users.show', $this->customer))->assertOk()
            ->assertSee(__('admin.users.erase.owns_products', ['organisation' => $this->organisation->name]))
            ->assertDontSee('name="refund"', false);
        $this->delete(route('admin.users.erase', $this->customer), ['email' => $this->customer->email, 'refund' => '0'])->assertSessionHas('error');
        $this->assertNotNull(User::find($this->customer->id));

        // The colleague can go: they leave, the organisation and its products stay.
        $this->delete(route('admin.users.erase', $colleague), ['email' => $colleague->email, 'refund' => '0'])->assertRedirect(route('admin.users.index'));
        $this->assertNull(User::find($colleague->id));
        $this->assertSame(1, Product::where('organisation_id', $this->organisation->id)->count());
    }

    public function test_only_a_super_admin_deletes_and_never_staff_or_themselves(): void
    {
        $this->signIn($this->staff())->delete(route('admin.users.erase', $this->customer), ['email' => $this->customer->email, 'refund' => '0'])->assertNotFound();
        $this->signIn($this->admin)->delete(route('admin.users.erase', $this->admin), ['email' => $this->admin->email, 'refund' => '0'])->assertNotFound();
        $this->signIn($this->admin)->delete(route('admin.users.erase', $this->staff()), ['email' => 'x@example.test', 'refund' => '0'])->assertNotFound();
        $this->assertNotNull(User::find($this->customer->id));
    }

    public function test_the_plan_list_shows_the_stripe_subscription_and_saving_keeps_it(): void
    {
        $this->paying();
        $this->signIn($this->admin)->get(route('admin.organisations.show', $this->organisation))->assertOk()
            ->assertSee(__('plans.admin.plans.stripe', ['plan' => Plan::fresh($this->organisation)->label()]))->assertSee(__('plans.admin.stripe_help'))
            ->assertSee(__('plans.admin.end.refund', ['amount' => '80 €', 'days' => 20, 'total' => 30, 'paid' => '120 €', 'date' => now()->subDays(10)->isoFormat('D MMMM YYYY')]));
    }

    public function test_a_super_admin_ends_a_subscription_with_a_refund_and_the_account_stays(): void
    {
        $this->paying();
        $this->stripeReturns('DELETE /v1/subscriptions/sub_buyer', ['id' => 'sub_buyer', 'object' => 'subscription', 'status' => 'canceled']);

        $this->signIn($this->admin)->delete(route('admin.organisations.subscription.end', $this->organisation), ['refund' => '1'])
            ->assertRedirect(route('admin.organisations.show', $this->organisation))
            ->assertSessionHas('status', __('plans.admin.end.done_refund', ['amount' => '80 €']));

        $this->assertEquals(8000, $this->stripeCalls('POST', '/v1/refunds')[0]['params']['amount']);
        $cancel = $this->stripeCalls('DELETE', '/v1/subscriptions/sub_buyer');
        $this->assertCount(1, $cancel);
        $this->assertContains($cancel[0]['params']['prorate'], [false, 'false'], 'No credit on the balance on top of the refund.');
        $this->assertSame([], $this->stripeCalls('DELETE', '/v1/customers/cus_buyer'), 'The customer stays.');
        $this->assertNotNull(User::find($this->customer->id));
        $this->assertFalse(Plan::fresh($this->organisation->refresh())->subscription?->valid() ?? false);
        $this->assertSame(1, Activity::query()->where('log_name', 'admin')->where('event', 'subscription_ended')->count());
    }

    public function test_ending_without_a_refund_and_only_for_a_super_admin(): void
    {
        $this->paying();
        $this->stripeReturns('DELETE /v1/subscriptions/sub_buyer', ['id' => 'sub_buyer', 'object' => 'subscription', 'status' => 'canceled']);

        $this->signIn($this->staff())->delete(route('admin.organisations.subscription.end', $this->organisation), ['refund' => '0'])->assertNotFound();

        $this->signIn($this->admin)->delete(route('admin.organisations.subscription.end', $this->organisation), ['refund' => '0'])
            ->assertSessionHas('status', __('plans.admin.end.done'));
        $this->assertSame([], $this->stripeCalls('POST', '/v1/refunds'));
        $this->assertCount(1, $this->stripeCalls('DELETE', '/v1/subscriptions/sub_buyer'));
    }

    public function test_a_stale_sign_in_confirms_then_returns_to_the_page_not_the_form_address(): void
    {
        $this->paying();
        $page = route('admin.organisations.show', $this->organisation);
        $this->signIn($this->admin)->withSession(['auth.confirmed_at' => 0, EnsureSessionIsValid::SIGNED_IN_AT => time() - 3600]);

        $this->from($page)->delete(route('admin.organisations.subscription.end', $this->organisation), ['refund' => '0'])
            ->assertRedirect(route('auth.confirm'));
        $this->assertSame($page, session('url.intended'), 'Not the DELETE address: a GET there is a 405.');
        $this->assertSame([], $this->stripeCalls('DELETE', '/v1/subscriptions/sub_buyer'));

        $this->assertTrue(session(RequireRecentAuth::AGAIN));

        // Confirmed: back on the page, told to click again.
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        app(SignIn::class)->confirmed($request, $this->admin, SignIn::PASSKEY);
        $this->assertSame(__('auth.confirm.again'), session('status'));
        $this->assertNull(session(RequireRecentAuth::AGAIN));
    }

    public function test_every_subscription_running_at_stripe_is_ended_even_one_redact_does_not_hold(): void
    {
        $this->paying();
        $this->organisation->subscriptions()->first()->markAsCanceled();
        $this->stripeReturns('GET /v1/subscriptions', ['object' => 'list', 'has_more' => false, 'data' => [
            ['id' => 'sub_twice', 'object' => 'subscription', 'status' => 'active', 'items' => ['object' => 'list', 'data' => []]],
            ['id' => 'sub_old', 'object' => 'subscription', 'status' => 'canceled', 'items' => ['object' => 'list', 'data' => []]],
        ]]);
        $this->stripeReturns('DELETE /v1/subscriptions/sub_twice', ['id' => 'sub_twice', 'object' => 'subscription', 'status' => 'canceled']);

        $this->signIn($this->admin)->get(route('admin.organisations.show', $this->organisation))->assertOk()->assertSee(__('plans.admin.end.title'));
        $this->delete(route('admin.organisations.subscription.end', $this->organisation), ['refund' => '0'])
            ->assertSessionHas('status', __('plans.admin.end.done'));
        $this->assertCount(1, $this->stripeCalls('DELETE', '/v1/subscriptions/sub_twice'));
        $this->assertSame([], $this->stripeCalls('DELETE', '/v1/subscriptions/sub_old'));
    }

    public function test_a_payment_refunded_once_is_never_refunded_again(): void
    {
        $this->paying();
        $start = now()->subDays(10)->getTimestamp();
        $this->stripeReturns('GET /v1/charges', ['object' => 'list', 'has_more' => false, 'data' => [
            ['id' => 'ch_last', 'object' => 'charge', 'amount' => 12000, 'amount_refunded' => 8000, 'currency' => 'eur', 'paid' => true, 'status' => 'succeeded', 'created' => $start],
        ]]);

        $this->signIn($this->admin)->get(route('admin.organisations.show', $this->organisation))->assertOk()
            ->assertSee(__('plans.admin.end.no_payment'));
    }
}
