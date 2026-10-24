<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\InventoryGuide;
use App\Assessment\VersionFactory;
use App\Content\ContentPack;
use App\Enums\ProductRole;
use App\Models\ProductVersion;
use App\Models\User;
use App\Support\WorkflowSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * "Assets & interfaces" as a guided sequence: the lists of the working standards in pack order,
 * then the mappings, then the workflow's next step; "Add and continue" / "Skip" in the picker;
 * "This product has none" per list (reversible, lifted by an added object, copied to new versions);
 * progress; the asset type cards. Runs on the fixture pack plus an invented second standard with a
 * list of its own ("Logs", used only by DEMO-2) and a catalogue for the interfaces.
 */
class InventoryGuideTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private string $packPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packPath = $this->guidedPack();
        $this->app->instance(ContentPack::class, new ContentPack($this->packPath));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packPath);
        parent::tearDown();
    }

    public function test_the_sequence_follows_the_pack_order_and_the_working_standards(): void
    {
        $version = $this->product($this->owner());

        // Scoping not answered: every standard is worked on, so the DEMO-2 list is in.
        $this->assertSame(['assets', 'interfaces', 'logs'], $this->guide($version)->listKeys());
        // The mappings are not a step of the sequence (a separate button).
        $this->assertSame(['assets', 'interfaces', 'logs'], array_column($this->guide($version)->steps(), 'key'));

        // Only DEMO-1 in scope: the list only DEMO-2 uses leaves the sequence…
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $this->assertSame(['assets', 'interfaces'], $this->guide($version)->listKeys());

        // …unless it already holds objects (nothing entered is ever hidden).
        $version->inventoryItems()->create(['list' => 'logs', 'code' => 'LG-01', 'name' => 'Boot log']);
        $this->assertSame(['assets', 'interfaces', 'logs'], $this->guide($version)->listKeys());
    }

    public function test_previous_and_next_lead_through_the_lists_then_the_next_step_and_mappings_stay_aside(): void
    {
        $version = $this->product($this->owner());
        $guide = $this->guide($version);

        $this->assertNull($guide->previous('assets'));
        $this->assertSame('interfaces', $guide->next('assets')['key']);
        $this->assertSame('assets', $guide->previous('interfaces')['key']);
        $this->assertNull($guide->next('logs'));

        // After the last list: whatever step follows "Assets & interfaces" in the workflow.
        $after = Assessment::for($version)->nextStepAfter('inventory');
        $this->assertNotNull($after);
        $this->assertSame($after['href'], $guide->continueUrl('logs'));
        $this->assertSame(['label' => $after['label'], 'href' => $after['href']], $guide->continueTarget('logs'));

        // The mappings: a separate page that goes back to the list it was opened from.
        $this->assertSame(route('versions.inventory.mappings', [$version, 'interfaces']), $guide->mappings('interfaces')['href']);
        $this->assertSame('assets', $guide->mappings()['back']);
        $this->assertSame(0, $guide->mappings()['count']);

        // The pages: the stepper, Previous (p) and Next (n).
        $owner = $version->product->owner;
        $this->signIn($owner)->get(route('versions.inventory', [$version, 'interfaces']))->assertOk()
            ->assertSee('a-guide-step', false)
            ->assertSee('data-shortcut="p"', false)->assertSee('data-shortcut="n"', false)
            ->assertSee(route('versions.inventory', [$version, 'assets']), false)
            ->assertSee(route('versions.inventory', [$version, 'logs']), false)
            ->assertSee(__('inventory.none_action'))
            ->assertSee(__('inventory.skip_to', ['step' => 'Logs']))
            ->assertSee(__('inventory.mappings_help'))
            ->assertSee(route('versions.inventory.mappings', [$version, 'interfaces']), false);
        $this->signIn($owner)->get(route('versions.inventory', [$version, 'logs']))->assertOk()
            ->assertSee(__('inventory.skip_to', ['step' => $after['label']]));

        // With objects, the bar offers the list's mappings ("Map to interfaces...").
        $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Key', 'attributes' => ['type' => 'security']]);
        $this->signIn($owner)->get(route('versions.inventory', [$version, 'assets']))->assertOk()
            ->assertSee(__('inventory.map_to', ['list' => 'interfaces']))
            ->assertSee(route('versions.inventory.mappings.relation', [$version, 'assets', 'asset_interface']), false);

        // The mappings page: back to the list it came from, then the workflow's next step.
        $page = $this->signIn($owner)->get(route('versions.inventory.mappings', [$version, 'interfaces']))->assertOk();
        $this->assertMatchesRegularExpression('/a-guide-map[^>]*aria-current="page"/s', (string) $page->getContent());
        $page->assertSee(__('inventory.back_to', ['list' => 'Interfaces']))
            ->assertSee(__('inventory.next_step', ['step' => $after['label']]))
            ->assertSee($after['href'], false);
    }

    public function test_add_and_continue_goes_to_the_next_lists_picker_and_skip_goes_there_too(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);

        $this->signIn($owner)->get(route('versions.inventory.pick', [$version, 'assets']))->assertOk()
            ->assertSee(__('inventory.add_continue', ['step' => 'Interfaces']))
            ->assertSee(__('inventory.skip_to', ['step' => 'Interfaces']));

        // Ticked: added, then straight to the interfaces' picker (still empty, has a catalogue).
        $this->signIn($owner)->post(route('versions.inventory.store', [$version, 'assets']), ['picker' => '1', 'catalogue' => [0, 1], 'continue' => '1'])
            ->assertRedirect(route('versions.inventory.pick', [$version, 'interfaces']))
            ->assertSessionHas('status', trans_choice('inventory.added_many', 2, ['count' => 2]));
        $this->assertSame(2, $version->inventoryItems()->where('list', 'assets')->count());

        // Nothing ticked: skip to the next list (logs: empty, with a catalogue, so its picker).
        $this->signIn($owner)->post(route('versions.inventory.store', [$version, 'interfaces']), ['picker' => '1', 'continue' => '1'])
            ->assertRedirect(route('versions.inventory.pick', [$version, 'logs']));
        $this->assertSame(0, $version->inventoryItems()->where('list', 'interfaces')->count());

        // "Add selected" with nothing ticked stays, with a word.
        $this->signIn($owner)->from(route('versions.inventory.pick', [$version, 'interfaces']))
            ->post(route('versions.inventory.store', [$version, 'interfaces']), ['picker' => '1'])
            ->assertRedirect(route('versions.inventory.pick', [$version, 'interfaces']))
            ->assertSessionHas('error', __('inventory.pick_nothing'));

        // "Add selected" keeps today's behaviour; "Add as one group" too.
        $this->signIn($owner)->post(route('versions.inventory.store', [$version, 'interfaces']), ['picker' => '1', 'catalogue' => [0]])
            ->assertRedirect(route('versions.inventory', [$version, 'interfaces']));
        $this->signIn($owner)->post(route('versions.inventory.store', [$version, 'assets']), ['picker' => '1', 'catalogue' => [2, 3], 'as_group' => '1'])
            ->assertRedirect(route('versions.inventory', [$version, 'assets']));
        $this->assertTrue($version->inventoryItems()->where('list', 'assets')->get()->contains(fn ($i) => $i->isGroup()));
    }

    public function test_none_is_marked_logged_reversible_and_lifted_by_an_added_object(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);

        $this->signIn($owner)->get(route('versions.inventory', [$version, 'logs']))->assertOk()->assertSee(__('inventory.none_action'));

        // Marked: on to what follows (the last list: the workflow's next step), logged.
        $this->signIn($owner)->post(route('versions.inventory.none', [$version, 'logs']))
            ->assertRedirect(Assessment::for($version)->nextStepAfter('inventory')['href'])
            ->assertSessionHas('status', __('inventory.none_marked', ['list' => 'logs']));
        $this->assertSame(['logs'], $version->fresh()->noneLists());
        $this->assertTrue(Activity::query()->where('description', 'marked none')->where('subject_id', $version->id)->exists());
        $step = $this->guide($version->fresh())->step('logs');
        $this->assertTrue($step['none'] && $step['done']);

        $this->signIn($owner)->get(route('versions.inventory', [$version, 'logs']))->assertOk()
            ->assertSee(__('inventory.none_title', ['list' => 'logs']))->assertSee(__('inventory.none_undo'));

        // Undo.
        $this->signIn($owner)->delete(route('versions.inventory.none.undo', [$version, 'logs']))
            ->assertRedirect(route('versions.inventory', [$version, 'logs']));
        $this->assertSame([], $version->fresh()->noneLists());
        $this->assertTrue(Activity::query()->where('description', 'none undone')->exists());

        // Marked again, then an object is added by hand: the mark goes.
        $this->signIn($owner)->post(route('versions.inventory.none', [$version, 'logs']));
        $this->signIn($owner)->post(route('versions.inventory.store', [$version, 'logs']), ['code' => 'LG-01', 'name' => 'Boot log'])->assertRedirect();
        $this->assertSame([], $version->fresh()->noneLists());
        $this->assertTrue(Activity::query()->where('description', 'none cleared')->exists());

        // A list with objects cannot be marked none.
        $this->signIn($owner)->post(route('versions.inventory.none', [$version, 'logs']))
            ->assertSessionHas('error', __('inventory.none_has_items'));
        $this->assertSame([], $version->fresh()->noneLists());
    }

    public function test_progress_counts_lists_with_objects_or_marked_none(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $inventory = fn () => collect(WorkflowSummary::for($version->fresh())->groups)->firstWhere('key', 'inventory');

        $this->assertSame([0, 3, false], [$inventory()['doneCount'], $inventory()['total'], $inventory()['done']]);

        $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Key', 'attributes' => ['type' => 'security']]);
        $version->inventoryItems()->create(['list' => 'interfaces', 'code' => 'IF-01', 'name' => 'Wi-Fi']);
        request()->attributes->remove('redact.workflow.'.$version->id);
        $this->assertSame([2, 3, false], [$inventory()['doneCount'], $inventory()['total'], $inventory()['done']]);
        $this->assertSame(['assets', 'interfaces', 'logs'], array_column($inventory()['lists'], 'key'));

        $this->signIn($owner)->post(route('versions.inventory.none', [$version, 'logs']));
        request()->attributes->remove('redact.workflow.'.$version->id);
        $this->assertSame([3, 3, true], [$inventory()['doneCount'], $inventory()['total'], $inventory()['done']]);
        $step = collect(Assessment::for($version->fresh())->steps())->firstWhere('key', 'inventory');
        $this->assertTrue($step['done']);

        // The sidebar shows one line per list while on the step.
        $this->signIn($owner)->get(route('versions.inventory', [$version, 'assets']))->assertOk()
            ->assertSee('a-guide-substeps', false)->assertSee(__('inventory.none_short'));
    }

    public function test_a_new_version_keeps_the_lists_marked_none(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $this->signIn($owner)->post(route('versions.inventory.none', [$version, 'logs']));

        $next = app(VersionFactory::class)->create($version->product, '2.0', $owner, $version->fresh());

        $this->assertSame(['logs'], $next->fresh()->noneLists());
    }

    public function test_only_editors_mark_none_and_strangers_see_nothing(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $reviewer = $this->member($version->product, ProductRole::Reviewer);

        $this->signIn($reviewer)->get(route('versions.inventory', [$version, 'logs']))->assertOk()
            ->assertDontSee(__('inventory.none_action'))->assertSee('a-guide-step', false);
        $this->signIn($reviewer)->post(route('versions.inventory.none', [$version, 'logs']))->assertNotFound();
        $this->signIn($reviewer)->delete(route('versions.inventory.none.undo', [$version, 'logs']))->assertNotFound();

        $stranger = User::factory()->create();
        $this->signIn($stranger)->get(route('versions.inventory', [$version, 'assets']))->assertNotFound();
        $this->signIn($stranger)->post(route('versions.inventory.none', [$version, 'logs']))->assertNotFound();
        $this->signIn($owner)->post(route('versions.inventory.none', [$version, 'nope']))->assertNotFound();
        $this->assertSame([], $version->fresh()->noneLists());
    }

    public function test_asset_types_are_cards_greyed_out_of_scope_and_untyped_assets_are_flagged(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Key', 'attributes' => ['type' => 'security']]);
        $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-02', 'name' => 'Untyped', 'attributes' => []]);

        $this->signIn($owner)->get(route('versions.inventory.new', [$version, 'assets']))->assertOk()
            ->assertSee('a-type-card', false)
            ->assertSee('Keeps the device safe')
            ->assertSee(__('inventory.type_parts', ['parts' => 'DEMO-1 and -2']))
            ->assertSee(trans_choice('inventory.type_out', 1, ['parts' => 'DEMO-2']))
            ->assertSee(__('inventory.type_to_decide'))
            ->assertSee('data-facet="_none"', false)
            ->assertSee(trans_choice('inventory.untyped', 1, ['count' => 1, 'item' => 'asset', 'items' => 'assets']));
    }

    public function test_prefill_everything_adds_the_items_linked_to_the_answers_once(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        foreach (['connected', 'updates', 'logging'] as $question) {
            $version->scopingAnswers()->create(['question' => $question, 'value' => true]);
        }

        // Linked to a yes answer: 2 assets, 1 interface, 1 log.
        $plan = $this->guide($version)->prefillPlan();
        $this->assertSame(4, $plan['total']);
        $this->assertEqualsCanonicalizing(['Sample update data', 'Sample log'], array_column($plan['lists']['assets']['rows'], 'name'));

        // Already in the inventory (same name rule as the pickers): left out. Marked none: skipped.
        $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Sample log']);
        $this->signIn($owner)->post(route('versions.inventory.none', [$version, 'logs']));
        $plan = $this->guide($version->fresh())->prefillPlan();
        $this->assertSame(2, $plan['total']);
        $this->assertSame(['Logs'], $plan['skipped']);

        $this->signIn($owner)->get(route('versions.inventory.prefill.show', [$version, 'assets']))->assertOk()
            ->assertSee(__('inventory.prefill_button'))->assertSee(__('inventory.prefill_title'))
            ->assertSee('Sample update data')->assertSee(trans_choice('inventory.prefill_submit', 2, ['count' => 2]))
            ->assertSee(__('inventory.prefill_skipped', ['lists' => 'logs']));

        $this->signIn($owner)->post(route('versions.inventory.prefill', $version), ['items' => $this->proposalKeys($plan)])
            ->assertRedirect(route('versions.inventory', [$version, 'assets']))
            ->assertSessionHas('status', trans_choice('inventory.prefill_done', 2, ['count' => 2, 'lists' => trans_choice('inventory.prefill_lists', 2, ['count' => 2])])
                .' '.__('inventory.prefill_skipped', ['lists' => 'logs']));
        $this->assertSame(['Sample log', 'Sample update data'], $version->inventoryItems()->where('list', 'assets')->pluck('name')->all());
        $this->assertSame(['Sample radio'], $version->inventoryItems()->where('list', 'interfaces')->pluck('name')->all());
        $this->assertSame(0, $version->inventoryItems()->where('list', 'logs')->count());
        $log = Activity::query()->where('description', 'prefilled')->sole();
        $this->assertSame(['assets' => 1, 'interfaces' => 1], $log->properties['added']);

        // A second click adds nothing; the button is then disabled.
        $this->signIn($owner)->post(route('versions.inventory.prefill', $version), ['items' => $this->proposalKeys($plan)])
            ->assertSessionHas('status', __('inventory.prefill_nothing'));
        $this->assertSame(3, $version->inventoryItems()->count());
        $this->signIn($owner)->get(route('versions.inventory', [$version, 'assets']))->assertOk()
            ->assertSee(__('inventory.prefill_nothing'))->assertDontSee(__('inventory.prefill_title'));
    }

    public function test_prefill_everything_adds_only_the_items_kept_chosen(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        foreach (['connected', 'updates', 'logging'] as $question) {
            $version->scopingAnswers()->create(['question' => $question, 'value' => true]);
        }
        $plan = $this->guide($version)->prefillPlan();
        $keys = $this->proposalKeys($plan);
        $this->assertCount(4, $keys);

        // Every proposal is a chip, chosen by default, with Select all / None and "n of N" per list.
        $page = $this->signIn($owner)->get(route('versions.inventory.prefill.show', [$version, 'assets']))->assertOk();
        foreach ($keys as $key) {
            $page->assertSee('name="items[]" value="'.$key.'" checked', false);
        }
        $page->assertSee('data-prefill-toggle', false)
            ->assertSee(__('inventory.prefill_count', ['n' => 2, 'total' => 2]))
            ->assertSee('data-many="'.trans_choice('inventory.prefill_submit', 2, ['count' => ':count']).'"', false);

        // Nothing chosen: nothing added. Something that is not a proposal: refused, nothing added.
        $this->post(route('versions.inventory.prefill', $version), ['items' => []])->assertSessionHas('error', __('inventory.prefill_nothing_chosen'));
        $this->post(route('versions.inventory.prefill', $version), ['items' => [$keys[0], 'assets:999']])->assertSessionHasErrors('items.1');
        $this->assertSame(0, $version->inventoryItems()->count());

        // One item of the assets kept: only that one is added, the other proposals stay proposed.
        $assetKeys = array_values(array_filter($keys, fn (string $k) => str_starts_with($k, 'assets:')));
        $this->post(route('versions.inventory.prefill', $version), ['items' => [$assetKeys[0]]])->assertSessionHasNoErrors()
            ->assertSessionHas('status', trans_choice('inventory.prefill_done', 1, ['count' => 1, 'lists' => trans_choice('inventory.prefill_lists', 1, ['count' => 1])]));
        $this->assertSame(1, $version->inventoryItems()->count());
        $this->assertSame(3, $this->guide($version->fresh())->prefillPlan()['total']);
    }

    public function test_the_empty_list_offers_prefill_next_to_the_catalogue_and_the_tools_have_one_place(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'updates', 'value' => true]);

        // Empty list with proposals left: "Prefill everything" next to "Pick from catalogue", same dialog.
        $html = $this->signIn($owner)->get(route('versions.inventory', [$version, 'assets']))->assertOk()->getContent();
        $empty = substr($html, (int) strpos($html, 'a-guide-empty'));
        $this->assertMatchesRegularExpression('#'.preg_quote(__('inventory.from_catalogue'), '#').'</a>\s*.*?data-dialog-open="prefill-all" data-empty-prefill#s', $empty);
        $this->assertSame(1, substr_count($html, '<dialog id="prefill-all"'));

        // The tools (Prefill everything, then Mappings) sit once, in the stepper row, on every list page.
        foreach (['assets', 'interfaces', 'logs'] as $list) {
            $page = $this->get(route('versions.inventory', [$version, $list]))->assertOk()->getContent();
            $this->assertSame(1, substr_count($page, 'class="a-guide-tools"'), $list);
            $this->assertMatchesRegularExpression('#class="a-guide-row">.*?class="a-guide-tools">.*?data-dialog-open="prefill-all".*?a-guide-map#s', $page, $list);
        }

        // Nothing left to propose: no button in the empty state.
        $this->post(route('versions.inventory.prefill', $version), ['items' => $this->proposalKeys($this->guide($version)->prefillPlan())]);
        $this->get(route('versions.inventory', [$version, 'interfaces']))->assertOk()->assertDontSee('data-empty-prefill', false);
    }

    /** @param  array{lists: array<string, array{rows: list<array<string, mixed>>}>}  $plan */
    private function proposalKeys(array $plan): array
    {
        $keys = [];
        foreach ($plan['lists'] as $list => $entry) {
            foreach ($entry['rows'] as $row) {
                $keys[] = $list.':'.$row['index'];
            }
        }

        return $keys;
    }

    public function test_only_editors_prefill(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'updates', 'value' => true]);
        $reviewer = $this->member($version->product, ProductRole::Reviewer);

        $this->signIn($reviewer)->get(route('versions.inventory', [$version, 'assets']))->assertOk()->assertDontSee(__('inventory.prefill_button'));
        $this->signIn($reviewer)->post(route('versions.inventory.prefill', $version))->assertNotFound();
        $this->signIn(User::factory()->create())->post(route('versions.inventory.prefill', $version))->assertNotFound();
        $this->assertSame(0, $version->inventoryItems()->count());
    }

    private function guide(ProductVersion $version): InventoryGuide
    {
        return new InventoryGuide(Assessment::for($version));
    }

    /** A copy of the fixture pack with a second standard, its own list, and an interface catalogue. */
    private function guidedPack(): string
    {
        $dir = storage_path('framework/testing/pack-guide-'.uniqid());
        File::copyDirectory(base_path('tests/Fixtures/content-pack'), $dir);
        $edit = function (string $file, callable $change) use ($dir) {
            $data = json_decode((string) file_get_contents($dir.'/'.$file), true);
            file_put_contents($dir.'/'.$file, json_encode($change($data), JSON_PRETTY_PRINT));
        };
        $edit('pack.json', function (array $pack) {
            $pack['standards'][] = ['code' => 'demo-2', 'name' => 'DEMO-2', 'article' => 'A2'];
            $pack['articles'][] = ['code' => 'A2', 'name' => 'Sample article A2'];
            $pack['standards'][0]['name'] = 'DEMO-1';

            return $pack;
        });
        $edit('scoping.json', function (array $scoping) {
            $scoping['sections'][0]['groups'][0]['questions'][] = ['key' => 'logging', 'type' => 'boolean', 'label' => 'Keeps logs', 'articles' => ['A2']];

            return $scoping;
        });
        $edit('lists.json', function (array $lists) {
            $lists['lists'][0]['fields'][0]['options'] = [
                ['value' => 'security', 'label' => 'Security', 'standards' => ['demo-1', 'demo-2'], 'help' => 'Keeps the device safe'],
                ['value' => 'data', 'label' => 'Data', 'standards' => ['demo-2'], 'help' => 'Personal things'],
            ];
            $lists['lists'][] = ['key' => 'logs', 'label' => 'Logs', 'singular' => 'Log', 'code_prefix' => 'LG-', 'fields' => []];

            return $lists;
        });
        $edit('requirements.json', function (array $requirements) {
            $requirements[] = ['code' => 'LOG-1', 'family' => 'UPD', 'title' => 'Sample log requirement', 'standards' => ['demo-2'], 'list' => 'logs'];

            return $requirements;
        });
        $edit('catalogue.json', function (array $catalogue) {
            $catalogue['lists']['interfaces'] = [
                ['name' => 'Sample radio', 'attributes' => ['kind' => 'network'], 'standards' => ['demo-1'], 'suggest_when' => ['connected']],
                ['name' => 'Sample button', 'attributes' => ['kind' => 'user'], 'standards' => ['demo-1']],
            ];
            $catalogue['lists']['logs'] = [['name' => 'Sample boot log', 'standards' => ['demo-2'], 'suggest_when' => ['logging']]];

            return $catalogue;
        });

        return $dir;
    }
}
