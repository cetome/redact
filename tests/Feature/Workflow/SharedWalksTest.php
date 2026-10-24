<?php

namespace Tests\Feature\Workflow;

use App\Assessment\SharedWalks;
use App\Assessment\VersionFactory;
use App\Content\ContentPack;
use App\Enums\Verdict;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Walks shared across the parts of a standard (SharedWalks) and the names of what is walked.
 *
 * A copy of the fixture pack with three invented parts:
 *  - ACC-1 in demo-1, demo-2 and demo-3. demo-2 has the tree of demo-1 with other wording, other
 *    result node ids, other branch order and upper-case labels: equivalent. demo-3 ends at DN-1
 *    (Yes → PASS, No → FAIL): not equivalent. In demo-2 only security assets are targets.
 *  - UPD-1 (the product) in demo-1 and demo-2 with the same tree.
 */
class SharedWalksTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private string $packPath;

    private User $owner;

    private ProductVersion $version;

    private InventoryItem $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packPath = $this->packWithParts();
        $this->app->instance(ContentPack::class, new ContentPack($this->packPath));

        $this->owner = $this->owner();
        $this->version = $this->product($this->owner);
        $this->version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $this->key = $this->version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Pairing key', 'attributes' => ['type' => 'security']]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packPath);
        parent::tearDown();
    }

    public function test_equivalent_trees_are_found_whatever_their_wording(): void
    {
        $classes = SharedWalks::classes(app(ContentPack::class));

        $this->assertSame($classes['ACC-1']['demo-1'], $classes['ACC-1']['demo-2']);
        $this->assertNotSame($classes['ACC-1']['demo-1'], $classes['ACC-1']['demo-3']);
        $this->assertSame($classes['UPD-1']['demo-1'], $classes['UPD-1']['demo-2']);
        $this->assertArrayNotHasKey('demo-3', $classes['UPD-1']);
    }

    public function test_a_save_fills_in_the_equivalent_parts_and_never_the_others(): void
    {
        $this->save('demo-1', 'ACC-1', $this->key->id, ['No', 'Yes'])
            ->assertSessionHas('status', __('evaluations.saved').' '.__('evaluations.shared.saved', ['parts' => 'DEMO-2 (sample standard)']));

        $one = $this->evaluation('demo-1', 'ACC-1', $this->key->id);
        $two = $this->evaluation('demo-2', 'ACC-1', $this->key->id);
        $this->assertNotNull($two, 'The equivalent part is created.');
        $this->assertSame('DN-2', $two->decision_node);
        $this->assertSame(Verdict::Pass, $two->verdict);
        // The path is walked again on demo-2's own tree (its labels).
        $this->assertSame([['node' => 'DN-1', 'answer' => 'NO'], ['node' => 'DN-2', 'answer' => 'YES']], $two->path);
        $this->assertSame($one->justification, $two->justification);
        $this->assertNotNull($one->shared_group);
        $this->assertSame($one->shared_group, $two->shared_group);
        $this->assertSame('demo-1', $two->shared_source);
        $this->assertNull($this->evaluation('demo-3', 'ACC-1', $this->key->id), 'A part with another tree is never filled in.');

        // Both stay in sync: a change made in demo-2 goes back to demo-1.
        $this->save('demo-2', 'ACC-1', $this->key->id, ['no', 'no'], 'Access is open to anyone on the local network.');
        $one->refresh();
        $this->assertSame(Verdict::Fail, $one->verdict);
        $this->assertSame([['node' => 'DN-1', 'answer' => 'No'], ['node' => 'DN-2', 'answer' => 'No']], $one->path);
        $this->assertSame('Access is open to anyone on the local network.', $one->justification);
        $this->assertSame('demo-2', $one->shared_source);
        $this->assertSame(2, Evaluation::count());
    }

    public function test_the_server_recomputes_the_outcome_in_every_part(): void
    {
        // The browser claims PASS at DN-1: the answers decide, in each part with its own tree.
        $this->signIn($this->owner)->put(route('versions.evaluate.update', [$this->version, 'demo-1', 'ACC-1']), [
            'target' => $this->key->id, 'answers' => ['No', 'No'], 'decision_node' => 'DN-1', 'verdict' => 'PASS',
            'justification' => 'Anyone can pair without any check.',
        ])->assertRedirect();

        foreach (['demo-1', 'demo-2'] as $part) {
            $evaluation = $this->evaluation($part, 'ACC-1', $this->key->id);
            $this->assertSame('DN-2', $evaluation->decision_node);
            $this->assertSame(Verdict::Fail, $evaluation->verdict);
        }
    }

    public function test_an_item_that_is_no_target_in_a_part_is_not_filled_in_there(): void
    {
        $data = $this->version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-02', 'name' => 'Usage log', 'attributes' => ['type' => 'data']]);

        $this->save('demo-1', 'ACC-1', $data->id, ['Yes'])->assertSessionHas('status', __('evaluations.saved'));

        $this->assertNull($this->evaluation('demo-2', 'ACC-1', $data->id));
        $this->assertNull($this->evaluation('demo-1', 'ACC-1', $data->id)->shared_group);
    }

    public function test_a_product_level_walk_is_shared_too(): void
    {
        $this->save('demo-1', 'UPD-1', 'product', ['Yes']);

        $two = $this->evaluation('demo-2', 'UPD-1', null);
        $this->assertSame('DN-1', $two->decision_node);
        $this->assertSame(Verdict::Pass, $two->verdict);
        $this->assertNull($this->evaluation('demo-3', 'UPD-1', null));
    }

    public function test_an_inconclusive_save_is_shared_with_the_path_so_far(): void
    {
        $this->signIn($this->owner)->put(route('versions.evaluate.update', [$this->version, 'demo-1', 'ACC-1']), [
            'target' => $this->key->id, 'inconclusive' => '1', 'partial' => ['No'],
            'justification' => 'Waiting for the supplier’s pairing documentation.',
        ])->assertRedirect();

        $two = $this->evaluation('demo-2', 'ACC-1', $this->key->id);
        $this->assertSame(Verdict::Inconclusive, $two->verdict);
        $this->assertSame('DN-1', $two->decision_node);
        $this->assertSame([['node' => 'DN-1', 'answer' => 'NO']], $two->path);
        $this->assertSame('Waiting for the supplier’s pairing documentation.', $two->justification);
    }

    public function test_a_part_decided_separately_is_left_alone_until_tied_again(): void
    {
        $this->save('demo-1', 'ACC-1', $this->key->id, ['No', 'Yes']);

        $this->signIn($this->owner)->post(route('versions.evaluate.untie', [$this->version, 'demo-2', 'ACC-1']), ['target' => $this->key->id])
            ->assertSessionHas('status', __('evaluations.shared.untied'));
        $two = $this->evaluation('demo-2', 'ACC-1', $this->key->id);
        $this->assertTrue($two->shared_untied);
        $this->assertNull($two->shared_group);

        // demo-1 changes: demo-2 keeps its own answer, and a save in demo-2 stays there.
        $this->save('demo-1', 'ACC-1', $this->key->id, ['No', 'No'])->assertSessionHas('status', __('evaluations.saved'));
        $this->assertSame(Verdict::Pass, $this->evaluation('demo-2', 'ACC-1', $this->key->id)->verdict);
        $this->save('demo-2', 'ACC-1', $this->key->id, ['Yes']);
        $this->assertSame(Verdict::Fail, $this->evaluation('demo-1', 'ACC-1', $this->key->id)->verdict);
        $this->assertSame(Verdict::NotApplicable, $this->evaluation('demo-2', 'ACC-1', $this->key->id)->verdict);

        $this->signIn($this->owner)->get($this->page('demo-2', $this->key->id))->assertOk()
            ->assertSee(__('evaluations.shared.retie'))
            ->assertDontSee(__('evaluations.shared.untie'));

        // Tied again: demo-2 takes demo-1's answer, and the next saves are shared again.
        $this->signIn($this->owner)->post(route('versions.evaluate.retie', [$this->version, 'demo-2', 'ACC-1']), ['target' => $this->key->id])
            ->assertSessionHas('status', __('evaluations.shared.tied', ['parts' => 'DEMO-1 (sample standard)']));
        $two = $this->evaluation('demo-2', 'ACC-1', $this->key->id);
        $this->assertFalse($two->shared_untied);
        $this->assertSame(Verdict::Fail, $two->verdict);
        $this->assertSame('DN-2', $two->decision_node);
        $this->assertSame($this->evaluation('demo-1', 'ACC-1', $this->key->id)->shared_group, $two->shared_group);

        $this->save('demo-1', 'ACC-1', $this->key->id, ['Yes']);
        $this->assertSame(Verdict::NotApplicable, $this->evaluation('demo-2', 'ACC-1', $this->key->id)->verdict);
    }

    public function test_one_activity_entry_per_save_names_the_parts_updated(): void
    {
        $this->save('demo-1', 'ACC-1', $this->key->id, ['No', 'Yes']);

        $one = $this->evaluation('demo-1', 'ACC-1', $this->key->id);
        $two = $this->evaluation('demo-2', 'ACC-1', $this->key->id);
        $entries = Activity::query()->where('log_name', 'evaluation')->get();
        $this->assertCount(1, $entries);
        $this->assertSame($one->id, (string) $entries[0]->subject_id);
        $this->assertSame('DEMO-2 (sample standard)', $entries[0]->getProperty('shared_with'));
        $this->assertSame(0, Activity::query()->where('subject_id', $two->id)->count());
    }

    public function test_the_pages_say_what_is_shared_and_name_what_is_walked(): void
    {
        $this->signIn($this->owner)->get($this->page('demo-1', $this->key->id))->assertOk()
            ->assertSee(trim(explode(':parts', __('evaluations.shared.will'))[0]))
            ->assertSee('DEMO-2 (sample standard)')
            ->assertDontSee('DEMO-3 (sample standard)</a>', false)
            ->assertSee('<span class="min-w-0 truncate" title="Assets">Assets</span>', false)
            ->assertDontSee('>Objects<', false);

        $this->save('demo-1', 'ACC-1', $this->key->id, ['No', 'Yes']);

        $this->signIn($this->owner)->get($this->page('demo-1', $this->key->id))->assertOk()
            ->assertSee(trim(explode(':parts', __('evaluations.shared.also'))[0]))
            ->assertSee(route('versions.evaluate.target', [$this->version, 'demo-2', 'ACC-1', $this->key->id]), false)
            ->assertSee(__('evaluations.shared.untie'));
        $this->signIn($this->owner)->get($this->page('demo-2', $this->key->id))->assertOk()
            ->assertSee(trim(explode(':parts', __('evaluations.shared.with'))[0]))
            ->assertSee('DEMO-1 (sample standard)</a>', false);

        // The requirements list: done in each part, the targets named, the shared badge.
        $this->signIn($this->owner)->get(route('versions.evaluate', [$this->version, 'demo-2']))->assertOk()
            ->assertSee(__('evaluations.applies_to'))
            ->assertSee('1 asset')
            ->assertSee(__('evaluations.shared.badge'))
            ->assertDontSee(__('evaluations.status.missing'));
        $this->signIn($this->owner)->get(route('versions.evaluate.requirement', [$this->version, 'demo-1', 'UPD-1']))->assertOk()
            ->assertSee('title="'.__('evaluations.product').'"', false);
    }

    public function test_a_version_copy_keeps_the_walks_shared(): void
    {
        $this->save('demo-1', 'ACC-1', $this->key->id, ['No', 'Yes']);
        $this->save('demo-1', 'UPD-1', 'product', ['Yes']);
        $old = $this->evaluation('demo-1', 'ACC-1', $this->key->id)->shared_group;

        $copy = app(VersionFactory::class)->create($this->version->product, '2.0', $this->owner, $this->version);
        $acc = $copy->evaluations()->where('requirement', 'ACC-1')->get();
        $upd = $copy->evaluations()->where('requirement', 'UPD-1')->get();

        $this->assertCount(2, $acc);
        $this->assertNotNull($acc[0]->shared_group);
        $this->assertSame($acc[0]->shared_group, $acc[1]->shared_group);
        $this->assertNotSame($old, $acc[0]->shared_group);
        $this->assertNotSame($acc[0]->shared_group, $upd[0]->shared_group);
        $this->assertSame($upd[0]->shared_group, $upd[1]->shared_group);

        // Still in sync in the new version.
        $item = $copy->inventoryItems()->firstOrFail();
        $this->signIn($this->owner)->put(route('versions.evaluate.update', [$copy, 'demo-2', 'ACC-1']), [
            'target' => $item->id, 'answers' => ['Yes'], 'justification' => 'The key is public by design in this version.',
        ])->assertRedirect();
        $this->assertSame(Verdict::NotApplicable, $copy->evaluations()->where('standard', 'demo-1')->where('requirement', 'ACC-1')->firstOrFail()->verdict);
    }

    /** @param  list<string>  $answers */
    private function save(string $standard, string $requirement, string $target, array $answers, string $justification = 'Only the paired owner app can open the lock.')
    {
        return $this->signIn($this->owner)->put(route('versions.evaluate.update', [$this->version, $standard, $requirement]), [
            'target' => $target, 'answers' => $answers, 'justification' => $justification,
        ])->assertSessionHasNoErrors();
    }

    private function evaluation(string $standard, string $requirement, ?string $itemId): ?Evaluation
    {
        return Evaluation::query()->where('product_version_id', $this->version->id)->where('standard', $standard)
            ->where('requirement', $requirement)->where('inventory_item_id', $itemId)->first();
    }

    private function page(string $standard, string $target): string
    {
        return route('versions.evaluate.target', [$this->version, $standard, 'ACC-1', $target]);
    }

    /** A copy of the fixture pack with three parts (see the class comment). */
    private function packWithParts(): string
    {
        $dir = storage_path('framework/testing/pack-'.uniqid());
        File::copyDirectory(base_path('tests/Fixtures/content-pack'), $dir);
        $json = fn (string $file) => json_decode((string) file_get_contents($dir.'/'.$file), true);
        $write = fn (string $file, array $data) => file_put_contents($dir.'/'.$file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $pack = $json('pack.json');
        $pack['standards'] = [
            ['code' => 'demo-1', 'name' => 'DEMO-1 (sample standard)', 'article' => 'A1'],
            ['code' => 'demo-2', 'name' => 'DEMO-2 (sample standard)', 'article' => 'A1'],
            ['code' => 'demo-3', 'name' => 'DEMO-3 (sample standard)', 'article' => 'A1'],
        ];
        $write('pack.json', $pack);
        $write('requirements.json', array_map(fn (array $r) => [...$r, 'standards' => $r['code'] === 'ACC-1' ? ['demo-1', 'demo-2', 'demo-3'] : ['demo-1', 'demo-2']], $json('requirements.json')));

        $acc = $json('trees/demo-1/ACC-1.json');
        $two = [...$acc, 'standard' => 'demo-2', 'nodes' => [
            ['id' => 'DN-1', 'kind' => 'decision', 'text' => 'Is it public on purpose?', 'branches' => [['label' => 'NO', 'to' => 'DN-2'], ['label' => 'YES', 'to' => 'R-NA-1']]],
            ['id' => 'DN-2', 'kind' => 'decision', 'text' => 'Is access checked?', 'branches' => [['label' => 'YES', 'to' => 'R-OK'], ['label' => 'NO', 'to' => 'R-KO']]],
            ['id' => 'R-NA-1', 'kind' => 'result', 'result' => 'NOT APPLICABLE'],
            ['id' => 'R-OK', 'kind' => 'result', 'result' => 'PASS'],
            ['id' => 'R-KO', 'kind' => 'result', 'result' => 'FAIL'],
        ]];
        $three = [...$acc, 'standard' => 'demo-3', 'nodes' => [
            ['id' => 'DN-1', 'kind' => 'decision', 'text' => 'Is access controlled?', 'branches' => [['label' => 'Yes', 'to' => 'R-PASS'], ['label' => 'No', 'to' => 'R-FAIL']]],
            ['id' => 'R-PASS', 'kind' => 'result', 'result' => 'PASS'],
            ['id' => 'R-FAIL', 'kind' => 'result', 'result' => 'FAIL'],
        ]];
        File::ensureDirectoryExists($dir.'/trees/demo-2');
        File::ensureDirectoryExists($dir.'/trees/demo-3');
        $write('trees/demo-2/ACC-1.json', $two);
        $write('trees/demo-3/ACC-1.json', $three);
        $write('trees/demo-2/UPD-1.json', [...$json('trees/demo-1/UPD-1.json'), 'standard' => 'demo-2']);

        file_put_contents($dir.'/rules.php', <<<'PHP'
            <?php

            use App\Content\GenericRules;
            use App\Content\VersionContext;

            // In demo-2, ACC-1 applies to security assets only.
            return new class extends GenericRules
            {
                public function evaluationTargets(string $standard, string $requirement, VersionContext $context): array
                {
                    $targets = parent::evaluationTargets($standard, $requirement, $context);

                    return $standard === 'demo-2' && $requirement === 'ACC-1'
                        ? array_values(array_filter($targets, fn ($item) => $item?->attr('type') === 'security'))
                        : $targets;
                }
            };
            PHP);

        return $dir;
    }
}
