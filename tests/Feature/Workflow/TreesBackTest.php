<?php

namespace Tests\Feature\Workflow;

use App\Content\ContentPack;
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

    public function test_the_parts_tabs_keep_their_order_at_dt(): void
    {
        $this->signIn($this->owner());
        $pack = app(ContentPack::class);
        // A requirement whose tree several parts share (the test pack's, or the real one's).
        $standards = collect(array_keys($pack->standards()))->sort(SORT_NATURAL)->values();
        $requirement = collect(array_keys($pack->requirements()))
            ->first(fn ($r) => $standards->filter(fn ($s) => $pack->tree($s, $r) !== null)->count() > 1);
        if ($requirement === null) {
            $this->markTestSkipped('No tree shared across parts in this pack.');
        }
        $parts = $standards->filter(fn ($s) => $pack->tree($s, $requirement) !== null)->values()->all();

        // The same order on every part: only the highlight moves.
        foreach ($parts as $current) {
            $html = $this->get(route('trees.show', [$current, $requirement]))->assertOk()->getContent();
            $nav = substr($html, (int) strpos($html, 'class="a-seg"'));
            $positions = array_map(fn ($p) => strpos($nav, $pack->standardName($p)), $parts);
            $sorted = $positions;
            sort($sorted);
            $this->assertSame($sorted, $positions, 'Parts in the same order on '.$current);
            $this->assertStringContainsString('aria-current="page">'.$pack->standardName($current), $nav);
        }

        $this->assertStringStartsWith(url('/dt/'), route('trees.show', [$parts[0], $requirement]));
    }
}
