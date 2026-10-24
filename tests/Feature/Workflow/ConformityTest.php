<?php

namespace Tests\Feature\Workflow;

use App\Enums\ConformityRoute;
use App\Enums\ProductRole;
use App\Enums\VersionStatus;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Scoping first; at the end, a FAIL in the decision trees makes a Notified
 * Body mandatory: self-assessment (Module A) is refused.
 */
class ConformityTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_creating_a_product_scopes_it(): void
    {
        $owner = $this->owner();

        $this->signIn($owner)->post(route('products.store'), [
            'organisation_id' => $owner->organisations()->first()->id,
            'name' => 'Smart lock',
            'answers' => ['connected' => '1', 'updates' => '0'],
        ])->assertRedirect()->assertSessionHas('next');

        $version = ProductVersion::firstOrFail();
        $this->assertSame('1.0', $version->label);
        $this->assertTrue((bool) $version->scopingAnswers()->where('question', 'connected')->value('value'));
    }

    public function test_empty_dashboard_shows_the_scoping_form(): void
    {
        $owner = $this->owner();

        $this->signIn($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('name="answers[connected]"', false)
            ->assertSee(route('products.store'), false);
    }

    public function test_a_fail_makes_self_assessment_impossible(): void
    {
        [$owner, $version, $asset] = $this->scopedProduct();

        // DN-1 "No", DN-2 "No" → FAIL
        $this->walk($owner, $version, $asset, ['No', 'No']);
        $this->walkProduct($owner, $version, ['Yes']);

        $this->signIn($owner)->put(route('versions.conformity.update', $version), ['conformity_route' => 'module_a'])
            ->assertSessionHasErrors('conformity_route');
        $this->assertNull($version->fresh()->conformity_route);

        $this->signIn($owner)->put(route('versions.conformity.update', $version), [
            'conformity_route' => 'module_b_c', 'notified_body' => 'NB', 'notified_body_number' => '0123',
        ])->assertSessionHasNoErrors();
        $this->assertSame(ConformityRoute::ModuleBC, $version->fresh()->conformity_route);
        $this->signIn($owner)->get(route('versions.conformity', $version))->assertOk();
    }

    public function test_self_assessment_allowed_when_all_trees_pass_and_blocked_at_submission_after_a_new_fail(): void
    {
        [$owner, $version, $asset] = $this->scopedProduct();
        $this->walk($owner, $version, $asset, ['No', 'Yes']);
        $this->walkProduct($owner, $version, ['Yes']);

        $this->signIn($owner)->put(route('versions.conformity.update', $version), ['conformity_route' => 'module_a'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ConformityRoute::ModuleA, $version->fresh()->conformity_route);

        // A FAIL appears afterwards: the chosen route now conflicts and submission is refused.
        $this->walk($owner, $version, $asset, ['No', 'No']);
        $this->member($version->product, ProductRole::Reviewer); // a review needs a reviewer
        $this->signIn($owner)->post(route('versions.submit', $version))->assertRedirect(route('versions.conformity', $version));
        $this->assertSame(VersionStatus::Draft, $version->fresh()->status);
    }

    public function test_scoping_step_keeps_the_conformity_answers(): void
    {
        [$owner, $version] = $this->scopedProduct();
        $version->scopingAnswers()->create(['question' => 'r_passwords', 'value' => true]);

        $this->signIn($owner)->put(route('versions.scoping.update', $version), ['answers' => ['connected' => '1']])
            ->assertRedirect();

        // r_passwords is not a question of the fixture pack: unknown keys of other steps are left alone.
        $this->assertTrue($version->scopingAnswers()->where('question', 'r_passwords')->exists());
    }

    /** @return array{0: User, 1: ProductVersion, 2: InventoryItem} */
    private function scopedProduct(): array
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $asset = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'attributes' => ['type' => 'security']]);

        return [$owner, $version, $asset];
    }

    /** @param list<string> $answers */
    private function walk(User $owner, ProductVersion $version, $asset, array $answers): void
    {
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'answers' => $answers, 'justification' => 'Because.',
        ])->assertSessionHasNoErrors();
    }

    /** @param list<string> $answers */
    private function walkProduct(User $owner, ProductVersion $version, array $answers): void
    {
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'UPD-1']), [
            'target' => 'product', 'answers' => $answers, 'justification' => 'Because.',
        ])->assertSessionHasNoErrors();
    }
}
