<?php

namespace Tests\Feature\Ai;

use App\Ai\AiClient;
use App\Ai\AiSettings;
use App\Ai\Availability;
use App\Ai\Feature;
use App\Ai\Quota;
use App\Billing\Plan;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Prism\Prism\Structured\Request as StructuredRequest;
use Tests\Feature\Admin\AdminTestHelpers;

/**
 * Bring your own key and model: provider, model, endpoint and key from .env or from the console's
 * AI settings (database first, key encrypted, never shown back), the connection test, and the AI
 * allowance of a self-hosted install (not limited by the SaaS plans) next to the SaaS (free = no AI).
 */
class AiSettingsTest extends AiTestCase
{
    use AdminTestHelpers;

    private const KEY = 'sk-test-0123456789abcdefWXYZ';

    private function settings(): AiSettings
    {
        return app(AiSettings::class);
    }

    public function test_the_provider_model_and_key_come_from_the_environment(): void
    {
        config(['redact.ai.provider' => 'openai', 'redact.ai.model' => '', 'prism.providers.openai.api_key' => self::KEY]);
        $client = app(AiClient::class);

        $this->assertSame('openai', $client->provider());
        $this->assertSame(['gpt-5-mini'], $client->models(), 'Empty model: the provider\'s suggested default.');
        $this->assertTrue($client->configured());

        // A model of another provider in REDACT_AI_MODEL is ignored, any other id is kept as typed.
        config(['redact.ai.model' => 'gemini-3.5-flash-lite']);
        $this->assertSame('gpt-5-mini', $this->settings()->model());
        config(['redact.ai.model' => 'gpt-5.5']);
        $this->assertSame('gpt-5.5', $this->settings()->model());

        // The call uses the openai driver, that model and key, and no temperature (refused by current models).
        $fake = $this->fakeAi([['improved' => 'Better.', 'changes' => []]]);
        $this->actingAs($this->owner)->postJson(route('ai.improve', $this->version), [
            'type' => 'product', 'key' => $this->product->id, 'field' => 'description', 'text' => 'A door lock with a radio.',
        ])->assertOk();
        $fake->assertProviderConfig(['api_key' => self::KEY]);
        $fake->assertRequest(function (array $requests) {
            $this->assertInstanceOf(StructuredRequest::class, $requests[0]);
            $this->assertSame('openai', $requests[0]->provider());
            $this->assertSame('gpt-5.5', $requests[0]->model());
            $this->assertNull($requests[0]->temperature());
        });
    }

    public function test_an_openai_compatible_api_needs_its_url_and_model(): void
    {
        config(['redact.ai.provider' => 'openai_compatible', 'redact.ai.model' => '', 'redact.ai.base_url' => '', 'redact.ai.api_key' => self::KEY]);
        $this->assertFalse(app(AiClient::class)->configured(), 'No URL, no model.');

        config(['redact.ai.base_url' => 'https://llm.example.test/v1/', 'redact.ai.model' => 'claude-sonnet-5-5']);
        $settings = $this->settings();
        $this->assertTrue($settings->configured());
        $this->assertSame('openrouter', $settings->driver(), 'The chat-completions driver.');
        $this->assertSame('claude-sonnet-5-5', $settings->model(), 'A gateway may serve any model id.');
        $this->assertSame('https://llm.example.test/v1', $settings->baseUrl());
        $this->assertSame(['api_key' => self::KEY, 'url' => 'https://llm.example.test/v1', 'site' => ['http_referer' => null, 'x_title' => null]], $settings->providerConfig());
        $this->assertTrue($settings->acceptsTemperature());
    }

    public function test_console_settings_override_the_environment_and_the_key_is_encrypted_at_rest(): void
    {
        $admin = $this->superAdmin();
        $this->signIn($admin)->put(route('admin.ai.update'), [
            'provider' => 'anthropic', 'model' => '', 'base_url' => '', 'api_key' => self::KEY,
        ])->assertRedirect(route('admin.ai.edit'));

        $settings = $this->settings();
        $this->assertSame('anthropic', $settings->provider(), 'Over REDACT_AI_PROVIDER=gemini.');
        $this->assertSame('claude-sonnet-5-5', $settings->model());
        $this->assertSame(self::KEY, $settings->apiKey());
        $this->assertSame('console', $settings->keySource());
        $this->assertSame('…WXYZ', $settings->keyHint());
        $this->assertFalse($settings->acceptsTemperature());

        // At rest: encrypted with the app key, never in clear (nor in the activity log).
        $raw = (string) DB::table('app_settings')->where('key', 'ai')->value('value');
        $this->assertStringNotContainsString(self::KEY, $raw);
        $this->assertSame(self::KEY, Crypt::decryptString(json_decode($raw, true)['api_key']));
        $this->assertStringNotContainsString(self::KEY, (string) DB::table('activity_log')->pluck('properties')->implode(' '));
        $this->assertTrue(DB::table('activity_log')->where('description', 'ai settings changed')->where('causer_id', $admin->id)->exists());

        // Saving again without a key keeps it; another provider without a key drops it.
        $this->signIn($admin)->put(route('admin.ai.update'), ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'api_key' => ''])->assertRedirect();
        $this->assertSame(self::KEY, $this->settings()->apiKey());
        $this->assertSame('claude-opus-5-5', $this->settings()->model());
        $this->signIn($admin)->put(route('admin.ai.update'), ['provider' => 'gemini', 'model' => ''])->assertRedirect();
        $this->assertSame('test-key', $this->settings()->apiKey(), 'Back to GEMINI_API_KEY from .env.');
        $this->assertSame('env', $this->settings()->keySource());

        // Reset: the .env values again.
        $this->signIn($admin)->put(route('admin.ai.update'), ['action' => 'reset'])->assertRedirect();
        $this->assertSame([], $this->settings()->stored());
        $this->assertSame('env', $this->settings()->source());
    }

    public function test_the_page_never_renders_the_key_and_only_staff_reach_it(): void
    {
        $admin = $this->superAdmin();
        $this->signIn($admin)->put(route('admin.ai.update'), ['provider' => 'openai', 'model' => 'gpt-6-luna', 'api_key' => self::KEY])->assertRedirect();

        $html = $this->signIn($admin)->get(route('admin.ai.edit'))->assertOk()
            ->assertSee(__('ai_settings.providers.openai'))->assertSee('gpt-6-luna')->assertSee('…WXYZ')
            ->assertSee(route('admin.ai.test'))->getContent();
        $this->assertStringNotContainsString(self::KEY, $html);
        $this->assertStringNotContainsString(substr(self::KEY, 0, 12), $html);

        // Staff read only (no form action works), everyone else gets 404.
        $staff = $this->staff();
        $this->signIn($staff)->get(route('admin.ai.edit'))->assertOk()->assertSee(__('ai_settings.read_only'))->assertDontSee(self::KEY);
        $this->signIn($staff)->put(route('admin.ai.update'), ['provider' => 'gemini'])->assertNotFound();
        $this->signIn($staff)->post(route('admin.ai.test'))->assertNotFound();
        $this->assertSame('openai', $this->settings()->provider());

        $this->signIn($this->owner)->get(route('admin.ai.edit'))->assertNotFound();
        $this->signIn($this->owner)->put(route('admin.ai.update'), ['provider' => 'gemini'])->assertNotFound();
        $this->signIn($this->owner)->post(route('admin.ai.test'))->assertNotFound();
    }

    public function test_the_form_is_validated(): void
    {
        $admin = $this->superAdmin();
        $this->signIn($admin)->put(route('admin.ai.update'), ['provider' => 'mystery'])->assertSessionHasErrors('provider');
        $this->signIn($admin)->put(route('admin.ai.update'), ['provider' => 'openai_compatible', 'model' => 'm1'])->assertSessionHasErrors('base_url');
        $this->signIn($admin)->put(route('admin.ai.update'), ['provider' => 'openai_compatible', 'base_url' => 'ftp://x.test'])->assertSessionHasErrors('base_url');
        $this->signIn($admin)->put(route('admin.ai.update'), ['provider' => 'openai_compatible', 'base_url' => 'https://llm.example.test/v1'])->assertSessionHasErrors('model');
        $this->signIn($admin)->put(route('admin.ai.update'), ['provider' => 'openai', 'model' => 'gpt 6"><script>'])->assertSessionHasErrors('model');
        $this->signIn($admin)->put(route('admin.ai.update'), ['provider' => 'openai', 'api_key' => 'two words'])->assertSessionHasErrors('api_key');
        $this->assertSame([], $this->settings()->stored());
    }

    public function test_test_connection_with_a_faked_provider(): void
    {
        $admin = $this->superAdmin();
        $this->signIn($admin)->put(route('admin.ai.update'), ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'api_key' => self::KEY])->assertRedirect();

        $fake = $this->fakeAi([['ok' => true]]);
        $this->signIn($admin)->post(route('admin.ai.test'))->assertRedirect(route('admin.ai.edit'));
        $this->signIn($admin)->get(route('admin.ai.edit'))->assertSee('Connected: claude-opus-5-5 answered in');
        $fake->assertCallCount(1);
        $fake->assertProviderConfig(['api_key' => self::KEY]);
        $this->assertSame(0, DB::table('ai_usage')->count(), 'A test is not a use of the AI.');
        $this->assertTrue(DB::table('activity_log')->where('description', 'ai connection tested')->exists());

        // An answer that is not the expected JSON is a failure, shown as such.
        $this->fakeAi([]);
        $this->signIn($admin)->post(route('admin.ai.test'));
        $this->signIn($admin)->get(route('admin.ai.edit'))->assertSee(__('ai_settings.test.failed', ['model' => 'claude-opus-5-5']));

        // Nothing configured: nothing is sent.
        $this->signIn($admin)->put(route('admin.ai.update'), ['provider' => 'openai', 'model' => ''])->assertRedirect();
        config(['prism.providers.openai.api_key' => '']);
        $fake = $this->fakeAi([['ok' => true]]);
        $this->signIn($admin)->post(route('admin.ai.test'));
        $this->signIn($admin)->get(route('admin.ai.edit'))->assertSee(__('ai_settings.test.not_configured'));
        $fake->assertCallCount(0);
    }

    public function test_a_self_hosted_install_without_billing_has_ai_for_everyone(): void
    {
        // Automatic: billing is not configured, so the install is self-hosted (its own AI key).
        config(['redact.self_hosted' => null, 'redact.plans.free.ai_units' => 0]);
        [$owner, $version] = $this->freeProduct();
        $plan = Plan::fresh($version->product->organisation);

        $this->assertTrue(Plan::selfHosted());
        $this->assertTrue($plan->aiAllowance()['own']);
        $this->assertTrue($plan->aiIncluded());
        $this->assertNull($plan->aiRefusal(1000));
        $this->assertNull(Availability::reason($owner, $version));
        $this->assertNull(Availability::upgradeUrl($owner, $version));
        app(Quota::class)->reserve($owner, $version, Feature::ImproveText, 1, 'gemini', 'test-model');

        // The per-person quotas still apply.
        config(['redact.ai.quota.per_day' => 1]);
        $this->assertSame(__('ai.quota.day', ['limit' => 1]), app(Quota::class)->check($owner, 1));

        // A staff AI override of an organisation still applies.
        $version->product->organisation->forceFill(['limits' => ['ai_units' => 0]])->save();
        $this->assertSame(__('plans.ai.paid_only'), Plan::fresh($version->product->organisation)->aiRefusal());

        // Without a key the reason is the configuration, never the plan.
        $version->product->organisation->forceFill(['limits' => null])->save();
        config(['prism.providers.gemini.api_key' => '']);
        $this->assertSame(__('ai.unavailable.not_configured'), Availability::reasonForOrganisation($owner, $version->product->organisation->fresh()));
    }

    public function test_the_saas_free_plan_still_has_no_ai(): void
    {
        config(['redact.self_hosted' => null, 'redact.plans.free.ai_units' => 0,
            'cashier.key' => 'pk_test_fake', 'cashier.secret' => 'sk_test_fake', 'redact.billing.price' => 'price_x']);
        [$owner, $version] = $this->freeProduct();

        $this->assertFalse(Plan::selfHosted(), 'Billing on: the cetome SaaS.');
        $this->assertFalse(Plan::fresh($version->product->organisation)->aiAllowance()['own']);
        $this->assertSame(__('plans.ai.paid_only'), Availability::reason($owner, $version));

        // Forced off even without billing (REDACT_SELF_HOSTED=false).
        config(['redact.self_hosted' => false, 'cashier.key' => '']);
        $this->assertSame(__('plans.ai.paid_only'), Plan::fresh($version->product->organisation)->aiRefusal());
    }

    public function test_the_pricing_page_offers_ai_with_your_own_key_in_open_source(): void
    {
        $this->get(route('pricing'))->assertOk()
            ->assertSee('AI with your own key or model (Claude, ChatGPT, Gemini, Mistral, any OpenAI-compatible)')
            ->assertSee('Your own key')
            ->assertSee('AI: cetome’s or your own model');
        $this->get(route('home'))->assertOk()->assertSee('AI with your own key or model (Claude, ChatGPT, Gemini, Mistral, any OpenAI-compatible)');
    }

    /** @return array{0: User, 1: ProductVersion} an owner and the free product of a new organisation (no AI limits of AiTestCase) */
    private function freeProduct(): array
    {
        $this->product->forceFill(['plan_slot' => Plan::SLOT_FREE])->save();
        $this->version->product->setRelation('organisation', $this->organisation->fresh());

        return [$this->owner, $this->version->fresh()->load('product.organisation')];
    }
}
