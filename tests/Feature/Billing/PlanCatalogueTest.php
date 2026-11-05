<?php

namespace Tests\Feature\Billing;

use App\Auth\SignIn;
use App\Billing\Billing;
use App\Billing\Catalogue;
use App\Billing\Plan;
use App\Models\BillingConsent;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\After;
use Stripe\ApiRequestor;
use Tests\Concerns\BuildsProducts;
use Tests\Concerns\FakesStripe;
use Tests\Feature\Admin\AdminTestHelpers;
use Tests\TestCase;

/**
 * Plans and prices (App\Billing\Catalogue, console "Plans and prices"), Stripe stubbed: monthly and
 * yearly prices for a plan and its add-on "Additional product", Checkout on the chosen plan and
 * interval, a subscription of a plan plus add-on units, plans made in the console (Premium...),
 * and trials without a card that staff give and end.
 */
class PlanCatalogueTest extends TestCase
{
    use AdminTestHelpers, BuildsProducts, FakesStripe, RefreshDatabase {
        BuildsProducts::signIn insteadof AdminTestHelpers;
    }

    private User $owner;

    private User $admin;

    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeStripe();
        // No legacy STRIPE_PRICE_PRODUCT_MONTHLY: the prices come from the console.
        config(['redact.billing.price' => '', 'redact.plans.free.products' => 1]);
        $this->owner = $this->owner();
        $this->organisation = $this->owner->organisations()->first();
        $this->organisation->forceFill(['stripe_id' => 'cus_test'])->save();
        $this->newProduct('Free lock')->assertRedirect();
        $this->admin = $this->superAdmin();
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

    private function asAdmin(): static
    {
        return $this->signIn($this->admin, SignIn::PASSKEY);
    }

    /** Paid: 100 € a month or 1000 € a year, each additional product the same (owner, 2026-11-04). */
    private function createPrices(): void
    {
        $this->stripeReturns('POST /v1/products', fn (array $p) => ['id' => 'prod_'.$p['metadata']['redact_item'], 'object' => 'product']);
        $this->stripeReturns('POST /v1/prices', fn (array $p) => ['id' => 'price_'.$p['metadata']['redact_item'].'_'.$p['recurring']['interval'], 'object' => 'price',
            'product' => $p['product'], 'unit_amount' => (int) $p['unit_amount'], 'currency' => $p['currency'], 'recurring' => ['interval' => $p['recurring']['interval']]]);
        foreach (['plan', 'extra_product'] as $item) {
            foreach (['month' => 100, 'year' => 1000] as $interval => $amount) {
                $this->asAdmin()->post(route('admin.plans.price', 'paid'), ['item' => $item, 'interval' => $interval, 'amount' => $amount])
                    ->assertRedirect(route('admin.plans.index'))->assertSessionHasNoErrors();
            }
        }
    }

    /** @param  array<string, int>  $items  Stripe price => quantity */
    private function subscribe(array $items): void
    {
        $subscription = $this->organisation->subscriptions()->create([
            'type' => 'products', 'stripe_id' => 'sub_test', 'stripe_status' => 'active',
            'stripe_price' => count($items) === 1 ? array_key_first($items) : null, 'quantity' => count($items) === 1 ? reset($items) : null,
        ]);
        foreach ($items as $price => $quantity) {
            $subscription->items()->create(['stripe_id' => 'si_'.$price, 'stripe_product' => 'prod_x', 'stripe_price' => $price, 'quantity' => $quantity]);
        }
    }

    public function test_the_console_creates_monthly_and_yearly_prices_for_the_plan_and_its_add_on(): void
    {
        $this->assertFalse(Plan::billingEnabled(), 'Keys but no price yet.');
        $this->createPrices();

        // One Stripe product for the plan, one for the add-on; recurring, per unit, tax exclusive.
        $this->assertCount(2, $this->stripeCalls('POST', '/v1/products'));
        $yearly = collect($this->stripeCalls('POST', '/v1/prices'))->firstWhere('params.lookup_key', 'redact_paid_extra_product_year')['params'];
        $this->assertSame(['prod_extra_product', 100000, 'eur', 'year', 'licensed', 'exclusive'],
            [$yearly['product'], (int) $yearly['unit_amount'], $yearly['currency'], $yearly['recurring']['interval'], $yearly['recurring']['usage_type'], $yearly['tax_behavior']]);
        $this->assertSame(Catalogue::TAX_CODE, $this->stripeCalls('POST', '/v1/products')[0]['params']['tax_code']);
        $this->assertSame(['REDact Premium', 'REDact Premium: additional product'], array_column(array_column($this->stripeCalls('POST', '/v1/products'), 'params'), 'name'));

        $catalogue = app(Catalogue::class);
        $this->assertTrue(Plan::billingEnabled());
        $this->assertSame(['month', 'year'], $catalogue->intervals('paid'));
        $this->assertSame('price_plan_year', $catalogue->price('paid', Catalogue::ITEM_PLAN, 'year'));
        $this->assertSame(['100 €', '1000 €'], [Plan::priceLabel(), Plan::priceLabel('year')]);
        $this->assertTrue(DB::table('activity_log')->where('description', 'price created')->count() === 4);

        // A new monthly price replaces the current one; the old one stays known (existing subscriptions).
        $this->stripeReturns('POST /v1/prices', fn (array $p) => ['id' => 'price_plan_month_v2', 'object' => 'price', 'product' => $p['product'], 'unit_amount' => (int) $p['unit_amount'], 'currency' => 'eur']);
        $this->asAdmin()->post(route('admin.plans.price', 'paid'), ['item' => 'plan', 'interval' => 'month', 'amount' => 120])->assertRedirect();
        $this->assertCount(2, $this->stripeCalls('POST', '/v1/products'), 'The plan product is reused.');
        $this->assertSame('price_plan_month_v2', $catalogue->price('paid', Catalogue::ITEM_PLAN, 'month'));
        $this->assertSame(['plan' => 'paid', 'item' => 'plan', 'interval' => 'month'], $catalogue->describe('price_plan_month'));

        // The page: prices, the add-on, the earlier price. Staff read it, nobody else reaches it.
        $this->asAdmin()->get(route('admin.plans.index'))->assertOk()
            ->assertSee('price_plan_month_v2')->assertSee('price_extra_product_year')->assertSee('120 €')->assertSee(__('plan_catalogue.price.history'))
            ->assertSee(__('plan_catalogue.items.extra_product'));
        $this->signIn($this->staff(), SignIn::PASSKEY)->get(route('admin.plans.index'))->assertOk()->assertSee(__('plan_catalogue.read_only'))
            ->assertDontSee(__('plan_catalogue.new.title'));
        $this->signIn($this->staff(), SignIn::PASSKEY)->post(route('admin.plans.price', 'paid'), ['item' => 'plan', 'interval' => 'month', 'amount' => 1])->assertNotFound();
        $this->signIn($this->owner)->get(route('admin.plans.index'))->assertNotFound();
    }

    public function test_checkout_takes_the_chosen_plan_and_interval(): void
    {
        $this->createPrices();
        $this->stripeReturns('GET /v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer', 'name' => 'ACME']);
        $this->stripeReturns('POST /v1/checkout/sessions', ['id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test']);

        $this->signIn($this->owner)->get(route('products.create'))->assertRedirect(route('billing.subscribe', $this->organisation));
        $this->get(route('billing.subscribe', $this->organisation))->assertOk()
            ->assertSee('value="paid:month"', false)->assertSee('value="paid:year"', false)->assertSee('1000 €')->assertSee('Total per month')
            ->assertSee('name="extra"', false)->assertSee('min="0" max="9" value="0"', false)
            ->assertSee(__('plans.subscribe.pay', ['amount' => '100 €', 'per' => 'per month']))->assertSee(__('plans.subscribe.included_line', ['count' => 2, 'plan' => 'Premium']));

        $this->post(route('billing.checkout', $this->organisation), ['offer' => 'paid:week', 'business' => '1'])->assertSessionHasErrors('offer');
        $this->post(route('billing.checkout', $this->organisation), ['offer' => 'paid:year', 'business' => '1'])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test');

        $params = $this->stripeCalls('POST', '/v1/checkout/sessions')[0]['params'];
        $this->assertCount(1, $params['line_items'], 'The plan includes the first paid product: no add-on unit yet.');
        $this->assertSame('price_plan_year', $params['line_items'][0]['price']);
        $this->assertEquals(1, $params['line_items'][0]['quantity']);
        $this->assertSame(['paid', 'year'], [$params['subscription_data']['metadata']['redact_plan'], $params['subscription_data']['metadata']['redact_interval']]);
    }

    public function test_the_subscribe_page_buys_the_plan_and_the_additional_products_in_one_checkout(): void
    {
        $this->createPrices();
        $this->stripeReturns('GET /v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer', 'name' => 'ACME']);
        $this->stripeReturns('POST /v1/checkout/sessions', ['id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test']);

        // 5 products, yearly: the plan (2 included) plus 3 add-on units, on the year prices.
        $this->signIn($this->owner)->post(route('billing.checkout', $this->organisation), ['offer' => 'paid:year', 'extra' => 3, 'business' => '1'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test');
        $items = collect($this->stripeCalls('POST', '/v1/checkout/sessions')[0]['params']['line_items'])->pluck('quantity', 'price')->map(fn ($q) => (int) $q)->all();
        $this->assertSame(['price_plan_year' => 1, 'price_extra_product_year' => 3], $items);
        $this->assertSame(4, BillingConsent::query()->sole()->quantity, 'The consent records the paid products (the free one aside).');
        $this->post(route('billing.checkout', $this->organisation), ['offer' => 'paid:year', 'extra' => 10, 'business' => '1'])->assertSessionHasErrors('extra');
    }

    public function test_a_visitor_buying_premium_signs_up_pays_then_is_offered_a_passkey(): void
    {
        $this->createPrices();
        $this->flushSession();
        Auth::logout();

        // Premium on the pricing page: /subscribe, the account first (Create an account tab).
        $this->get(route('pricing'))->assertOk()->assertSee(route('subscribe'), false);
        $this->get(route('subscribe'))->assertRedirect(route('register'));
        $this->withSession(['url.intended' => route('subscribe')])->get(route('register'))->assertOk()->assertSee(__('auth.subscribe_lead'));

        // Back from the e-mail: the subscribe page, not the passkey (that comes after the payment).
        $user = $this->makeUser(['email' => 'buyer@example.test']);
        $organisation = Organisation::create(['name' => 'Buyer', 'created_by' => $user->id]);
        $organisation->members()->attach($user->id, ['role' => 'admin']);
        $request = Request::create('/login/code');
        $request->setLaravelSession($session = app('session.store'));
        $session->put('url.intended', route('subscribe'));
        $this->assertSame(route('subscribe'), app(SignIn::class)->homeAfterEmail($request, $user));
        $this->assertTrue($session->get(SignIn::SUGGEST_PASSKEY));
        $session->forget('url.intended');
        $this->assertSame(route('auth.passkey.suggest'), app(SignIn::class)->homeAfterEmail($request, $user), 'Not buying: the passkey first, as before.');

        $this->signIn($user)->get(route('subscribe'))->assertRedirect(route('billing.subscribe', $organisation));
        $this->get(route('billing.subscribe', $organisation))->assertOk()->assertSee(__('plans.upgrade_to', ['plan' => 'Premium']));

        // Paid: the passkey suggestion, then on to New product.
        $organisation->forceFill(['stripe_id' => 'cus_buyer'])->save();
        $this->stripeReturns('GET /v1/checkout/sessions/cs_buyer', ['id' => 'cs_buyer', 'object' => 'checkout.session', 'customer' => 'cus_other', 'status' => 'open']);
        $this->withSession([SignIn::SUGGEST_PASSKEY => true])->get(route('billing.success', [$organisation, 'cs_buyer']))->assertRedirect(route('auth.passkey.suggest'));
        $this->assertSame(route('products.create'), session('url.intended'));
    }

    public function test_the_payer_finds_plan_and_billing_in_the_account_menu(): void
    {
        $this->createPrices();
        $this->signIn($this->owner)->get(route('dashboard'))->assertOk()->assertSee(route('billing.mine'), false);
        $this->get(route('billing.mine'))->assertRedirect(route('billing.show', $this->organisation));

        // A member who does not pay: no billing entry.
        $member = User::factory()->create();
        $this->organisation->members()->attach($member->id, ['role' => 'member']);
        $this->signIn($member)->get(route('dashboard'))->assertOk()->assertDontSee(route('billing.mine'), false);
        $this->get(route('billing.mine'))->assertNotFound();
    }

    public function test_online_sales_stop_at_eleven_products_then_talk_to_us(): void
    {
        // The plan (2 included) plus 9 add-on units: 11 products (10 000 € a year), the most sold online.
        $this->createPrices();
        $this->subscribe(['price_plan_month' => 1, 'price_extra_product_month' => 9]);
        foreach (range(2, 11) as $n) {
            $this->newProduct('Lock '.$n)->assertRedirect();
        }
        $this->assertTrue(Plan::fresh($this->organisation)->onlineMaximumReached());

        $this->signIn($this->owner)->get(route('products.create'))->assertOk()
            ->assertSee(__('plans.add.maximum', ['max' => 11]))->assertDontSee(__('plans.add.confirm_check', ['price' => '100 €', 'per' => 'per month']));
        $this->post(route('billing.products.add', $this->organisation), ['confirm' => '1', 'business' => '1'])->assertRedirect(route('products.create'));
        $this->assertSame([], $this->stripeCalls('POST', '/v1/subscription_items'));
        $this->assertSame([], $this->stripeCalls('POST', '/v1/subscriptions/sub_test'));
    }

    public function test_a_subscription_is_the_plan_plus_one_add_on_unit_per_additional_product(): void
    {
        $this->createPrices();
        $this->subscribe(['price_plan_year' => 1, 'price_extra_product_year' => 2]);

        $plan = Plan::fresh($this->organisation);
        $this->assertSame([Plan::SOURCE_SUBSCRIPTION, 'paid', 'year', 3, 2], [$plan->source, $plan->key(), $plan->interval, $plan->paidSlots, $plan->extraUnits]);
        $this->assertSame(500 + 500 * 2, $plan->aiAllowance()['units']);
        $this->assertSame(['1000 €', 'per year'], [$plan->extraPriceLabel(), $plan->per()]);
        $this->assertNull($plan->teamLimit($this->organisation->products()->firstOrFail()), 'Paid: unlimited team on every product, the free one too.');

        // One more product: one more add-on unit, on the subscription's interval.
        $this->stripeReturns('POST /v1/subscription_items/si_price_extra_product_year', fn (array $p) => ['id' => 'si_price_extra_product_year', 'object' => 'subscription_item', 'quantity' => (int) $p['quantity'], 'price' => ['id' => 'price_extra_product_year', 'object' => 'price', 'product' => 'prod_x']]);
        $this->stripeReturns('GET /v1/subscriptions/sub_test', ['id' => 'sub_test', 'object' => 'subscription', 'status' => 'active', 'customer' => 'cus_test', 'items' => ['object' => 'list', 'data' => []]]);
        foreach (['Paid lock', 'Second', 'Third'] as $name) {
            $this->newProduct($name)->assertRedirect();
        }
        $this->assertTrue(Plan::fresh($this->organisation)->productLimitReached());
        $this->signIn($this->owner)->get(route('products.create'))->assertOk()->assertSee('This adds 1000 € per year (prorated)');
        $this->post(route('billing.products.add', $this->organisation), ['confirm' => '1', 'business' => '1'])
            ->assertRedirect(route('products.create'))->assertSessionHas('status', __('plans.billing.added', ['price' => '1000 €', 'per' => 'per year']));
        $this->assertEquals(3, $this->stripeCalls('POST', '/v1/subscription_items/si_price_extra_product_year')[0]['params']['quantity']);
        $this->assertSame(4, Plan::fresh($this->organisation)->paidSlots);
    }

    public function test_the_billing_page_adds_products_and_never_removes_any(): void
    {
        // Yearly: the plan (2 included) plus 2 add-on units, 4 products, 1 in use.
        $this->createPrices();
        $this->subscribe(['price_plan_year' => 1, 'price_extra_product_year' => 2]);
        $this->stripeReturns('POST /v1/subscription_items/si_price_extra_product_year', fn (array $p) => ['id' => 'si_price_extra_product_year', 'object' => 'subscription_item', 'quantity' => (int) $p['quantity'], 'price' => ['id' => 'price_extra_product_year', 'object' => 'price', 'product' => 'prod_x']]);
        $this->stripeReturns('DELETE /v1/subscription_items/si_price_extra_product_year', ['id' => 'si_price_extra_product_year', 'object' => 'subscription_item', 'deleted' => true]);
        $this->stripeReturns('GET /v1/subscription_items/si_price_extra_product_year', ['id' => 'si_price_extra_product_year', 'object' => 'subscription_item', 'quantity' => 1, 'price' => ['id' => 'price_extra_product_year', 'object' => 'price', 'product' => 'prod_x', 'recurring' => ['interval' => 'year', 'usage_type' => 'licensed']]]);
        $this->stripeReturns('GET /v1/subscriptions/sub_test', ['id' => 'sub_test', 'object' => 'subscription', 'status' => 'active', 'customer' => 'cus_test', 'items' => ['object' => 'list', 'data' => []]]);
        $this->stripeReturns('GET /v1/invoices', ['object' => 'list', 'data' => [], 'has_more' => false]);
        $this->stripeReturns('POST /v1/invoices/create_preview', ['object' => 'invoice', 'customer' => 'cus_test', 'status' => 'draft', 'total' => 400000, 'currency' => 'eur', 'created' => now()->addYear()->timestamp, 'lines' => ['object' => 'list', 'data' => []]]);

        $this->signIn($this->owner)->get(route('billing.show', $this->organisation))->assertOk()
            ->assertSee(__('plans.billing.products_used_value', ['used' => 1, 'count' => 4]))->assertSee(__('plans.billing.payment_title'))
            ->assertSee(trans_choice('plans.billing.add_button', 1, ['count' => 1, 'amount' => '1000 €', 'per' => 'per year']))
            ->assertSee('max="7"', false)
            ->assertDontSee('name="products"', false)->assertSee(__('plans.billing.products_value', ['used' => 1, 'total' => 4]));

        // Add 2 (6 in all): the consent recorded, 4 add-on units.
        $this->put(route('billing.products.set', $this->organisation), ['add' => 8, 'business' => '1'])->assertSessionHasErrors('add');
        $this->put(route('billing.products.set', $this->organisation), ['add' => 2, 'business' => '1'])
            ->assertRedirect(route('billing.show', $this->organisation))->assertSessionHas('status', __('plans.billing.products_saved', ['count' => 6]));
        $this->assertEquals(4, $this->stripeCalls('POST', '/v1/subscription_items/si_price_extra_product_year')[0]['params']['quantity']);
        $this->assertSame(5, Plan::fresh($this->organisation)->paidSlots);

        // Never fewer from the billing page (owner 2026-11-05): cancelling is in Stripe's portal.
        $this->put(route('billing.products.set', $this->organisation), ['products' => 2, 'business' => '1'])->assertSessionHasErrors('add');
        $this->assertSame([], $this->stripeCalls('DELETE', '/v1/subscription_items/si_price_extra_product_year'));
        $this->assertSame(5, Plan::fresh($this->organisation)->paidSlots);
    }

    public function test_no_negative_zero_or_odd_number_of_products_reaches_stripe(): void
    {
        $this->createPrices();
        $this->subscribe(['price_plan_year' => 1, 'price_extra_product_year' => 2]);
        $this->signIn($this->owner);

        // Adding on the billing page: a whole number, 1 or more.
        foreach (['-3', '0', '1.5', 'abc', '', '1e3', '-0'] as $bad) {
            $this->put(route('billing.products.set', $this->organisation), ['add' => $bad, 'business' => '1'])->assertSessionHasErrors('add');
        }
        // The subscribe page and checkout: extra products 0 or more, a total 1 or more.
        $this->organisation->subscriptions()->delete();
        foreach ([['extra' => '-2'], ['extra' => '2.5'], ['products' => '-5'], ['products' => '0'], ['extra' => 'x']] as $bad) {
            cache()->flush(); // the checkout is rate-limited: each try on its own
            $this->post(route('billing.checkout', $this->organisation), ['offer' => 'paid:year', 'business' => '1'] + $bad)->assertSessionHasErrors(array_key_first($bad));
        }

        $this->assertSame([], $this->stripeCalls('POST', '/v1/subscription_items/si_price_extra_product_year'));
        $this->assertSame([], $this->stripeCalls('POST', '/v1/checkout/sessions'));
    }

    public function test_the_billing_service_never_lowers_the_products_paid_for(): void
    {
        $this->createPrices();
        $this->subscribe(['price_plan_year' => 1, 'price_extra_product_year' => 2]);
        $before = Plan::fresh($this->organisation)->paidSlots;

        app(Billing::class)->setProducts($this->organisation, $this->owner, -10);
        app(Billing::class)->setProducts($this->organisation, $this->owner, 1);

        $this->assertSame($before, Plan::fresh($this->organisation)->paidSlots);
        $this->assertSame([], $this->stripeCalls('DELETE', '/v1/subscription_items/si_price_extra_product_year'));
        $this->assertSame([], $this->stripeCalls('POST', '/v1/subscription_items/si_price_extra_product_year'));
    }

    public function test_the_first_additional_product_adds_the_add_on_price_to_the_subscription(): void
    {
        $this->createPrices();
        $this->subscribe(['price_plan_month' => 1]);
        $this->newProduct('Paid lock')->assertRedirect();
        $this->assertSame(Plan::SLOT_PAID, Product::query()->where('name', 'Paid lock')->value('plan_slot'));

        // Cashier reads the price before adding it (a metered price would be added without a quantity).
        $this->stripeReturns('GET /v1/prices/price_extra_product_month', ['id' => 'price_extra_product_month', 'object' => 'price', 'recurring' => ['interval' => 'month', 'usage_type' => 'licensed']]);
        $this->stripeReturns('POST /v1/subscription_items', fn (array $p) => ['id' => 'si_new', 'object' => 'subscription_item', 'quantity' => (int) $p['quantity'], 'price' => ['id' => $p['price'], 'object' => 'price', 'product' => 'prod_extra_product']]);
        $this->stripeReturns('GET /v1/subscriptions/sub_test', ['id' => 'sub_test', 'object' => 'subscription', 'status' => 'active', 'customer' => 'cus_test', 'items' => ['object' => 'list', 'data' => []]]);
        $this->signIn($this->owner)->post(route('billing.products.add', $this->organisation), ['confirm' => '1', 'business' => '1'])->assertRedirect(route('products.create'));

        $params = $this->stripeCalls('POST', '/v1/subscription_items')[0]['params'];
        $this->assertSame(['sub_test', 'price_extra_product_month'], [$params['subscription'], $params['price']]);
        $this->assertEquals(1, $params['quantity']);
    }

    public function test_staff_give_a_trial_without_a_card_and_end_it(): void
    {
        // A plan made in the console, on request only: Premium, 5 products, 2000 AI units a month.
        $this->asAdmin()->post(route('admin.plans.store'), [
            'key' => 'premium', 'names' => ['en' => 'Premium', 'fr' => 'Premium'], 'products' => 5, 'ai_units' => 2000, 'ai_period' => 'month',
            'support' => '1', 'public' => '0', 'active' => '1',
        ])->assertRedirect(route('admin.plans.index'))->assertSessionHasNoErrors();

        $this->asAdmin()->put(route('admin.organisations.plan', $this->organisation), ['plan' => 'premium', 'plan_ends_at' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('plan_ends_at');
        $this->asAdmin()->put(route('admin.organisations.plan', $this->organisation), ['plan' => 'premium', 'plan_ends_at' => now()->addDays(14)->toDateString()])
            ->assertRedirect(route('admin.organisations.show', $this->organisation));

        $plan = Plan::fresh($this->organisation->refresh());
        $this->assertTrue($plan->onTrial());
        $this->assertSame(['Premium', 5, 2000, 4], [$plan->label(), $plan->productLimit(), $plan->aiAllowance()['units'], $plan->grantedSlots]);
        $this->assertFalse($plan->isEnterprise(), 'A trial is not a contract.');
        $this->newProduct('Trial lock')->assertRedirect();
        $this->newProduct('Trial lock 2')->assertRedirect();
        $this->assertSame(2, Product::query()->where('plan_slot', Plan::SLOT_GRANTED)->count());
        $this->asAdmin()->get(route('admin.organisations.show', $this->organisation))->assertOk()
            ->assertSee(__('plans.trial.until', ['date' => $this->organisation->plan_ends_at->isoFormat('D MMMM YYYY')]))->assertSee(__('plans.admin.end_trial'));

        // Over: back to Free, the trial products read-only (never deleted).
        $this->travel(15)->days();
        $plan = Plan::fresh($this->organisation->refresh());
        $this->assertSame([null, 2], [$plan->source, count($plan->locked)]);
        $this->assertSame(3, $this->organisation->products()->count());
        $this->travelBack();

        // Ended early by staff, logged.
        $this->asAdmin()->delete(route('admin.organisations.trial.end', $this->organisation))->assertRedirect(route('admin.organisations.show', $this->organisation));
        $this->assertSame(['free', null], [$this->organisation->refresh()->plan, $this->organisation->plan_ends_at]);
        $this->assertTrue(DB::table('activity_log')->where('description', 'trial ended')->exists());
        $this->asAdmin()->delete(route('admin.organisations.trial.end', $this->organisation))->assertNotFound();

        // Without an end date it is a contract: no online payment.
        $this->asAdmin()->put(route('admin.organisations.plan', $this->organisation), ['plan' => 'enterprise'])->assertRedirect();
        $plan = Plan::fresh($this->organisation->refresh());
        $this->assertTrue($plan->isEnterprise());
        $this->assertTrue($plan->unlimited());
        $this->assertSame([], $plan->locked);
    }

    public function test_a_subscription_takes_over_a_trial(): void
    {
        $this->createPrices();
        $this->organisation->forceFill(['plan' => 'enterprise', 'plan_ends_at' => now()->addWeek()])->save();
        $this->assertTrue(Plan::fresh($this->organisation)->onTrial());

        $this->subscribe(['price_plan_month' => 1]);
        $plan = Plan::fresh($this->organisation);
        $this->assertSame([Plan::SOURCE_SUBSCRIPTION, 'paid', 1, 0], [$plan->source, $plan->key(), $plan->paidSlots, $plan->grantedSlots]);
    }

    public function test_plans_are_edited_and_only_unused_console_plans_deleted(): void
    {
        $this->asAdmin()->put(route('admin.plans.update', 'paid'), ['products' => 3, 'ai_units' => 800, 'ai_period' => 'month', 'support' => '1', 'public' => '1', 'active' => '1'])
            ->assertRedirect(route('admin.plans.index'));
        $paid = app(Catalogue::class)->plan('paid');
        $this->assertSame([3, 800, 800, true], [$paid->products, $paid->aiUnits, $paid->extraProductAiUnits, $paid->builtIn]);
        $this->assertSame('Premium plan', $paid->name('en'), 'A built-in plan keeps its translated name (Premium, key paid).');

        $this->asAdmin()->delete(route('admin.plans.destroy', 'paid'))->assertSessionHas('error', __('plan_catalogue.errors.built_in'));
        $this->asAdmin()->post(route('admin.plans.store'), ['key' => 'paid', 'ai_units' => 1, 'ai_period' => 'month'])->assertSessionHasErrors('key');
        $this->asAdmin()->post(route('admin.plans.store'), ['key' => 'premium', 'names' => ['en' => 'Premium'], 'ai_units' => 1, 'ai_period' => 'month'])->assertRedirect();

        $this->organisation->forceFill(['plan' => 'premium'])->save();
        $this->asAdmin()->delete(route('admin.plans.destroy', 'premium'))->assertSessionHas('error', __('plan_catalogue.errors.in_use'));
        $this->organisation->forceFill(['plan' => 'free'])->save();
        $this->asAdmin()->delete(route('admin.plans.destroy', 'premium'))->assertRedirect(route('admin.plans.index'));
        $this->assertNull(app(Catalogue::class)->plan('premium'));
    }

    public function test_renaming_a_plan_renames_its_stripe_products(): void
    {
        $this->createPrices();
        $this->stripeReturns('POST /v1/products/prod_plan', ['id' => 'prod_plan', 'object' => 'product']);
        $this->stripeReturns('POST /v1/products/prod_extra_product', ['id' => 'prod_extra_product', 'object' => 'product']);
        $form = ['products' => 2, 'ai_units' => 500, 'ai_period' => 'month', 'support' => '1', 'public' => '1', 'active' => '1'];

        // The same name: Stripe is not called.
        $this->asAdmin()->put(route('admin.plans.update', 'paid'), $form)->assertRedirect(route('admin.plans.index'));
        $this->assertSame([], $this->stripeCalls('POST', '/v1/products/prod_plan'));

        $this->asAdmin()->put(route('admin.plans.update', 'paid'), $form + ['names' => ['en' => 'Pro', 'fr' => 'Pro']])
            ->assertRedirect(route('admin.plans.index'))->assertSessionHas('status', __('plan_catalogue.saved', ['name' => 'Pro']))->assertSessionMissing('error');
        $this->assertSame('REDact Pro', $this->stripeCalls('POST', '/v1/products/prod_plan')[0]['params']['name']);
        $this->assertSame('REDact Pro: additional product', $this->stripeCalls('POST', '/v1/products/prod_extra_product')[0]['params']['name']);
        $this->get(route('pricing'))->assertOk()->assertSee('Pro');
    }
}
