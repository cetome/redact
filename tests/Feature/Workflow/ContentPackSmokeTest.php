<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\ScopingForm;
use App\Assessment\SharedWalks;
use App\Content\ContentPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Runs the whole workflow against any content pack, driven only by the pack's
 * own definitions (no pack content in this repository).
 *
 * Skipped unless REDACT_TEST_PACK points to a pack, e.g.:
 *   $env:REDACT_TEST_PACK="..\redact-content"; php artisan test --filter=ContentPackSmoke
 */
class ContentPackSmokeTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $path = getenv('REDACT_TEST_PACK') ?: null;
        if (! $path) {
            $this->markTestSkipped('Set REDACT_TEST_PACK to a content pack to run this test.');
        }
        config(['redact.content_path' => realpath($path) ?: $path]);
        $this->app->forgetInstance(ContentPack::class);
    }

    public function test_every_page_and_report_renders_with_the_pack(): void
    {
        $pack = app(ContentPack::class);
        $owner = $this->owner();
        $version = $this->product($owner);
        $this->signIn($owner);

        // Answer every scoping question "yes" (or its first option).
        $answers = [];
        foreach (app(ScopingForm::class)->questions() as $key => $question) {
            $answers[$key] = ($question['type'] ?? 'boolean') === 'boolean' ? '1' : ($question['options'][0]['value'] ?? null);
        }
        $this->put(route('versions.scoping.update', $version), ['answers' => $answers])->assertSessionHasNoErrors();

        // Two objects per list, with every field filled in through the real form.
        foreach ($pack->lists() as $key => $list) {
            foreach ([1, 2] as $n) {
                $attributes = [];
                foreach ($list['fields'] ?? [] as $field) {
                    $first = $field['options'][$n - 1]['value'] ?? $field['options'][0]['value'] ?? null;
                    $attributes[$field['key']] = match ($field['type']) {
                        'boolean' => '1',
                        'yesno' => 'yes',
                        'select' => $first,
                        'multiselect' => array_filter([$first]),
                        default => 'Sample '.$field['key'],
                    };
                }
                $this->post(route('versions.inventory.store', [$version, $key]), [
                    'code' => strtoupper(substr($key, 0, 3)).'-'.$n, 'name' => $key.' '.$n, 'attributes' => $attributes,
                ])->assertSessionHasNoErrors();
            }
            $this->get(route('versions.inventory', [$version, $key]))->assertOk();
        }

        // Link everything that can be linked.
        foreach ($pack->relations() as $key => $relation) {
            $rows = $version->inventoryItems()->where('list', $relation['from'])->pluck('id')->all();
            $others = $version->inventoryItems()->where('list', $relation['to'])->pluck('id')->all();
            $this->put(route('versions.inventory.links', [$version, $key]), [
                'side' => 'from', 'rows' => $rows, 'links' => array_fill_keys($rows, $others),
            ])->assertSessionHasNoErrors();
        }

        $assessment = Assessment::for($version->fresh());
        $this->assertNotEmpty($assessment->standards(), 'Answering yes everywhere should bring standards into scope.');

        // Walk the first branch of each tree for each target until targets stop growing.
        $walked = 0;
        for ($round = 0; $round < 4; $round++) {
            $assessment = Assessment::for($version->fresh());
            foreach ($assessment->standards() as $standard) {
                foreach ($assessment->applicableRequirements($standard) as $code => $requirement) {
                    $tree = $pack->tree($standard, $code);
                    $this->get(route('versions.evaluate.requirement', [$version, $standard, $code]))->assertOk();
                    if (! $tree) {
                        continue;
                    }
                    foreach ($assessment->targets($standard, $code) as $item) {
                        if ($assessment->evaluation($standard, $code, $item)) {
                            continue;
                        }
                        // Always take the last branch: tends to go deeper into the tree.
                        $answers = [];
                        $node = $tree->node($tree->start());
                        while ($node['kind'] === 'decision') {
                            $branch = end($node['branches']);
                            $answers[] = $branch['label'];
                            $node = $tree->node($branch['to']);
                        }
                        $this->put(route('versions.evaluate.update', [$version, $standard, $code]), [
                            'target' => $item?->id ?? 'product', 'answers' => $answers, 'justification' => 'Smoke test.',
                        ])->assertSessionHasNoErrors();
                        $walked++;
                    }
                }
            }
        }
        $this->assertGreaterThan(0, $walked);

        $assessment = Assessment::for($version->fresh());
        foreach ($assessment->standards() as $standard) {
            $this->get(route('versions.evaluate', [$version, $standard]))->assertOk();
            $this->get(route('versions.einfo', [$version, $standard]))->assertOk();
            $rows = $assessment->requiredEinfo($standard);
            $this->assertNotEmpty($rows);

            // Conditional E.Info (if the pack has any): shown on the object's card, can be written,
            // never counted in the progress.
            $row = collect($rows)->first(fn (array $r) => ($r['conditional'] ?? []) !== []);
            if ($row) {
                $key = $row['item']->id ?? 'product';
                $code = $row['conditional'][0];
                $this->assertNotContains($code, $row['codes']);
                $this->get(route('versions.einfo.object', [$version, $standard, $key]))->assertOk()->assertSee(__('einfo.conditional'));
                $before = $assessment->einfoProgress($standard);
                $this->put(route('versions.einfo.update', [$version, $standard]), ['entries' => [$key => [$code => 'Smoke test: condition holds.']]])
                    ->assertSessionHasNoErrors();
                $this->assertDatabaseHas('einfo_entries', ['product_version_id' => $version->id, 'einfo' => $code, 'inventory_item_id' => $row['item']?->id]);
                $this->assertSame($before, Assessment::for($version->fresh())->einfoProgress($standard));
            }
        }

        foreach (['versions.show', 'versions.edit', 'versions.scoping', 'versions.risks', 'versions.review'] as $route) {
            $this->get(route($route, $version))->assertOk();
        }
        // The pack's assessment criteria: the justification check of every walked evaluation, the assessment units, the read-only evidence of each evaluation.
        foreach ($assessment->standards() as $standard) {
            foreach (array_keys($assessment->applicableRequirements($standard)) as $code) {
                $this->get(route('versions.evaluate.requirement', [$version, $standard, $code]))->assertOk();
            }
        }
        $this->get(route('versions.units', $version))->assertOk();
        $this->get(route('versions.review.units', $version))->assertOk();
        foreach ($version->evaluations()->get() as $evaluation) {
            $this->get(route('versions.review.evidence', [$version, $evaluation]))->assertOk();
        }
        foreach (array_keys($pack->standards()) as $standard) {
            $this->get(route('trees.standard', $standard))->assertOk();
            foreach (array_keys($pack->treesFor($standard)) as $code) {
                $this->get(route('trees.show', [$standard, $code]))->assertOk();
            }
        }
        if ($pack->risk()['threats'] ?? false) {
            $this->post(route('versions.risks.library', $version), ['threats' => array_keys($pack->risk()['threats'])])->assertSessionHasNoErrors();
        }

        foreach ([['assessment', 'docx'], ['assessment', 'xlsx'], ['trees', 'docx'], ['trees', 'xlsx'], ['review', 'docx'], ['review', 'xlsx']] as [$kind, $format]) {
            $this->get(route('reports.download', ['version' => $version, 'kind' => $kind, 'format' => $format]))->assertOk();
        }

        fwrite(STDERR, sprintf("\n[pack %s] standards: %s, evaluations walked: %d\n",
            $pack->reference(), implode(', ', $assessment->standards()), $walked));

        // Trees shared across parts (SharedWalks): requirement (groups of equivalent parts).
        $shared = [];
        foreach (SharedWalks::classes($pack) as $code => $classes) {
            $groups = [];
            foreach ($classes as $standard => $class) {
                $groups[$class][] = $standard;
            }
            $groups = array_filter($groups, fn (array $group) => count($group) > 1);
            if ($groups !== []) {
                $shared[] = $code.' ('.implode(' | ', array_map(fn (array $group) => implode('+', $group), $groups)).')';
            }
        }
        fwrite(STDERR, sprintf("[pack] %d requirements with a tree shared across parts: %s\n", count($shared), implode(', ', $shared)));
    }
}
