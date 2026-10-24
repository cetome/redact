<?php

namespace Tests\Feature\Workflow;

use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * The decision tree walk page: the diagram beside the walk (server-rendered path, clickable
 * questions and answers for editors only), the keyboard hints, and the server staying the only
 * judge of the outcome whatever path the browser sends (a jump in the diagram is just answers).
 * Fixture pack: ACC-1 is DN-1 (Yes → NOT APPLICABLE, No → DN-2), DN-2 (Yes → PASS, No → FAIL).
 */
class TreeWalkPageTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_an_editor_gets_a_clickable_diagram_with_the_current_question_marked(): void
    {
        [$owner, $version, $first, $second] = $this->twoAssets();

        $this->signIn($owner)->get($this->page($version, $first))
            ->assertOk()
            ->assertSee('x-data="treeWalk"', false)
            ->assertSee('data-interactive="1"', false)
            ->assertSee('data-tree-diagram', false)
            ->assertSee('@click="pickBranch"', false)
            ->assertSee('@click="pickNode"', false)
            ->assertSee(__('evaluations.tree_hint'))
            // Nothing walked yet: the start node is the current question.
            ->assertSee('aria-current="step"', false)
            ->assertSee('data-shortcut="j"', false)
            ->assertDontSee('data-shortcut="k"', false)
            ->assertSee('data-shortcut="mod+s"', false)
            ->assertSee('data-shortcut="r"', false);

        $this->signIn($owner)->get($this->page($version, $second))
            ->assertOk()
            ->assertSee('data-shortcut="k"', false)
            ->assertDontSee('data-shortcut="j"', false);
    }

    public function test_a_saved_walk_is_drawn_on_the_diagram_with_the_outcome_reached(): void
    {
        [$owner, $version, $first] = $this->twoAssets();

        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $first->id, 'answers' => ['No', 'Yes'],
        ])->assertRedirect();

        $html = $this->signIn($owner)->get($this->page($version, $first))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-tree-node="DN-1" class="a-tree-node is-on-path is-start"/', $html);
        $this->assertMatchesRegularExpression('/data-tree-node="DN-2" class="a-tree-node is-on-path"/', $html);
        $this->assertMatchesRegularExpression('/class="a-tree-branch a-tree-pick is-taken"\s+data-tree-branch data-node="DN-1" data-label="No"/', $html);
        $this->assertMatchesRegularExpression('/class="a-tree-branch a-tree-pick is-taken is-reached"\s+data-tree-branch data-node="DN-2" data-label="Yes"[^>]*aria-pressed="true"/', $html);
        $this->assertStringNotContainsString('aria-current="step"', $html);
    }

    public function test_a_reviewer_sees_the_static_diagram_without_buttons_or_shortcuts(): void
    {
        [$owner, $version, $first] = $this->twoAssets();
        $reviewer = $this->member($version->product, ProductRole::Reviewer);

        $this->signIn($reviewer)->get($this->page($version, $first))
            ->assertOk()
            ->assertSee('data-tree-diagram', false)
            ->assertSee('data-interactive="0"', false)
            ->assertDontSee('@click="pickBranch"', false)
            ->assertDontSee(__('evaluations.tree_hint'))
            ->assertDontSee('aria-current="step"', false)
            ->assertDontSee('data-shortcut="mod+s"', false)
            ->assertDontSee('data-shortcut="r"', false)
            ->assertDontSee('aria-keyshortcuts', false)
            ->assertSee('href="#diagram-DN-2"', false);
    }

    public function test_the_tree_viewer_keeps_its_links_and_no_buttons(): void
    {
        $owner = $this->owner();

        $this->signIn($owner)->get(route('trees.show', ['demo-1', 'ACC-1']))
            ->assertOk()
            ->assertSee('href="#node-DN-2"', false)
            ->assertDontSee('a-tree-pick', false);
    }

    public function test_the_server_recomputes_the_outcome_of_a_jumped_path(): void
    {
        [$owner, $version, $first] = $this->twoAssets();
        $url = route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']);

        // A path ending on a question (the browser jumped there and did not answer) is refused.
        $this->signIn($owner)->put($url, ['target' => $first->id, 'answers' => ['No']])
            ->assertSessionHasErrors('answers');
        // An answer the node does not have is refused.
        $this->signIn($owner)->put($url, ['target' => $first->id, 'answers' => ['No', 'Maybe']])
            ->assertSessionHasErrors('answers');
        $this->assertSame(0, Evaluation::count());

        // The verdict and the last node come from the tree, never from the browser.
        $this->signIn($owner)->put($url, [
            'target' => $first->id, 'answers' => ['NO', 'no'], 'decision_node' => 'DN-1', 'verdict' => 'PASS',
        ])->assertRedirect();

        $evaluation = Evaluation::firstOrFail();
        $this->assertSame('DN-2', $evaluation->decision_node);
        $this->assertSame(Verdict::Fail, $evaluation->verdict);
        $this->assertSame([['node' => 'DN-1', 'answer' => 'No'], ['node' => 'DN-2', 'answer' => 'No']], $evaluation->path);
    }

    /** @return array{0: User, 1: ProductVersion, 2: InventoryItem, 3: InventoryItem} */
    private function twoAssets(): array
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $first = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'One', 'attributes' => ['type' => 'security']]);
        $second = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-02', 'name' => 'Two', 'attributes' => ['type' => 'security']]);

        return [$owner, $version, $first, $second];
    }

    private function page(ProductVersion $version, InventoryItem $item): string
    {
        return route('versions.evaluate.target', [$version, 'demo-1', 'ACC-1', $item->id]);
    }
}
