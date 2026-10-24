<?php

namespace Tests\Feature\Reports;

use App\Assessment\Assessment;
use App\Content\ContentPack;
use App\Enums\Verdict;
use App\Models\Evaluation;
use App\Models\ProductVersion;
use App\Reports\CetomeWorkbooks;
use App\Workbooks\ImportApplier;
use App\Workbooks\Mapping;
use App\Workbooks\Sheet;
use App\Workbooks\WorkbookImport;
use App\Workbooks\XlsxPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;
use ZipArchive;

/**
 * The owner's real templates (content pack templates/), filled from a version built with every
 * list, then read back. Skipped unless REDACT_TEST_PACK points to the pack, e.g.:
 *   $env:REDACT_TEST_PACK="..\redact-content"; php artisan test --filter=CetomeWorkbooksPack
 */
class CetomeWorkbooksPackTest extends TestCase
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
        if (app(CetomeWorkbooks::class)->available() === []) {
            $this->markTestSkipped('The pack has no workbook templates.');
        }
    }

    /** Every list filled with two items (first options), linked, every scoping answer "yes", walks at DN-1. */
    private function version(): ProductVersion
    {
        $pack = app(ContentPack::class);
        $version = $this->product($this->owner());
        $version->update(['author_name' => 'Test author', 'completion_date' => '2026-10-23']);
        foreach ($pack->scoping()['sections'] as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['questions'] as $question) {
                    $version->scopingAnswers()->create(['question' => $question['key'], 'value' => true]);
                }
            }
        }
        $items = [];
        foreach ($pack->lists() as $key => $list) {
            foreach ([1, 2] as $n) {
                $attributes = [];
                foreach ($list['fields'] ?? [] as $field) {
                    $option = $field['options'][$n - 1]['value'] ?? $field['options'][0]['value'] ?? null;
                    $attributes[$field['key']] = match ($field['type']) {
                        'boolean' => $n === 1,
                        'yesno' => 'yes',
                        'select' => $option,
                        'multiselect' => [$field['options'][0]['value']],
                        default => 'Text '.$n,
                    };
                }
                $items[$key][] = $version->inventoryItems()->create([
                    'list' => $key, 'code' => ($list['code_prefix'] ?? 'X-').'0'.$n, 'name' => ucfirst((string) $key).' '.$n,
                    'description' => 'Description '.$n, 'attributes' => $attributes, 'position' => $n,
                ]);
            }
        }
        foreach ($pack->relations() as $key => $relation) {
            $version->inventoryLinks()->create(['relation' => $key, 'from_item_id' => $items[$relation['from']][0]->id, 'to_item_id' => $items[$relation['to']][0]->id]);
        }
        $assessment = Assessment::for($version);
        foreach ($assessment->standards() as $standard) {
            foreach ($pack->requirementsFor($standard) as $code => $definition) {
                $tree = $pack->tree($standard, (string) $code);
                foreach ($assessment->targets($standard, (string) $code) as $item) {
                    if ($item && $tree) {
                        $node = $tree->decisionNodes()[0];
                        $verdict = $tree->verdictsFrom($node)[0] ?? null;
                        Evaluation::create([
                            'product_version_id' => $version->id, 'standard' => $standard, 'requirement' => $code,
                            'inventory_item_id' => $item->id, 'decision_node' => $node, 'verdict' => $verdict ?? Verdict::Pass,
                            'justification' => 'Because '.$code.'.',
                        ]);
                    }
                }
            }
        }
        $assessment->refresh();
        foreach ($assessment->standards() as $standard) {
            foreach ($assessment->requiredEinfo($standard) as $row) {
                foreach (array_slice($row['codes'], 0, 3) as $code) {
                    if ($row['item']) {
                        $version->einfoEntries()->firstOrCreate(['einfo' => $code, 'inventory_item_id' => $row['item']->id], ['text' => 'Text of '.$code]);
                    }
                }
            }
        }
        $version->riskEntries()->create(['position' => 1, 'threat' => 'A threat', 'stride' => ['S', 'T'], 'impact' => 4, 'likelihood' => 3, 'articles' => ['3.3d'], 'remediation' => 'Do it']);

        return $version;
    }

    public function test_the_owner_templates_are_filled_kept_and_read_back(): void
    {
        $version = $this->version();
        $workbooks = app(CetomeWorkbooks::class);
        $files = [];
        foreach ($workbooks->available() as $workbook) {
            $template = (string) app(ContentPack::class)->template($workbook['key']);
            $target = tempnam(sys_get_temp_dir(), 'wb-').'.xlsx';
            $result = $workbooks->fill($version, $workbook, $target);
            $files[] = $target;

            // Every fix of the mapping applies to the owner's template
            $this->assertSame([], $result['skipped'], $workbook['key'].': fixes left: '.implode(', ', array_column($result['skipped'], 'id')));
            $this->assertSame([], $result['refused'], $workbook['key'].': formula cells refused');

            // Formulas kept, apart from the fixed ones (each named by the mapping)
            $fixedColumns = [];
            foreach ((array) $workbook['fixes'] as $fix) {
                if (in_array($fix['type'], ['formula', 'fill'], true)) {
                    foreach ((array) $fix['range'] as $range) {
                        $sheet = str_contains($range, '!') ? explode('!', $range)[0] : $fix['sheet'];
                        foreach (Sheet::range(str_contains($range, '!') ? explode('!', $range)[1] : $range) as $ref) {
                            $fixedColumns[$sheet.'!'.$ref] = true;
                        }
                    }
                }
            }
            // A restriction the data settles (mapping {computed: settled}) is written as met (1) over
            // its formula, with a note; every other formula stays.
            $settled = [];
            foreach ((array) $workbook['blocks'] as $block) {
                foreach ((array) ($block['cells'] ?? []) as $ref => $spec) {
                    if (is_array($spec) && ($spec['computed'] ?? null) === 'settled') {
                        $settled[$block['sheet'].'!'.$ref] = true;
                    }
                }
            }
            $before = self::formulas($template);
            $after = self::formulas($target);
            foreach ($before as $ref => $formula) {
                if (isset($settled[$ref]) && ! isset($after[$ref])) {
                    [$sheetName, $cell] = explode('!', $ref);
                    $this->assertEquals(1, XlsxPackage::open($target)->sheet($sheetName)?->get($cell), $ref.' written as met');
                    $this->assertNotSame([], preg_grep('/^'.preg_quote($sheetName.' '.$cell, '/').'/', $result['notes']), $ref.' explained in the notes');

                    continue;
                }
                $this->assertArrayHasKey($ref, $after, $workbook['key'].' lost '.$ref);
                if (! isset($fixedColumns[$ref])) {
                    $this->assertSame($formula, $after[$ref], $workbook['key'].' changed '.$ref);
                }
            }

            $package = XlsxPackage::open($target);
            $this->assertStringContainsString('fullCalcOnLoad="1"', (string) $package->raw('xl/workbook.xml'));
            $this->assertStringNotContainsString('workbookProtection', (string) $package->raw('xl/workbook.xml'));
            foreach ($package->sheetNames() as $name) {
                $sheet = $package->sheet($name);
                $this->assertNotNull($sheet);
                $this->assertFalse($sheet->isProtected(), $name.' is still protected');
                $this->assertStringNotContainsString('hashValue', (string) $package->raw($sheet->part));
                // Every value written in a dropdown cell is one of the dropdown's labels
                foreach ($sheet->validations() as $dv) {
                    $formula = trim((string) $dv->getElementsByTagNameNS(XlsxPackage::NS, 'formula1')->item(0)?->textContent);
                    if (! str_starts_with($formula, '"')) {
                        continue;
                    }
                    $labels = array_map('trim', explode(',', trim($formula, '"')));
                    foreach (preg_split('/\s+/', $dv->getAttribute('sqref')) ?: [] as $range) {
                        foreach (Sheet::range($range) as $ref) {
                            $value = $sheet->get($ref);
                            if (is_string($value) && $value !== '' && ! $sheet->hasFormula($ref)) {
                                $this->assertContains($value, $labels, $workbook['key'].' '.$name.'!'.$ref);
                            }
                        }
                    }
                }
            }
            $templatePackage = XlsxPackage::open($template);
            foreach ($templatePackage->sheetNames() as $name) {
                // Tables keep their ranges
                $this->assertSame(self::tables($templatePackage), self::tables($package));
            }
            $package->close();
            $templatePackage->close();
        }

        // Read back into the same version: nothing new, no conflict in the inventory or the walks
        $pack = app(ContentPack::class);
        $import = new WorkbookImport($pack, Mapping::fromPack($pack));
        foreach ($files as $file) {
            $import->read($file, basename($file));
        }
        $plan = $import->plan();
        $preview = (new ImportApplier($version, $pack))->preview($plan);
        foreach (['inventory', 'scoping', 'links'] as $part) {
            $statuses = array_count_values(array_column($preview[$part], 'status'));
            $this->assertSame([], array_diff_key($statuses, ['same' => true]), $part.': '.json_encode(array_filter($preview[$part], fn ($r) => $r['status'] !== 'same')));
        }
        $this->assertNotEmpty($plan['walks']);
        foreach ($files as $file) {
            @unlink($file);
        }

        // All four in one zip with the notes
        $zipPath = tempnam(sys_get_temp_dir(), 'wbz-');
        $keys = $workbooks->zip($version, $zipPath);
        $this->assertCount(count($workbooks->available()), $keys);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertSame(count($keys) + 1, $zip->numFiles);
        $zip->close();
        @unlink($zipPath);
    }

    /** @return array<string, string> */
    private static function formulas(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $book = $reader->load($path);
        $out = [];
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->getCellCollection()->getCoordinates() as $ref) {
                $cell = $sheet->getCell($ref);
                if ($cell->isFormula()) {
                    $out[$sheet->getTitle().'!'.$ref] = (string) $cell->getValue();
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> table part => ref */
    private static function tables(XlsxPackage $package): array
    {
        $out = [];
        for ($i = 1; $i <= 30; $i++) {
            $xml = $package->raw('xl/tables/table'.$i.'.xml');
            if ($xml !== null && preg_match('/<table [^>]*ref="([^"]+)"/', $xml, $m) === 1) {
                $out['table'.$i] = $m[1];
            }
        }

        return $out;
    }
}
