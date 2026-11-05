<?php

namespace Tests\Feature\Billing;

use App\Billing\StripeSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\After;
use Stripe\ApiRequestor;
use Tests\Concerns\FakesStripe;
use Tests\Feature\Admin\AdminTestHelpers;
use Tests\TestCase;

/**
 * The console's Stripe page: keys of a sandbox, then live (encrypted, never shown back), the
 * webhook endpoint created in Stripe (stubbed), the switch to live that unlinks the sandbox
 * customers, and the .env values as the fallback. Prices: PlanCatalogueTest.
 */
class StripeSettingsTest extends TestCase
{
    use AdminTestHelpers, FakesStripe, RefreshDatabase;

    private const SECRET = 'sk_test_0123456789abcdefWXYZ';

    // Split so the open-source leak check (release/open-source/snapshot.php) never sees a key shape.
    private const LIVE_SECRET = 'sk_'.'live_0123456789abcdefLIVE';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeStripe();
        // Nothing in .env: everything comes from the console.
        config(['cashier.key' => '', 'cashier.secret' => '', 'cashier.webhook.secret' => '', 'redact.billing.price' => '']);
        $this->admin = $this->superAdmin();
    }

    #[After]
    protected function forgetStripeStub(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    private function settings(): StripeSettings
    {
        return app(StripeSettings::class);
    }

    private function saveKeys(string $key, string $secret, array $extra = []): TestResponse
    {
        return $this->signIn($this->admin)->put(route('admin.stripe.update'), ['key' => $key, 'secret' => $secret] + $extra);
    }

    private function stubWebhook(string $secret = 'whsec_fromstripe123'): void
    {
        $url = route('cashier.webhook');
        $this->stripeReturns('GET /v1/webhook_endpoints', ['object' => 'list', 'data' => [
            ['id' => 'we_old', 'object' => 'webhook_endpoint', 'url' => $url],
            ['id' => 'we_other', 'object' => 'webhook_endpoint', 'url' => 'https://elsewhere.example/hook'],
        ]]);
        $this->stripeReturns('DELETE /v1/webhook_endpoints/we_old', ['id' => 'we_old', 'object' => 'webhook_endpoint', 'deleted' => true]);
        $this->stripeReturns('POST /v1/webhook_endpoints', ['id' => 'we_new', 'object' => 'webhook_endpoint', 'url' => $url, 'secret' => $secret]);
    }

    public function test_a_sandbox_is_set_up_from_the_console_keys_and_webhook(): void
    {
        $this->saveKeys('pk_test_abc123', self::SECRET)->assertRedirect(route('admin.stripe.edit'));
        $this->assertSame('test', $this->settings()->mode());
        $this->assertSame(self::SECRET, config('cashier.secret'));

        // At rest: encrypted, never in clear, nor in the activity log.
        $raw = (string) DB::table('app_settings')->where('key', 'stripe')->value('value');
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertSame(self::SECRET, Crypt::decryptString(json_decode($raw, true)['secret']));
        $this->assertStringNotContainsString(self::SECRET, (string) DB::table('activity_log')->pluck('properties')->implode(' '));

        // The webhook: the endpoint at this address is replaced, its signing secret kept.
        $this->stubWebhook();
        $this->signIn($this->admin)->post(route('admin.stripe.webhook'))->assertRedirect(route('admin.stripe.edit'));
        $deletes = array_values(array_filter($this->stripeRequests, fn ($r) => $r['method'] === 'DELETE'));
        $this->assertCount(1, $deletes);
        $this->assertSame('/v1/webhook_endpoints/we_old', $deletes[0]['path']);
        $created = $this->stripeCalls('POST', '/v1/webhook_endpoints')[0]['params'];
        $this->assertSame(route('cashier.webhook'), $created['url']);
        $this->assertSame(StripeSettings::webhookEvents(), array_values($created['enabled_events']));
        $this->assertSame('whsec_fromstripe123', config('cashier.webhook.secret'));
        $this->assertSame('console', $this->settings()->source('webhook_secret'));

        // Stripe's signed calls are accepted with that secret, refused with another.
        $event = ['id' => 'evt_1', 'type' => 'balance.available', 'data' => ['object' => []]];
        $this->postStripeWebhook($event, 'whsec_fromstripe123')->assertOk();
        $this->postStripeWebhook($event, 'whsec_wrong')->assertForbidden();
    }

    public function test_going_live_unlinks_the_sandbox_customers_and_drops_the_sandbox_webhook(): void
    {
        $this->saveKeys('pk_test_abc123', self::SECRET, ['webhook_secret' => 'whsec_sandbox0123456789'])->assertRedirect();
        $organisation = $this->seedSampleData()['organisation'];
        $organisation->forceFill(['stripe_id' => 'cus_sandbox', 'pm_last_four' => '4242'])->save();
        $subscription = $organisation->subscriptions()->create(['type' => 'products', 'stripe_id' => 'sub_sandbox', 'stripe_status' => 'active', 'stripe_price' => 'price_new', 'quantity' => 1]);
        $subscription->items()->create(['stripe_id' => 'si_sandbox', 'stripe_product' => 'prod_redact', 'stripe_price' => 'price_new', 'quantity' => 1]);

        // Keys from two places, or live without unlinking: refused, nothing changes.
        $this->saveKeys('pk_live_abc123', self::SECRET)->assertSessionHasErrors('key');
        $this->saveKeys('pk_live_abc123', self::LIVE_SECRET)->assertSessionHasErrors('forget_customers');
        $this->assertSame('test', $this->settings()->mode());
        $this->assertSame('cus_sandbox', $organisation->fresh()->stripe_id);

        $this->saveKeys('pk_live_abc123', self::LIVE_SECRET, ['forget_customers' => '1'])->assertRedirect(route('admin.stripe.edit'));
        $this->assertSame('live', $this->settings()->mode());
        $this->assertNull($organisation->fresh()->stripe_id);
        $this->assertNull($organisation->fresh()->pm_last_four);
        $this->assertSame(0, DB::table('subscriptions')->count());
        $this->assertSame(0, DB::table('subscription_items')->count());
        $this->assertNull($this->settings()->source('webhook_secret'), 'The sandbox endpoint does not exist in live.');
        $this->assertTrue(DB::table('activity_log')->where('description', 'stripe settings changed')->where('properties', 'like', '%"customers_unlinked":1%')->exists());
    }

    public function test_the_page_never_renders_a_secret_and_only_a_super_admin_changes_it(): void
    {
        $this->saveKeys('pk_test_abc123', self::SECRET, ['webhook_secret' => 'whsec_pasted0123456789'])->assertRedirect();

        $html = $this->signIn($this->admin)->get(route('admin.stripe.edit'))->assertOk()
            ->assertSee(route('cashier.webhook'))->assertSee('…WXYZ')->assertSee(__('stripe_settings.status.mode_test'))
            ->assertSee(route('admin.plans.index'))->assertSee(route('admin.stripe.webhook'))->getContent();
        $this->assertStringNotContainsString(self::SECRET, $html);
        $this->assertStringNotContainsString('whsec_pasted0123456789', $html);

        // Staff read only, everyone else gets 404.
        $staff = $this->staff();
        $this->signIn($staff)->get(route('admin.stripe.edit'))->assertOk()->assertSee(__('stripe_settings.read_only'))->assertDontSee(__('stripe_settings.webhook.recreate'));
        $this->signIn($staff)->put(route('admin.stripe.update'), ['key' => 'pk_test_x'])->assertNotFound();
        $this->signIn($staff)->post(route('admin.stripe.webhook'))->assertNotFound();
        $this->signIn($this->makeUser())->get(route('admin.stripe.edit'))->assertNotFound();

        // Back to .env (empty here): the replaced config values are put back.
        $this->signIn($this->admin)->put(route('admin.stripe.update'), ['action' => 'reset'])->assertRedirect();
        $this->assertSame([], $this->settings()->stored());
        $this->assertNull($this->settings()->source('secret'));
        $this->assertSame('', config('cashier.secret'));
    }
}
