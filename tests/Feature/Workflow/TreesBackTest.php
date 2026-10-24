<?php

namespace Tests\Feature\Workflow;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Opened from an assessment page, the trees pages offer the way back and keep it in their links;
 * the requirement page shows the tree once, as the evaluation itself (owner, 2026-10-23).
 */
class TreesBackTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_the_trees_pages_lead_back_to_the_assessment_page(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $back = route('versions.evaluate', [$version, 'demo-1']);

        // "View the trees" on the assessment page: the way back is kept in the session, not in the address.
        $this->signIn($owner)->get(route('versions.evaluate', [$version, 'demo-1']))->assertOk()
            ->assertSee('href="'.route('versions.evaluate.trees', [$version, 'demo-1']).'"', false);
        $this->get(route('versions.evaluate.trees', [$version, 'demo-1']))->assertRedirect(route('trees.standard', 'demo-1'));

        // Every trees page offers it while the person reads the trees, and its links stay clean.
        $this->get(route('trees.standard', 'demo-1'))->assertOk()
            ->assertSee('href="'.e($back).'"', false)->assertSee(__('trees.back'))
            ->assertSee('href="'.route('trees.show', ['demo-1', 'ACC-1']).'"', false);
        $this->get(route('trees.show', ['demo-1', 'ACC-1']))->assertOk()->assertSee(__('trees.back'))
            ->assertSee('href="'.route('trees.standard', 'demo-1').'"', false);
        $this->get(route('trees.index'))->assertOk()->assertSee(__('trees.back'));

        // Elsewhere, then the trees again from the top bar: no way back to offer.
        $this->get(route('dashboard'));
        $this->get(route('trees.index'))->assertOk()->assertDontSee(__('trees.back'));
    }

    public function test_only_a_version_page_someone_can_see_is_offered(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);

        $this->signIn($this->owner())->get(route('versions.evaluate.trees', [$version, 'demo-1']))->assertNotFound();
        $this->signIn($owner)->get(route('versions.evaluate.trees', [$version, 'nope']))->assertNotFound();
        // The legacy ?back= is ignored (and was never an open redirect).
        $this->get(route('trees.show', ['demo-1', 'ACC-1']).'?back='.urlencode('https://evil.example/x'))->assertOk()->assertDontSee(__('trees.back'));
    }

    public function test_the_requirement_page_shows_the_tree_once_as_the_evaluation(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Door key', 'attributes' => ['type' => 'security']]);

        $html = $this->signIn($owner)->get(route('versions.evaluate.requirement', [$version, 'demo-1', 'ACC-1']))->assertOk()
            ->assertSee('is-interactive', false)
            ->assertDontSee('a-walk-question', false)
            ->getContent();
        $this->assertSame(1, substr_count($html, 'data-tree-diagram'), 'One tree on the page: it is the evaluation.');
    }
}
