<?php

namespace Tests\Feature\Billing;

use App\Billing\Plan;
use App\Http\Middleware\LogRequest;
use App\Models\BillingConsent;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use App\Support\ProductAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\After;
use Spatie\Activitylog\Models\Activity;
use Stripe\ApiRequestor;
use Tests\Concerns\BuildsProducts;
use Tests\Concerns\FakesStripe;
use Tests\TestCase;

/**
 * Paid products through Stripe (Laravel Cashier), with the stripe-php HTTP client stubbed:
 * Checkout for the first paid product, one more unit on an existing subscription, signed
 * webhooks, read-only paid products when the subscription ends (never deleted, still
 * downloadable), "Also reduce your subscription" on deletion, the billing page and portal.
 */
class BillingTest extends TestCase
{
    use BuildsProducts, FakesStripe, RefreshDatabase;

    private User $owner;

    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['redact.plans.free.products' => 1, 'redact.plans.paid.price_label' => '100 €']);
        $this->fakeStripe();

        $this->owner = $this->owner();
        $this->organisation = $this->owner->organisations()->first();
        $this->organisation->forceFill(['stripe_id' => 'cus_test'])->save();
        // The free product, created through New product.
        $this->newProduct('Free lock')->assertRedirect();
    }

    #[After]
    protected function forgetStripeStub(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    private function newProduct(string $name): TestResponse
    {
        return $this->signIn($this->owner)->post(route('products.store'), ['organisation_id' => $this->organisation->id, 'name' => $name]);
    }

    private function subscribe(int $quantity, string $status = 'active'): Subscription
    {
        $subscription = $this->organisation->subscriptions()->create([
            'type' => 'products', 'stripe_id' => 'sub_test', 'stripe_status' => $status,
            'stripe_price' => 'price_product_monthly', 'quantity' => $quantity,
        ]);
        $subscription->items()->create(['stripe_id' => 'si_test', 'stripe_product' => 'prod_test', 'stripe_price' => 'price_product_monthly', 'quantity' => $quantity]);

        return $subscription;
    }

    /** A paid product, created on a slot of the subscription. */
    private function paidProduct(string $name): ProductVersion
    {
        $this->newProduct($name)->assertRedirect();
        $product = Product::query()->where('name', $name)->sole();
        $this->assertSame(Plan::SLOT_PAID, $product->plan_slot);

        return $product->versions()->firstOrFail();
    }

    public function test_the_first_paid_product_goes_through_stripe_checkout_with_tax(): void
    {
        $this->stripeReturns('GET /v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer', 'name' => 'ACME']);
        $this->stripeReturns('POST /v1/checkout/sessions', ['id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test']);

        $this->get(route('products.create'))->assertOk()
            ->assertSee('Your free product is in use. Each additional product is 100 € per month.')
            ->assertSee(__('plans.add.checkout'))->assertSee(route('billing.checkout', $this->organisation));
        $this->get(route('dashboard'))->assertOk()->assertSee(__('plans.upgrade'))->assertSee(__('plans.line.billing'));

        // The immediate start (EU withdrawal right) must be asked for: nothing reaches Stripe without it.
        $this->assertStringContainsString('name="withdrawal"', $this->get(route('products.create'))->getContent());
        $this->post(route('billing.checkout', $this->organisation))->assertSessionHasErrors('withdrawal');
        $this->assertSame([], $this->stripeCalls('POST', '/v1/checkout/sessions'));
        $this->assertSame(0, BillingConsent::query()->count());

        $this->post(route('billing.checkout', $this->organisation), ['withdrawal' => '1'])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test');
        $consent = BillingConsent::query()->sole();
        $this->assertSame([$this->organisation->id, $this->owner->id, 'checkout', 1, config('redact.auth.terms_version')], [$consent->organisation_id, $consent->user_id, $consent->action, $consent->quantity, $consent->terms_version]);
        $this->assertSame(LogRequest::hashIp('127.0.0.1'), $consent->ip_hash);
        $this->assertNotNull($consent->created_at);

        $params = $this->stripeCalls('POST', '/v1/checkout/sessions')[0]['params'];
        $this->assertSame('subscription', $params['mode']);
        $this->assertSame('cus_test', $params['customer']);
        $this->assertSame('price_product_monthly', $params['line_items'][0]['price']);
        $this->assertEquals(1, $params['line_items'][0]['quantity']);
        $this->assertEquals(true, $params['automatic_tax']['enabled']);
        $this->assertEquals(true, $params['tax_id_collection']['enabled']);
        $this->assertSame('required', $params['billing_address_collection']);
        // Stripe writes the session id into the path: no query string.
        $this->assertSame(url('/organisations/'.$this->organisation->id.'/billing/success/{CHECKOUT_SESSION_ID}'), $params['success_url']);
        $this->assertSame(route('billing.cancelled', $this->organisation), $params['cancel_url']);

        // Cancelled on Stripe: back with a message, nothing changed.
        $this->get(route('billing.cancelled', $this->organisation))->assertRedirect(route('products.create'))
            ->assertSessionHas('status', __('plans.billing.cancelled'));
    }

    public function test_back_from_checkout_the_paid_product_can_be_created(): void
    {
        $this->stripeReturns('GET /v1/checkout/sessions/cs_test', [
            'id' => 'cs_test', 'object' => 'checkout.session', 'customer' => 'cus_test', 'status' => 'complete',
            'subscription' => $this->stripeSubscription('cus_test', 1),
        ]);

        // Free plan: no AI.
        $this->assertFalse(Plan::fresh($this->organisation)->aiIncluded());

        $this->get(route('billing.success', [$this->organisation, 'cs_test']))
            ->assertRedirect(route('products.create'))->assertSessionHas('status', __('plans.billing.paid'));

        // The first paid product turns the AI on for the organisation: 500 units a month per paid product.
        $plan = Plan::fresh($this->organisation);
        $this->assertTrue($plan->aiIncluded());
        $this->assertSame(500, $plan->aiAllowance()['units']);
        $this->assertSame('month', $plan->aiAllowance()['period']);

        $subscription = $this->organisation->subscription('products');
        $this->assertSame('active', $subscription->stripe_status);
        $this->assertSame(1, (int) $subscription->quantity);

        $this->get(route('products.create'))->assertOk()->assertSee(__('scoping.what'));
        $this->paidProduct('Paid lock');
        $this->assertTrue(Plan::fresh($this->organisation)->productLimitReached());

        // The webhook arriving later changes nothing.
        $this->postStripeWebhook(['id' => 'evt_1', 'type' => 'customer.subscription.created', 'data' => ['object' => $this->stripeSubscription('cus_test', 1)]])->assertOk();
        $this->assertSame(1, $this->organisation->subscriptions()->count());
    }

    public function test_a_session_of_another_customer_is_ignored(): void
    {
        $this->stripeReturns('GET /v1/checkout/sessions/cs_other', [
            'id' => 'cs_other', 'object' => 'checkout.session', 'customer' => 'cus_someone_else', 'status' => 'complete',
            'subscription' => $this->stripeSubscription('cus_someone_else', 5),
        ]);

        // A checkout started before the id moved into the path comes back with ?session_id= (read once).
        $this->get(route('billing.success', $this->organisation).'?session_id=cs_other')
            ->assertRedirect(route('billing.show', $this->organisation))->assertSessionHas('status', __('plans.billing.pending'));
        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_with_a_subscription_one_more_product_is_confirmed_then_added_prorated(): void
    {
        $this->subscribe(1);
        $this->paidProduct('Paid lock');
        $this->stripeReturns('POST /v1/subscriptions/sub_test', fn (array $params) => $this->stripeSubscription('cus_test', (int) $params['quantity']));

        $this->get(route('products.create'))->assertOk()
            ->assertSee('This adds 100 €/month (prorated) to your subscription, which has 1 paid product today.')
            ->assertSee(__('plans.add.confirm'));

        // The price must be confirmed.
        $this->post(route('billing.products.add', $this->organisation))->assertSessionHasErrors('confirm');
        $this->assertSame([], $this->stripeCalls('POST', '/v1/subscriptions/sub_test'));

        $this->post(route('billing.products.add', $this->organisation), ['confirm' => '1'])->assertSessionHasErrors('withdrawal');
        $this->assertSame([], $this->stripeCalls('POST', '/v1/subscriptions/sub_test'));

        $this->post(route('billing.products.add', $this->organisation), ['confirm' => '1', 'withdrawal' => '1'])
            ->assertRedirect(route('products.create'))->assertSessionHas('status', __('plans.billing.added', ['price' => '100 €']));

        $params = $this->stripeCalls('POST', '/v1/subscriptions/sub_test')[0]['params'];
        $this->assertEquals(2, $params['quantity']);
        $this->assertSame('create_prorations', $params['proration_behavior']);
        $this->assertSame(2, (int) $this->organisation->subscription('products')->quantity);
        $this->assertSame('add_product', BillingConsent::query()->sole()->action);

        $this->paidProduct('Second paid lock');
        $this->assertTrue(Activity::query()->where('log_name', 'billing')->where('description', 'product added')->exists());
    }

    public function test_a_cancelled_subscription_makes_paid_products_read_only_never_deleted(): void
    {
        $this->subscribe(1);
        $paid = $this->paidProduct('Paid lock');
        $free = Product::query()->where('name', 'Free lock')->sole()->versions()->firstOrFail();

        // A forged webhook is refused.
        $this->postStripeWebhook(['id' => 'evt_x', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_test', 'customer' => 'cus_test']]], 'whsec_wrong')
            ->assertForbidden();
        $this->assertSame('active', Subscription::query()->sole()->stripe_status);

        $this->postStripeWebhook(['id' => 'evt_2', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_test', 'customer' => 'cus_test', 'status' => 'canceled']]])
            ->assertOk();
        $this->assertSame('canceled', Subscription::query()->sole()->stripe_status);
        // No paid or granted product left: the AI is off again (free plan).
        $this->assertFalse(Plan::fresh($this->organisation)->aiIncluded());
        $this->assertSame(__('plans.ai.paid_only'), Plan::fresh($this->organisation)->aiRefusal());
        $this->assertTrue(Activity::query()->where('log_name', 'billing')->where('description', 'subscription ended')->exists());

        $this->assertFalse(ProductAccess::for($this->owner, $paid->product)->canEdit($paid));
        $this->assertTrue(ProductAccess::for($this->owner, $free->product)->canEdit($free));
        $this->assertSame(2, Product::query()->count());

        // Shown with the reason, and the settings page still allows deleting it.
        $this->followingRedirects()->get(route('products.show', $paid->product))->assertOk()
            ->assertSee(__('plans.locked.banner'))->assertSee(__('plans.locked.reactivate'));
        $this->get(route('products.edit', $paid->product))->assertOk()->assertSee(__('plans.locked.settings'))->assertSee(__('products.delete'));

        // Editing and imports are refused with the reason (not a 404), the free product is untouched.
        $this->put(route('products.update', $paid->product), ['name' => 'Renamed'])->assertRedirect()->assertSessionHas('error', __('plans.locked.banner'));
        $this->post(route('versions.documents.store', $paid), ['files' => [UploadedFile::fake()->create('spec.pdf', 10, 'application/pdf')]])
            ->assertSessionHas('error', __('plans.locked.banner'));
        $this->put(route('products.update', $free->product), ['name' => 'Free lock 2'])->assertRedirect(route('products.show', $free->product));

        // Reactivated in the portal: editable again.
        $this->postStripeWebhook(['id' => 'evt_3', 'type' => 'customer.subscription.updated', 'data' => ['object' => $this->stripeSubscription('cus_test', 1)]])->assertOk();
        $this->assertTrue(ProductAccess::for($this->owner, $paid->product->fresh())->canEdit($paid));
        $this->assertTrue(Plan::fresh($this->organisation)->aiIncluded());
    }

    public function test_the_ai_stays_on_after_a_cancellation_while_a_granted_product_remains(): void
    {
        $this->organisation->forceFill(['limits' => ['extra_products' => 1]])->save();
        $this->subscribe(1);
        $this->newProduct('Granted lock')->assertRedirect();
        $this->paidProduct('Paid lock');
        $this->assertSame(1000, Plan::fresh($this->organisation)->aiAllowance()['units']);

        $this->postStripeWebhook(['id' => 'evt_9', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_test', 'customer' => 'cus_test']]])->assertOk();

        $plan = Plan::fresh($this->organisation);
        $this->assertTrue($plan->aiIncluded());
        $this->assertSame(500, $plan->aiAllowance()['units']);
        $this->assertTrue($plan->isLocked(Product::query()->where('name', 'Paid lock')->sole()));
        $this->assertFalse($plan->isLocked(Product::query()->where('name', 'Granted lock')->sole()));
    }

    public function test_free_plan_and_a_read_only_paid_product_can_still_download(): void
    {
        $this->subscribe(1);
        $paid = $this->paidProduct('Paid lock');
        $this->postStripeWebhook(['id' => 'evt_4', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_test', 'customer' => 'cus_test']]])->assertOk();
        $this->assertTrue(Plan::fresh($this->organisation)->isLocked($paid->product));

        $this->get(route('reports.download', ['version' => $paid, 'kind' => 'assessment', 'format' => 'docx']))->assertOk()
            ->assertHeader('content-disposition');
        $this->get(route('reports.download', ['version' => $paid, 'kind' => 'assessment', 'format' => 'xlsx']))->assertOk();
        $this->get(route('sbom.template'))->assertOk();
        $this->get(route('reports.index', $paid))->assertOk();
    }

    public function test_a_failed_payment_keeps_products_editable_while_stripe_retries(): void
    {
        $this->subscribe(1);
        $paid = $this->paidProduct('Paid lock');

        $this->postStripeWebhook(['id' => 'evt_5', 'type' => 'invoice.payment_failed', 'data' => ['object' => ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_test', 'status' => 'open']]])->assertOk();
        $this->postStripeWebhook(['id' => 'evt_6', 'type' => 'customer.subscription.updated', 'data' => ['object' => $this->stripeSubscription('cus_test', 1, 'past_due')]])->assertOk();

        $this->assertSame('past_due', Plan::fresh($this->organisation)->subscriptionState());
        $this->assertTrue(ProductAccess::for($this->owner, $paid->product)->canEdit($paid));
        $this->assertTrue(Activity::query()->where('log_name', 'billing')->where('description', 'payment failed')->exists());

        // Unpaid after the retries: read-only.
        $this->postStripeWebhook(['id' => 'evt_7', 'type' => 'customer.subscription.updated', 'data' => ['object' => $this->stripeSubscription('cus_test', 1, 'unpaid')]])->assertOk();
        $this->assertFalse(ProductAccess::for($this->owner, $paid->product)->canEdit($paid));
    }

    public function test_deleting_a_paid_product_may_also_reduce_the_subscription(): void
    {
        $this->subscribe(2);
        $first = $this->paidProduct('Paid one');
        $second = $this->paidProduct('Paid two');
        $this->stripeReturns('POST /v1/subscriptions/sub_test', fn (array $params) => $this->stripeSubscription('cus_test', (int) $params['quantity']));

        $this->get(route('products.edit', $first->product))->assertOk()->assertSee('Also reduce your subscription by one product');

        // Without the box: the slot stays paid for and free for a new product.
        $this->delete(route('products.destroy', $first->product))->assertRedirect(route('dashboard'));
        $this->assertSame([], $this->stripeCalls('POST', '/v1/subscriptions/sub_test'));
        $this->assertSame(1, Plan::fresh($this->organisation)->unusedPaidSlots());

        // With it: one unit less (nothing is deleted on Stripe's side).
        $this->delete(route('products.destroy', $second->product), ['reduce_subscription' => '1'])
            ->assertRedirect(route('dashboard'))->assertSessionHas('status', fn ($s) => str_contains($s, __('plans.billing.reduced')));
        $this->assertEquals(1, $this->stripeCalls('POST', '/v1/subscriptions/sub_test')[0]['params']['quantity']);
        $this->assertSame(1, (int) $this->organisation->subscription('products')->quantity);
        $this->assertSame(1, Product::withTrashed()->where('name', 'Paid two')->count());
    }

    public function test_the_billing_page_shows_the_plan_invoices_and_opens_the_portal_for_admins_only(): void
    {
        $this->subscribe(1);
        $this->paidProduct('Paid lock');
        $invoice = [
            'id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_test', 'number' => 'RED-0001', 'status' => 'paid',
            'total' => 12000, 'subtotal' => 10000, 'currency' => 'eur', 'created' => now()->subDays(3)->timestamp,
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/test', 'lines' => ['object' => 'list', 'data' => []],
        ];
        $this->stripeReturns('GET /v1/invoices', ['object' => 'list', 'data' => [$invoice], 'has_more' => false]);
        $this->stripeReturns('POST /v1/invoices/create_preview', ['object' => 'invoice', 'customer' => 'cus_test', 'status' => 'draft', 'total' => 12000, 'currency' => 'eur', 'created' => now()->addMonth()->timestamp, 'lines' => ['object' => 'list', 'data' => []]]);
        $this->stripeReturns('POST /v1/billing_portal/sessions', ['object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test']);

        $this->get(route('billing.show', $this->organisation))->assertOk()
            ->assertSee(__('plans.billing.title'))->assertSee('RED-0001')->assertSee('https://invoice.stripe.com/i/test')
            ->assertSee(__('plans.billing.next_invoice'))->assertSee(__('plans.billing.manage'));

        $this->post(route('billing.portal', $this->organisation))->assertRedirect('https://billing.stripe.com/p/session/test');
        $this->assertSame(route('billing.show', $this->organisation), $this->stripeCalls('POST', '/v1/billing_portal/sessions')[0]['params']['return_url']);

        // A member who is not an organisation admin, and a stranger: 404.
        $member = User::factory()->create();
        $this->organisation->members()->attach($member->id, ['role' => 'member']);
        $this->signIn($member)->get(route('billing.show', $this->organisation))->assertNotFound();
        $this->signIn(User::factory()->create())->post(route('billing.checkout', $this->organisation))->assertNotFound();
    }

    public function test_read_only_paid_products_are_reactivated_through_checkout(): void
    {
        $this->subscribe(2);
        $this->paidProduct('Paid one');
        $this->paidProduct('Paid two');
        $this->postStripeWebhook(['id' => 'evt_8', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_test', 'customer' => 'cus_test']]])->assertOk();
        $this->assertSame(2, Plan::fresh($this->organisation)->lockedPaidCount());

        $this->stripeReturns('GET /v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer']);
        $this->stripeReturns('POST /v1/checkout/sessions', ['id' => 'cs_re', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_re']);

        $this->get(route('billing.show', $this->organisation))->assertOk()
            ->assertSee('2 paid products are read-only. Reactivating them costs 100 € per month each.');
        $this->post(route('billing.reactivate', $this->organisation))->assertSessionHasErrors('withdrawal');
        $this->post(route('billing.reactivate', $this->organisation), ['withdrawal' => '1'])->assertRedirect('https://checkout.stripe.com/c/pay/cs_re');
        $this->assertSame(['reactivate', 2], [BillingConsent::query()->sole()->action, BillingConsent::query()->sole()->quantity]);
        $this->assertEquals(2, $this->stripeCalls('POST', '/v1/checkout/sessions')[0]['params']['line_items'][0]['quantity']);

        // Paid: the new subscription covers both again.
        $this->stripeReturns('GET /v1/checkout/sessions/cs_re', [
            'id' => 'cs_re', 'object' => 'checkout.session', 'customer' => 'cus_test', 'status' => 'complete',
            'subscription' => $this->stripeSubscription('cus_test', 2, 'active', 'sub_new'),
        ]);
        $this->get(route('billing.success', [$this->organisation, 'cs_re']))
            ->assertRedirect(route('billing.show', $this->organisation))->assertSessionHas('status', __('plans.billing.reactivated'));
        $this->assertSame([], Plan::fresh($this->organisation)->locked);
    }
}
