<?php

namespace Tests\Feature\Ai;

use App\Ai\Feature;
use App\Ai\Features\SuggestScoping;
use App\Ai\Provenance;
use App\Enums\Origin;
use App\Enums\ProductRole;
use App\Models\AiSuggestion;
use App\Models\AiUsage;
use App\Models\FieldOrigin;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Prism\Prism\Structured\Request as StructuredRequest;

/**
 * "Suggest with AI" on the scoping (New product and the scoping step).
 */
class AiScopingTest extends AiTestCase
{
    /** The model's answer: one known key true, one false, plus what must be dropped. */
    private function answer(): array
    {
        return ['answers' => [
            ['key' => 'connected', 'applies' => true, 'reason' => 'It is opened from a phone app over Wi-Fi.', 'confidence' => 'high'],
            ['key' => 'updates', 'applies' => false, 'reason' => 'The description does not mention updates.', 'confidence' => 'bogus'],
            ['key' => 'net_invented', 'applies' => true, 'reason' => 'Not a question of the pack.', 'confidence' => 'high'],
            ['key' => 'connected', 'applies' => false, 'reason' => 'A second answer for the same key.', 'confidence' => 'low'],
            ['key' => 'updates', 'applies' => 'yes', 'reason' => 'Not a boolean.', 'confidence' => 'high'],
        ]];
    }

    private function suggestNew(User $user, array $data = []): TestResponse
    {
        return $this->actingAs($user)->postJson(route('ai.scoping.new'), $data + [
            'organisation_id' => $this->organisation->id,
            'name' => 'Smart lock',
            'description' => 'A door lock opened from a phone app over Wi-Fi.',
        ]);
    }

    private function guest(): User
    {
        $guest = User::factory()->create();
        $guest->forceFill(['is_guest' => true])->save();
        $this->organisation->members()->attach($guest->id, ['role' => 'member']);

        return $guest;
    }

    public function test_the_scoping_step_suggests_known_questions_only(): void
    {
        $fake = $this->fakeAi([$this->answer()]);

        $response = $this->actingAs($this->owner)->postJson(route('ai.scoping', $this->version))->assertOk();

        $this->assertSame(['connected', 'updates'], array_keys($response->json('suggestions')));
        $this->assertTrue($response->json('suggestions.connected.applies'));
        $this->assertSame('It is opened from a phone app over Wi-Fi.', $response->json('suggestions.connected.reason'));
        $this->assertFalse($response->json('suggestions.updates.applies'));
        $this->assertSame('low', $response->json('suggestions.updates.confidence'));
        $this->assertSame(__('scoping.ai.confidence.high'), $response->json('suggestions.connected.confidence_label'));
        $this->assertSame(['DEMO-1 (sample standard)'], $response->json('standards'));
        $this->assertStringContainsString('Sample article A1', $response->json('articles.0'));
        $this->assertNotEmpty($response->json('token'));

        // The prompt carries the questions and the description, never the provider in the answer.
        $fake->assertRequest(function (array $requests) {
            /** @var StructuredRequest $request */
            $request = $requests[0];
            $this->assertStringContainsString('connected: The product connects to a network', $request->prompt());
            $this->assertStringContainsString('A door lock with a radio.', $request->prompt());
            $this->assertStringContainsString('Front doors of homes.', $request->prompt());
        });
        $this->assertStringNotContainsString('gemini', $response->getContent());

        // Logged like every AI call, and kept in the AI channel (nothing written to the answers).
        $usage = AiUsage::sole();
        $this->assertSame(Feature::SuggestScoping->value, $usage->feature);
        $this->assertSame(1, (int) $usage->units);
        $this->assertSame($this->version->id, $usage->product_version_id);
        $suggestion = AiSuggestion::sole();
        $this->assertSame('pending', $suggestion->status);
        $this->assertSame(Feature::SuggestScoping->value, $suggestion->feature);
        $this->assertSame(0, $this->version->scopingAnswers()->count());
    }

    public function test_applied_suggestions_are_marked_ai_generated_and_changed_ones_human_edited(): void
    {
        $this->fakeAi([$this->answer()]);
        $token = $this->actingAs($this->owner)->postJson(route('ai.scoping', $this->version))->json('token');

        // Applied, then the person ticked "updates" although the AI said no.
        $this->put(route('versions.scoping.update', $this->version), [
            'answers' => ['connected' => '1', 'updates' => '1'],
            'ai_scoping' => $token,
        ])->assertRedirect();

        $provenance = app(Provenance::class);
        $this->assertSame(Origin::AiGenerated, $provenance->origin(SuggestScoping::TARGET, $this->version->id, 'connected', 'true'));
        $this->assertSame(Origin::HumanEdited, $provenance->origin(SuggestScoping::TARGET, $this->version->id, 'updates', 'true'));
        $this->assertSame(['connected' => true, 'updates' => false], app(SuggestScoping::class)->recorded($this->version));
        $this->assertSame('accepted', AiSuggestion::sole()->status);
        $this->assertSame($this->owner->id, AiSuggestion::sole()->decided_by);

        // The page hands the recorded AI values to the cards.
        $this->get(route('versions.scoping', $this->version))->assertOk()
            ->assertSee('data-ai-recorded="{&quot;connected&quot;:true,&quot;updates&quot;:false}"', false);
    }

    public function test_answers_saved_without_applying_stay_human(): void
    {
        $this->fakeAi([$this->answer()]);
        $this->actingAs($this->owner)->postJson(route('ai.scoping', $this->version))->assertOk();

        $this->put(route('versions.scoping.update', $this->version), ['answers' => ['connected' => '1']])->assertRedirect();

        $this->assertSame(0, FieldOrigin::count());
        $this->assertSame(Origin::Human, app(Provenance::class)->origin(SuggestScoping::TARGET, $this->version->id, 'connected', 'true'));
        $this->assertSame('pending', AiSuggestion::sole()->status);
    }

    public function test_a_foreign_tampered_or_stale_token_records_nothing(): void
    {
        $this->fakeAi([$this->answer(), $this->answer()]);
        $token = $this->actingAs($this->owner)->postJson(route('ai.scoping', $this->version))->json('token');

        // Another version of the same owner.
        $other = ProductVersion::create(['product_id' => $this->product->id, 'label' => '2.0', 'status' => 'draft', 'created_by' => $this->owner->id]);
        $this->put(route('versions.scoping.update', $other), ['answers' => ['connected' => '1'], 'ai_scoping' => $token])->assertRedirect();
        // Tampered.
        $middle = intdiv(strlen($token), 2);
        $tampered = substr_replace($token, $token[$middle] === 'A' ? 'B' : 'A', $middle, 1);
        $this->put(route('versions.scoping.update', $this->version), ['answers' => ['connected' => '1'], 'ai_scoping' => $tampered])->assertRedirect();
        // Forged by hand (no valid signature) and a new-product token used on a version.
        $this->put(route('versions.scoping.update', $this->version), ['answers' => ['connected' => '1'], 'ai_scoping' => base64_encode('{"u":1}')])->assertRedirect();
        $newToken = $this->suggestNew($this->owner)->json('token');
        $this->put(route('versions.scoping.update', $this->version), ['answers' => ['connected' => '1'], 'ai_scoping' => $newToken])->assertRedirect();
        // Another person with edit rights on the same version.
        $contributor = $this->member(ProductRole::Contributor);
        $this->actingAs($contributor)->put(route('versions.scoping.update', $this->version), ['answers' => ['connected' => '1'], 'ai_scoping' => $token])->assertRedirect();
        $this->assertSame(0, FieldOrigin::count());

        // Stale: issued more than a day ago.
        $this->travel(SuggestScoping::MAX_AGE + 60)->seconds();
        $this->actingAs($this->owner)->put(route('versions.scoping.update', $this->version), ['answers' => ['connected' => '1'], 'ai_scoping' => $token])->assertRedirect();
        $this->assertSame(0, FieldOrigin::count());
    }

    public function test_a_token_only_keeps_questions_of_the_pack(): void
    {
        // Even a validly encrypted token cannot mark an unknown question.
        $token = Crypt::encryptString(json_encode([
            'u' => $this->owner->id, 'v' => $this->version->id, 'o' => null, 'sid' => null,
            'a' => ['connected' => ['applies' => true, 'reason' => 'r', 'confidence' => 'high'], 'net_invented' => ['applies' => true, 'reason' => 'r', 'confidence' => 'high']],
            'p' => 'gemini', 'm' => 'gemini-test-model', 't' => now()->getTimestamp(),
        ]));
        $this->actingAs($this->owner)->put(route('versions.scoping.update', $this->version), ['answers' => ['connected' => '1'], 'ai_scoping' => $token])->assertRedirect();

        $this->assertSame(['connected'], FieldOrigin::pluck('field')->all());
    }

    public function test_new_product_suggests_then_records_origins_on_the_created_version(): void
    {
        $fake = $this->fakeAi([$this->answer()]);

        $response = $this->suggestNew($this->owner)->assertOk();
        $this->assertSame(['connected', 'updates'], array_keys($response->json('suggestions')));
        $fake->assertRequest(fn (array $requests) => $this->assertStringContainsString('phone app over Wi-Fi', $requests[0]->prompt()));

        $usage = AiUsage::sole();
        $this->assertNull($usage->product_version_id);
        $this->assertSame($this->organisation->id, $usage->organisation_id);
        $this->assertSame(0, AiSuggestion::count());

        // Applied, then "connected" unticked by the person.
        $this->post(route('products.store'), [
            'organisation_id' => $this->organisation->id,
            'name' => 'Smart lock 2',
            'description' => 'A door lock opened from a phone app over Wi-Fi.',
            'answers' => ['connected' => '0', 'updates' => '0'],
            'ai_scoping' => $response->json('token'),
        ])->assertRedirect();

        $product = Product::where('name', 'Smart lock 2')->sole();
        $this->assertSame('A door lock opened from a phone app over Wi-Fi.', $product->description);
        $version = $product->versions()->sole();
        $provenance = app(Provenance::class);
        $this->assertSame(Origin::HumanEdited, $provenance->origin(SuggestScoping::TARGET, $version->id, 'connected', 'false'));
        $this->assertSame(Origin::AiGenerated, $provenance->origin(SuggestScoping::TARGET, $version->id, 'updates', 'false'));
        $suggestion = AiSuggestion::sole();
        $this->assertSame($version->id, $suggestion->product_version_id);
        $this->assertSame('accepted', $suggestion->status);
        $this->assertSame('gemini-test-model', $suggestion->model);
    }

    public function test_new_product_without_applying_records_nothing_and_a_token_of_another_organisation_is_ignored(): void
    {
        $this->fakeAi([$this->answer()]);
        $token = $this->suggestNew($this->owner)->json('token');
        $second = Organisation::create(['name' => 'Second', 'created_by' => $this->owner->id, 'ai_enabled' => true]);
        $second->members()->attach($this->owner->id, ['role' => 'member']);

        $this->post(route('products.store'), ['organisation_id' => $this->organisation->id, 'name' => 'Plain', 'answers' => ['connected' => '1']])->assertRedirect();
        $this->post(route('products.store'), ['organisation_id' => $second->id, 'name' => 'Elsewhere', 'answers' => ['connected' => '1'], 'ai_scoping' => $token])->assertRedirect();

        $this->assertSame(0, FieldOrigin::count());
        $this->assertSame(0, AiSuggestion::count());
    }

    public function test_new_product_needs_a_description_and_one_of_my_organisations(): void
    {
        $fake = $this->fakeAi([]);

        $this->suggestNew($this->owner, ['description' => '  '])->assertStatus(422)->assertJson(['message' => __('scoping.ai.empty_new')]);
        $stranger = Organisation::create(['name' => 'Other', 'created_by' => $this->owner->id, 'ai_enabled' => true]);
        $this->suggestNew($this->owner, ['organisation_id' => $stranger->id])->assertNotFound();

        $this->organisation->update(['ai_enabled' => false]);
        $this->suggestNew($this->owner)->assertStatus(503)->assertJson(['message' => __('ai.unavailable.organisation')]);

        $fake->assertCallCount(0);
        $this->assertSame(0, AiUsage::count());
    }

    public function test_guests_reviewers_and_outsiders_are_refused(): void
    {
        $fake = $this->fakeAi([]);

        $this->suggestNew($this->guest())->assertNotFound();
        $this->actingAs($this->member(ProductRole::Reviewer))->postJson(route('ai.scoping', $this->version))->assertNotFound();
        $this->actingAs(User::factory()->create())->postJson(route('ai.scoping', $this->version))->assertNotFound();

        $fake->assertCallCount(0);
        $this->assertSame(0, AiUsage::count());
    }

    public function test_the_allowance_is_respected(): void
    {
        config(['redact.ai.quota.per_day' => 0]);
        $fake = $this->fakeAi([]);

        $this->suggestNew($this->owner)->assertStatus(429);
        $this->actingAs($this->owner)->postJson(route('ai.scoping', $this->version))->assertStatus(429);

        $fake->assertCallCount(0);
    }

    public function test_without_a_key_the_ai_is_unavailable_and_the_pages_say_so(): void
    {
        config(['prism.providers.gemini.api_key' => '']);
        $fake = $this->fakeAi([]);

        $this->suggestNew($this->owner)->assertStatus(503)->assertJson(['message' => __('ai.unavailable.not_configured')]);
        $this->actingAs($this->owner)->postJson(route('ai.scoping', $this->version))->assertStatus(503);
        $fake->assertCallCount(0);

        $this->get(route('products.create'))->assertOk()
            ->assertSee(__('scoping.what'))->assertSee(__('ai.unavailable.short'))->assertDontSee(__('scoping.ai.button'));
        $this->get(route('versions.scoping', $this->version))->assertOk()
            ->assertSee(__('ai.unavailable.short'))->assertDontSee(__('scoping.ai.button'));
    }

    public function test_the_pages_offer_the_button_and_guests_get_one_line_instead(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('products.create'))->assertOk()
            ->assertSee(__('scoping.ai.button'))->assertSee(route('ai.scoping.new'))->assertSee('Uses 1 AI unit.')
            ->assertSee(__('scoping.ai.suggests_yes'));
        $this->get(route('versions.scoping', $this->version))->assertOk()
            ->assertSee(__('scoping.ai.button'))->assertSee(route('ai.scoping', $this->version));

        // A reviewer sees the cards (and their marks) but no AI button.
        $this->actingAs($this->member(ProductRole::Reviewer))->get(route('versions.scoping', $this->version))->assertOk()
            ->assertDontSee(__('scoping.ai.button'))->assertDontSee(__('ai.unavailable.short'));

        $this->actingAs($this->guest())->get(route('products.create'))->assertOk()
            ->assertDontSee(__('scoping.ai.button'))->assertSee(__('ai.unavailable.guest'));
    }

    public function test_the_scoping_step_needs_something_to_read(): void
    {
        $this->product->update(['description' => null]);
        $this->version->update(['intended_use' => null]);
        $fake = $this->fakeAi([]);

        $this->actingAs($this->owner)->postJson(route('ai.scoping', $this->version))
            ->assertStatus(422)->assertJson(['message' => __('scoping.ai.empty_version')]);
        $fake->assertCallCount(0);
        $this->assertSame(0, AiUsage::count());
    }
}
