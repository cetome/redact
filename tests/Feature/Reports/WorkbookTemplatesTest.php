<?php

namespace Tests\Feature\Reports;

use App\Ai\Provenance;
use App\Ai\Targets;
use App\Content\ContentPack;
use App\Enums\Origin;
use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Models\EinfoEntry;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Reports\CetomeWorkbooks;
use App\Workbooks\Mapping;
use App\Workbooks\Sheet;
use App\Workbooks\WorkbookException;
use App\Workbooks\WorkbookImport;
use App\Workbooks\XlsxPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;
use ZipArchive;

/**
 * "Your cetome REDact workbooks" and the exact importer, on the demo pack's invented workbook
 * (tests/Fixtures/content-pack/templates: a protected workbook with a table, dropdowns and
 * formulas). The real templates are tested when REDACT_TEST_PACK is set.
 */
class WorkbookTemplatesTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private User $owner;

    private ProductVersion $version;

    /** @var array<string, InventoryItem> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->owner();
        $this->version = $this->product($this->owner);
        $version = $this->version;
        $version->product->update(['description' => 'A smart lock & its hub.']);
        $version->update(['author_name' => 'Ada Lovelace', 'completion_date' => '2026-10-20']);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $version->scopingAnswers()->create(['question' => 'updates', 'value' => false]);

        $this->items['vault'] = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Key vault', 'description' => 'Holds the keys.', 'attributes' => ['type' => 'security', 'stored' => true], 'position' => 1]);
        $this->items['logs'] = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-02', 'name' => '=HYPERLINK("http://evil")', 'attributes' => ['type' => 'data', 'stored' => false], 'position' => 2]);
        $this->items['wifi'] = $version->inventoryItems()->create(['list' => 'interfaces', 'code' => 'IF-01', 'name' => 'Wi-Fi', 'attributes' => ['kind' => 'network'], 'position' => 1]);
        $version->inventoryLinks()->create(['relation' => 'asset_interface', 'from_item_id' => $this->items['vault']->id, 'to_item_id' => $this->items['wifi']->id]);

        Evaluation::create([
            'product_version_id' => $version->id, 'standard' => 'demo-1', 'requirement' => 'ACC-1',
            'inventory_item_id' => $this->items['vault']->id, 'decision_node' => 'DN-2', 'verdict' => Verdict::Pass,
            'justification' => 'Access needs a PIN.',
        ]);
        $version->einfoEntries()->create(['einfo' => 'E.Info.ACC-1.Asset', 'inventory_item_id' => $this->items['vault']->id, 'text' => 'PIN on the keypad.']);
        $version->riskEntries()->create(['position' => 1, 'threat' => 'Firmware tampering', 'stride' => ['T'], 'impact' => 5, 'likelihood' => 4, 'requirements' => ['UPD-1'], 'remediation' => 'Signed updates']);
    }

    private function as(User $user): static
    {
        return $this->signIn($user->refresh());
    }

    private function val(Worksheet $sheet, string $ref): mixed
    {
        $value = $sheet->getCell($ref)->getValue();

        return $value instanceof RichText ? $value->getPlainText() : $value;
    }

    private function template(): string
    {
        return (string) app(ContentPack::class)->template('demo');
    }

    /** The file a streamed download wrote, copied before it is deleted. */
    private function saved(TestResponse $response, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'redact-test-').'.'.$extension;
        $streamed = $response->baseResponse->getFile()->getPathname();
        copy($streamed, $path);
        @unlink($streamed);

        return $path;
    }

    private function part(string $path, string $name): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $content = (string) $zip->getFromName($name);
        $zip->close();

        return $content;
    }

    /** @return array<string, string> "Sheet!A1" => formula, for every formula cell */
    private function formulas(string $path): array
    {
        $book = IOFactory::createReader('Xlsx')->load($path);
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

    public function test_the_template_is_filled_without_rebuilding_it(): void
    {
        $response = $this->as($this->owner)->get(route('versions.workbooks.download', [$this->version, 'demo']))->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString(rawurlencode('Smart lock 1.0 - cetome REDact - Demo.xlsx'), (string) $response->headers->get('Content-Disposition'));
        $path = $this->saved($response, 'xlsx');

        $book = IOFactory::createReader('Xlsx')->load($path);
        $intro = $book->getSheetByName('Introduction');
        $this->assertSame('Ada Lovelace', $this->val($intro, 'B2'));
        $this->assertSame('Smart lock 1.0', $this->val($intro, 'B3'));
        $this->assertEquals(46315, $this->val($intro, 'B4'), '20 October 2026 as an Excel date');
        $this->assertSame('A smart lock & its hub.', $this->val($intro, 'A6'));
        $this->assertTrue($this->val($book->getSheetByName('Scoping'), 'B2'));
        $this->assertFalse($this->val($book->getSheetByName('Scoping'), 'B3'));

        $inventory = $book->getSheetByName('Inventory');
        $this->assertSame('AS-01 · Key vault', $this->val($inventory, 'A2'));
        $this->assertSame('Security', $this->val($inventory, 'B2'));
        $this->assertSame('Yes', $this->val($inventory, 'C2'));
        $this->assertSame('Holds the keys.', $this->val($inventory, 'D2'));
        // A formula-looking name stays text, never a formula
        $this->assertSame('AS-02 · =HYPERLINK("http://evil")', $this->val($inventory, 'A3'));
        $this->assertFalse($inventory->getCell('A3')->isFormula());
        $this->assertSame('x', $this->val($book->getSheetByName('Mapping'), 'B2'));
        $this->assertNull($this->val($book->getSheetByName('Mapping'), 'B3'));

        $walks = $book->getSheetByName('Walks');
        $this->assertSame('DT.ACC-1.DN-2', $this->val($walks, 'B2'));
        $this->assertSame('PASS', $this->val($walks, 'C2'));
        $this->assertSame('Access needs a PIN.', $this->val($walks, 'D2'));
        $this->assertSame('E.Info.ACC-1.Asset: PIN on the keypad.', $this->val($walks, 'E2'));
        $this->assertNull($this->val($walks, 'B3'), 'Walked but not evaluated yet: blank');

        $risks = $book->getSheetByName('Risks');
        $this->assertSame('Firmware tampering', $this->val($risks, 'A2'));
        $this->assertNull($this->val($risks, 'B2'), 'The template example is cleared');
        $this->assertSame('x', $this->val($risks, 'C2'));
        $this->assertEquals(5, $this->val($risks, 'D2'));
        $this->assertSame("Signed updates\n[UPD-1]", $this->val($risks, 'G2'));

        // Formulas: all kept, only the fixed ones changed
        $before = $this->formulas($this->template());
        $after = $this->formulas($path);
        $this->assertSame(array_keys($before), array_keys($after));
        foreach ($before as $ref => $formula) {
            $expected = str_starts_with($ref, 'Walks!F') ? str_replace('DT.ACC-1.DN-9', 'DT.ACC-1.DN-2', $formula) : $formula;
            $this->assertSame($expected, $after[$ref], $ref);
        }

        // Tables, dropdowns, recalculation, no protection, no password anywhere
        $this->assertStringContainsString('ref="A1:E6"', $this->part($path, 'xl/tables/table1.xml'));
        $this->assertStringContainsString('"N/A,DT.ACC-1.DN-1,DT.ACC-1.DN-2"', html_entity_decode($this->part($path, 'xl/worksheets/sheet6.xml')));
        $this->assertStringContainsString('"Security,Data"', html_entity_decode($this->part($path, 'xl/worksheets/sheet3.xml')));
        $workbook = $this->part($path, 'xl/workbook.xml');
        $this->assertStringContainsString('fullCalcOnLoad="1"', $workbook);
        $this->assertStringNotContainsString('workbookProtection', $workbook);
        foreach ($book->getAllSheets() as $sheet) {
            $this->assertNotTrue($sheet->getProtection()->getSheet(), $sheet->getTitle().' is not protected');
        }
        $this->assertNotTrue($book->getSecurity()->getLockStructure());
        preg_match_all('/hashValue="([^"]+)"/', $this->part($this->template(), 'xl/worksheets/sheet1.xml'), $hashes);
        $this->assertNotEmpty($hashes[1], 'The template is protected');
        $zip = new ZipArchive;
        $zip->open($path);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $xml = (string) $zip->getFromIndex($i);
            $this->assertStringNotContainsString('sheetProtection', $xml);
            foreach ($hashes[1] as $hash) {
                $this->assertStringNotContainsString($hash, $xml);
            }
        }
        $zip->close();

        // Document properties
        $custom = $this->part($path, 'docProps/custom.xml');
        foreach (['REDact version', 'Content pack', 'Generated at', 'Product', 'Product version'] as $name) {
            $this->assertStringContainsString('name="'.$name.'"', $custom);
        }
        $this->assertStringContainsString('demo-formula, demo-dropdown', $custom);

        // Logged like the other reports (activity log and usage statistics)
        $this->assertSame(1, Activity::query()->where('log_name', 'report')->where('properties->kind', 'workbook:demo')->count());
        @unlink($path);
    }

    public function test_a_fix_is_applied_only_where_the_template_holds_the_expected_text(): void
    {
        $result = app(CetomeWorkbooks::class)->fill($this->version, app(CetomeWorkbooks::class)->workbook('demo'), $target = tempnam(sys_get_temp_dir(), 'wb-'));
        $this->assertSame(['demo-formula', 'demo-dropdown'], array_column($result['applied'], 'id'));
        $this->assertSame(['demo-missing'], array_column($result['skipped'], 'id'));
        @unlink($target);
    }

    public function test_rows_beyond_the_template_are_left_out_and_noted(): void
    {
        for ($n = 3; $n <= 7; $n++) {
            $this->version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-0'.$n, 'name' => 'Asset '.$n, 'attributes' => ['type' => 'data'], 'position' => $n]);
        }
        $response = $this->as($this->owner)->get(route('versions.workbooks.zip', $this->version))->assertOk();
        $path = $this->saved($response, 'zip');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertNotFalse($zip->locateName('Smart lock 1.0 - cetome REDact - Demo.xlsx'));
        $notes = (string) $zip->getFromName(__('workbooks.notes_file.name').'.txt');
        $zip->close();
        $this->assertStringContainsString('Inventory', $notes);
        $this->assertStringContainsString('AS-06 · Asset 6', $notes);
        $this->assertStringContainsString('Demo fix of a formula.', $notes);
        $this->assertStringContainsString('Demo item left as it is.', $notes);
        $this->assertSame(1, Activity::query()->where('log_name', 'report')->where('properties->kind', 'workbooks')->count());
        @unlink($path);
    }

    public function test_access_follows_the_reports(): void
    {
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        $this->as($reviewer)->get(route('versions.workbooks.download', [$this->version, 'demo']))->assertOk();
        $stranger = $this->owner();
        $this->as($stranger)->get(route('versions.workbooks.download', [$this->version, 'demo']))->assertNotFound();
        $this->as($this->owner)->get(route('versions.workbooks.download', [$this->version, 'nope']))->assertNotFound();

        $page = $this->as($this->owner)->get(route('reports.index', $this->version))->assertOk();
        $page->assertSee(__('workbooks.title'))->assertSee('Demo fix of a formula.')
            ->assertSee(route('versions.workbooks.download', [$this->version, 'demo']), false)
            ->assertSee(route('versions.workbooks.zip', $this->version), false);
    }

    public function test_guests_need_an_account_for_workbooks_and_imports(): void
    {
        $this->owner->forceFill(['is_guest' => true, 'guest_expires_at' => now()->addDay()])->save();
        $this->as($this->owner)->get(route('versions.workbooks.download', [$this->version, 'demo']))->assertRedirect(route('guest.claim'));
        $this->as($this->owner)->post(route('versions.import.store', $this->version), ['files' => [UploadedFile::fake()->create('a.xlsx', 1)]])
            ->assertRedirect(route('guest.claim'));
        $this->as($this->owner)->get(route('reports.index', $this->version))->assertOk()
            ->assertSee(__('workbooks.locked'))
            ->assertDontSee(route('versions.workbooks.zip', $this->version), false);
        $this->as($this->owner)->get(route('versions.edit', $this->version))->assertOk()
            ->assertSee(__('import.locked'))
            ->assertDontSee('action="'.route('versions.import.store', $this->version).'"', false);
    }

    // ---- Import -----------------------------------------------------------------------------------

    /** The demo workbook filled from the set-up version, as a person would upload it. */
    private function filledWorkbook(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wb-');
        app(CetomeWorkbooks::class)->fill($this->version, app(CetomeWorkbooks::class)->workbook('demo'), $path);

        return new UploadedFile($path, 'My demo workbook.xlsx', null, null, true);
    }

    public function test_a_filled_workbook_is_imported_into_an_empty_version_after_a_preview(): void
    {
        $file = $this->filledWorkbook();
        $target = $this->product($this->owner, '2.0');
        $this->assertSame(0, $target->inventoryItems()->count());

        $response = $this->as($this->owner)->post(route('versions.import.store', $target), ['files' => [$file]]);
        $response->assertRedirect();
        $preview = $this->as($this->owner)->get((string) $response->headers->get('Location'))->assertOk();
        $preview->assertSee(__('import.title'))->assertSee('My demo workbook.xlsx')->assertSee('AS-01 · Key vault')
            ->assertSee(__('import.parts.walks'))->assertSee(__('import.status.new'));
        $this->assertSame(0, $target->inventoryItems()->count(), 'Nothing is written before Apply');

        $token = basename((string) $response->headers->get('Location'));
        $this->as($this->owner)->post(route('versions.import.apply', [$target, $token]), ['parts' => ['fields', 'scoping', 'inventory', 'links', 'walks', 'einfo', 'risks']])
            ->assertRedirect(route('versions.edit', $target));

        $target->refresh();
        $this->assertSame('Ada Lovelace', $target->author_name);
        $this->assertSame('2026-10-20', $target->completion_date?->toDateString());
        $this->assertTrue($target->scopingAnswers()->where('question', 'connected')->value('value'));
        $vault = $target->inventoryItems()->where('code', 'AS-01')->firstOrFail();
        $this->assertSame('Key vault', $vault->name);
        $this->assertSame('Holds the keys.', $vault->description);
        $this->assertSame(['type' => 'security', 'stored' => true], $vault->getAttribute('attributes'));
        $this->assertSame('=HYPERLINK("http://evil")', $target->inventoryItems()->where('code', 'AS-02')->value('name'));
        $wifi = $target->inventoryItems()->where('code', 'IF-01')->firstOrFail();
        $this->assertTrue($target->inventoryLinks()->where('from_item_id', $vault->id)->where('to_item_id', $wifi->id)->exists());

        $evaluation = Evaluation::query()->where('product_version_id', $target->id)->where('inventory_item_id', $vault->id)->firstOrFail();
        $this->assertSame('DN-2', $evaluation->decision_node);
        $this->assertSame(Verdict::Pass, $evaluation->verdict);
        $this->assertSame('Access needs a PIN.', $evaluation->justification);
        $this->assertSame('PIN on the keypad.', EinfoEntry::query()->where('product_version_id', $target->id)->where('einfo', 'E.Info.ACC-1.Asset')->value('text'));
        $risk = $target->riskEntries()->where('threat', 'Firmware tampering')->firstOrFail();
        $this->assertSame(['T'], $risk->stride);
        $this->assertSame(['UPD-1'], $risk->requirements);
        $this->assertSame('Signed updates', $risk->remediation);
        $this->assertFalse($target->riskEntries()->where('threat', 'like', 'Example:%')->exists(), 'The template example is not imported');

        // Marked "Imported from your workbook" (not AI), logged
        $provenance = app(Provenance::class);
        $this->assertSame(Origin::Imported, $provenance->origin('evaluation', $evaluation->id, 'justification', $evaluation->justification));
        $this->assertSame(Origin::Imported, $provenance->origin('einfo_entry', Targets::einfoKey('E.Info.ACC-1.Asset', $vault->id), 'text', 'PIN on the keypad.'));
        $this->assertSame(Origin::Imported, $provenance->origin('inventory_item', $vault->id, 'description', 'Holds the keys.'));
        $this->assertTrue(Activity::query()->where('log_name', 'import')->where('subject_id', $target->id)->exists());
    }

    public function test_only_the_ticked_parts_are_applied_and_existing_values_show_as_conflicts(): void
    {
        $file = $this->filledWorkbook();
        $this->items['vault']->update(['description' => 'Changed in REDact.']);
        $response = $this->as($this->owner)->post(route('versions.import.store', $this->version), ['files' => [$file]]);
        $location = (string) $response->headers->get('Location');
        $this->as($this->owner)->get($location)->assertOk()->assertSee(__('import.status.same'));

        $this->as($this->owner)->post(route('versions.import.apply', [$this->version, basename($location)]), ['parts' => ['risks']])->assertRedirect();
        $this->assertSame('Changed in REDact.', $this->items['vault']->refresh()->description, 'Inventory not ticked: unchanged');
        $this->assertSame(1, $this->version->riskEntries()->count(), 'The same threat is updated, not duplicated');

        // The plan is used once and belongs to its author and version
        $this->as($this->owner)->get($location)->assertNotFound();
    }

    public function test_the_import_refuses_what_it_cannot_read(): void
    {
        $other = $this->owner();
        $this->as($other)->post(route('versions.import.store', $this->version), ['files' => [$this->filledWorkbook()]])->assertNotFound();
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        $this->as($reviewer)->post(route('versions.import.store', $this->version), ['files' => [$this->filledWorkbook()]])->assertNotFound();

        // A workbook encrypted with a password to open is an OLE container, not a zip
        $encrypted = tempnam(sys_get_temp_dir(), 'enc-');
        file_put_contents($encrypted, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 600));
        $this->as($this->owner)->from(route('versions.edit', $this->version))
            ->post(route('versions.import.store', $this->version), ['files' => [new UploadedFile($encrypted, 'Locked.xlsx', null, null, true)]])
            ->assertRedirect(route('versions.edit', $this->version))
            ->assertSessionHasErrors('files');
        $this->assertStringContainsString(__('import.errors.encrypted'), implode(' ', session('errors')->get('files')));

        try {
            XlsxPackage::open($encrypted);
            $this->fail('An encrypted workbook must be refused');
        } catch (WorkbookException $e) {
            $this->assertSame(__('import.errors.encrypted'), $e->getMessage());
        }

        // A part with a DOCTYPE (external entities) is refused
        $this->expectException(WorkbookException::class);
        XlsxPackage::parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>');
    }

    public function test_another_spreadsheet_goes_to_the_ai_documents_as_tab_separated_text(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Parts')->fromArray([['Part', 'Version'], ['Bootloader', '2.1']]);
        $path = tempnam(sys_get_temp_dir(), 'xl-').'.xlsx';
        (new Xlsx($book))->save($path);

        // The one upload of the Documents column: this file is a document, the cetome workbook goes to the preview
        $this->as($this->owner)->get(route('versions.edit', $this->version))->assertOk()
            ->assertSee('action="'.route('versions.import.store', $this->version).'"', false)
            ->assertSee('name="files[]" multiple', false);
        $response = $this->as($this->owner)->from(route('versions.edit', $this->version))
            ->post(route('versions.import.store', $this->version), [
                'files' => [new UploadedFile($path, 'Parts list.xlsx', null, null, true), $this->filledWorkbook()],
                'category' => 'descriptions',
            ]);
        $response->assertRedirect()->assertSessionHas('status', trans_choice('import.documents_added', 1, ['count' => 1]));
        $this->assertStringContainsString('/import/', (string) $response->headers->get('Location'));
        $document = $this->version->documents()->sole();
        $this->assertSame('Parts list.xlsx', $document->original_name);
        $this->assertSame('descriptions', $document->category);
        $this->assertStringContainsString("## Sheet: Parts\nPart\tVersion\nBootloader\t2.1", (string) $document->extracted_text);
    }

    public function test_the_mapping_reads_back_what_it_writes(): void
    {
        $pack = app(ContentPack::class);
        $import = new WorkbookImport($pack, Mapping::fromPack($pack));
        $file = $this->filledWorkbook();
        $workbook = $import->read((string) $file->getRealPath(), 'demo.xlsx');
        $this->assertSame('demo', $workbook['key']);
        $plan = $import->plan();
        $this->assertSame(['connected' => true, 'updates' => false], $plan['scoping']);
        $this->assertSame('DN-2', $plan['walks']['ACC-1|assets|code:as-01']['node']);
        $this->assertSame('PASS', $plan['walks']['ACC-1|assets|code:as-01']['verdict']);
        $this->assertSame('E.Info.ACC-1.Asset', array_values($plan['einfo'])[0]['code']);
        $this->assertSame([], $plan['warnings']);
        $this->assertSame(['A1', 'B1', 'A2', 'B2'], Sheet::range('A1:B2'));
        $this->assertSame('AB', Sheet::columnLetters(Sheet::columnIndex('AB')));
    }
}
