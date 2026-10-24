<?php

namespace Tests\Feature\Ai;

use App\Ai\AiException;
use App\Ai\Availability;
use App\Ai\Feature;
use App\Ai\Quota;
use App\Enums\ProductRole;
use App\Models\AiSuggestion;
use App\Models\AiUsage;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class AiQuotaAndAccessTest extends AiTestCase
{
    private function improve(User $user)
    {
        return $this->actingAs($user)->postJson(route('ai.improve', $this->version), [
            'type' => 'product_version', 'key' => $this->version->id, 'field' => 'intended_use', 'text' => 'Front doors of homes.',
        ]);
    }

    public function test_a_call_records_its_usage_row(): void
    {
        $this->fakeAi([['improved' => 'Front doors of private homes.', 'changes' => ['More precise']]]);

        $this->improve($this->owner)->assertOk();

        $usage = AiUsage::sole();
        $this->assertSame('improve_text', $usage->feature);
        $this->assertSame(1, (int) $usage->units);
        $this->assertSame('ok', $usage->outcome);
        $this->assertSame(100, (int) $usage->tokens_in);
        $this->assertSame(50, (int) $usage->tokens_out);
        $this->assertSame($this->organisation->id, $usage->organisation_id);
    }

    public function test_the_quota_is_enforced_before_the_model_is_called(): void
    {
        config(['redact.ai.quota.per_day' => 2]);
        AiUsage::create(['user_id' => $this->owner->id, 'feature' => 'check', 'units' => 2, 'provider' => 'gemini', 'model' => 'x', 'outcome' => 'ok']);
        $fake = $this->fakeAi([['improved' => 'x', 'changes' => []]]);

        $this->improve($this->owner)->assertStatus(429)->assertJson(['message' => __('ai.quota.day', ['limit' => 2])]);

        $fake->assertCallCount(0);
        $this->assertSame(1, AiUsage::count());
        $this->assertSame(0, AiSuggestion::count());
    }

    public function test_the_burst_limit_and_oversized_actions_are_refused(): void
    {
        config(['redact.ai.quota.per_10_minutes' => 5]);
        $quota = app(Quota::class);

        $this->assertSame(__('ai.quota.too_big', ['units' => 12, 'cap' => 5]), $quota->check($this->owner, 12));
        AiUsage::create(['user_id' => $this->owner->id, 'feature' => 'check', 'units' => 5, 'provider' => 'gemini', 'model' => 'x', 'outcome' => 'ok']);
        $this->assertSame(__('ai.quota.burst', ['limit' => 5]), $quota->check($this->owner, 1));
    }

    public function test_the_quota_fails_closed_when_usage_cannot_be_counted(): void
    {
        $fake = $this->fakeAi([['improved' => 'x', 'changes' => []]]);
        Schema::drop('ai_usage');

        try {
            app(Quota::class)->reserve($this->owner, $this->version, Feature::ImproveText, 1, 'gemini', 'm');
            $this->fail('The reservation must fail closed.');
        } catch (AiException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame(__('ai.quota.unchecked'), $e->getMessage());
        }

        $this->improve($this->owner)->assertStatus(429);
        $fake->assertCallCount(0);
    }

    public function test_a_failed_call_is_recorded_and_says_nothing_about_the_provider(): void
    {
        config(['redact.ai.fallback_models' => ['gemini-second']]);
        // Two empty answers: both models "fail".
        $fake = $this->fakeAi([[], []]);

        $response = $this->improve($this->owner)->assertStatus(503);

        $fake->assertCallCount(2);
        $this->assertSame(__('ai.errors.unavailable'), $response->json('message'));
        $this->assertStringNotContainsStringIgnoringCase('gemini', $response->getContent());
        $this->assertSame('error', AiUsage::sole()->outcome);
    }

    public function test_reviewers_outsiders_and_non_draft_versions_cannot_use_the_ai(): void
    {
        $fake = $this->fakeAi([]);

        $this->improve($this->member(ProductRole::Reviewer))->assertNotFound();
        $this->improve(User::factory()->create())->assertNotFound();

        $this->version->update(['status' => 'in_review']);
        $this->improve($this->owner)->assertNotFound();
        $this->assertSame(__('ai.unavailable.not_draft'), Availability::reason($this->owner, $this->version->fresh()));

        $fake->assertCallCount(0);
        $this->assertSame(0, AiUsage::count());
    }

    public function test_an_organisation_that_opted_out_is_told_why(): void
    {
        $this->organisation->update(['ai_enabled' => false]);
        $this->fakeAi([]);

        $this->improve($this->owner)->assertNotFound();
        $this->assertSame(__('ai.unavailable.organisation'), Availability::reason($this->owner, $this->version->fresh()));

        $html = (string) $this->blade('<x-ai.assist :version="$version" type="product_version" :key="$version->id" field="intended_use" />', ['version' => $this->version->fresh()]);
        $this->assertStringNotContainsString(__('ai.improve.button'), $html);
        $this->assertStringContainsString(__('ai.unavailable.short'), $html);
    }

    public function test_the_assist_buttons_end_their_tooltip_with_the_units(): void
    {
        $this->actingAs($this->owner);
        $html = (string) $this->blade('<x-ai.assist :version="$version" type="product_version" :key="$version->id" field="intended_use" />', ['version' => $this->version]);

        $this->assertStringContainsString(__('ai.improve.button'), $html);
        $this->assertStringContainsString('Uses 1 AI unit.', $html);
        $this->assertStringContainsString('x-data="aiAssist"', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('style="', $html);
    }
}
