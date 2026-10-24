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
 * "Edit the decision tree" on the E.Info page: each requirement of the item shown, with the state of
 * its tree, opens that tree for the item in a modal (the evaluation page's form, evaluations/_tree-form);
 * saving comes back to the E.Info page on the item. Demo pack: ACC-1 places E.Info.ACC-1.Asset.
 */
class EinfoTreeModalTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_an_editor_gets_the_tree_of_each_requirement_of_the_item_in_a_modal(): void
    {
        [$owner, $version, $asset] = $this->walkedAsset();
        $evaluate = route('versions.evaluate.target', [$version, 'demo-1', 'ACC-1', $asset->id]);

        $this->signIn($owner)->get($this->einfo($version).'/'.$asset->id)->assertOk()
            // The requirement block: its tree's node and verdict, then the action (the evaluation page without script).
            ->assertSee('<span class="a-code">ACC-1</span> <span class="a-einfo-req-title">Sample access requirement</span>', false)
            ->assertSee('title="DT.ACC-1.DN-2">DN-2</span>', false)
            ->assertSee('href="'.e($evaluate).'"', false)
            ->assertSee('data-dialog-open="tree-ACC-1"', false)
            ->assertSee(__('einfo.trees.edit'))
            // The modal: the clickable tree of this item, posting to the evaluation, back here.
            ->assertSee('<dialog id="tree-ACC-1"', false)
            ->assertSee('id="tree-ACC-1-form"', false)
            ->assertSee('x-data="treeWalk"', false)
            ->assertSee('data-answers="[&quot;No&quot;,&quot;Yes&quot;]"', false)
            ->assertSee('id="tree-ACC-1-diagram-DN-1"', false)
            ->assertSee('name="return" value="'.e($this->einfo($version).'/'.$asset->id).'"', false)
            ->assertSee('form="tree-ACC-1-form"', false);
    }

    public function test_a_reviewer_sees_the_state_of_the_trees_without_the_action(): void
    {
        [, $version, $asset] = $this->walkedAsset();
        $reviewer = $this->member($version->product, ProductRole::Reviewer);

        $this->signIn($reviewer)->get($this->einfo($version).'/'.$asset->id)->assertOk()
            ->assertSee('title="DT.ACC-1.DN-2">DN-2</span>', false)
            ->assertDontSee('data-dialog-open="tree-ACC-1"', false)
            ->assertDontSee(__('einfo.trees.edit'))
            ->assertDontSee('<dialog id="tree-ACC-1"', false)
            ->assertDontSee('x-data="treeWalk"', false);
        $this->signIn($reviewer)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'answers' => ['Yes'], 'return' => $this->einfo($version),
        ])->assertNotFound();
    }

    public function test_saving_from_the_modal_comes_back_to_the_item_on_the_einfo_page(): void
    {
        [$owner, $version, $asset] = $this->walkedAsset();
        $einfo = $this->einfo($version);

        // The "missing only" filter is kept; the item is the one evaluated.
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'answers' => ['No', 'No'], 'justification' => 'Anyone nearby opens it.',
            'return' => $einfo.'/missing/product', 'tree' => 'ACC-1',
        ])->assertRedirect($einfo.'/missing/'.$asset->id)
            ->assertSessionHas('status', __('evaluations.saved'));

        $evaluation = Evaluation::query()->where('inventory_item_id', $asset->id)->sole();
        $this->assertSame(Verdict::Fail, $evaluation->verdict);
        $this->assertSame('DN-2', $evaluation->decision_node);

        // A relative address of this version's E.Info page, another part, works the same.
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'answers' => ['No', 'Yes'], 'return' => route('versions.einfo', [$version, 'demo-1'], false),
        ])->assertRedirect($einfo.'/'.$asset->id);

        // INCONCLUSIVE from the modal also comes back.
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'inconclusive' => '1', 'partial' => ['No'], 'justification' => 'Waiting for the lock maker\'s answer on access.',
            'return' => $einfo.'/'.$asset->id,
        ])->assertRedirect($einfo.'/'.$asset->id)->assertSessionHas('status', __('assessment.inconclusive_saved'));
    }

    public function test_the_return_address_is_only_this_versions_einfo_page(): void
    {
        [$owner, $version, $asset] = $this->walkedAsset();
        $other = $this->product($owner, '2.0');
        $evaluate = route('versions.evaluate.target', [$version, 'demo-1', 'ACC-1', $asset->id]);
        $path = route('versions.einfo', [$version, 'demo-1'], false);

        foreach ([
            'https://evil.example'.$path,
            '//evil.example'.$path,
            'http://127.0.0.2'.$path,
            'https://user@'.parse_url(url('/'), PHP_URL_HOST).$path,
            $this->einfo($version).'#x',
            $this->einfo($version).'?object=product',
            $this->einfo($version).'/elsewhere',
            $this->einfo($other),
            route('versions.evaluate', [$version, 'demo-1']),
            $path.'/../../evaluate/demo-1',
            '\\\\evil.example'.$path,
            'javascript:alert(1)',
            'https://evil.example/?'.$path,
        ] as $return) {
            $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
                'target' => $asset->id, 'answers' => ['No', 'Yes'], 'return' => $return,
            ])->assertRedirect($evaluate);
        }
    }

    public function test_a_refused_save_opens_the_modal_again_with_the_error(): void
    {
        [$owner, $version, $asset] = $this->walkedAsset();
        $page = $this->einfo($version).'/'.$asset->id;

        $this->signIn($owner)->from($page)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'inconclusive' => '1', 'partial' => ['No'], 'justification' => 'Too short',
            'return' => $page, 'tree' => 'ACC-1',
        ])->assertRedirect($page)->assertSessionHasErrors('justification');

        // The modal of that tree opens again (static without script), with the text typed.
        $html = $this->get($page)->assertOk()
            ->assertSee('>Too short</textarea>', false)
            ->getContent();
        $this->assertMatchesRegularExpression('/<dialog id="tree-ACC-1"[^>]*class="a-modal a-modal-wide is-static a-einfo-tree-modal"\s+open\s*>/', (string) $html);
    }

    /** @return array{0: User, 1: ProductVersion, 2: InventoryItem} */
    private function walkedAsset(): array
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $asset = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Front door key', 'attributes' => ['type' => 'security'], 'position' => 1]);
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'answers' => ['No', 'Yes'], 'justification' => 'Paired app.',
        ])->assertRedirect();

        return [$owner, $version, $asset];
    }

    private function einfo(ProductVersion $version): string
    {
        return route('versions.einfo', [$version, 'demo-1']);
    }
}
