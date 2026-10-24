<?php

namespace Tests\Feature\Reports;

use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Http\Controllers\ReportController;
use App\Models\Evaluation;
use App\Models\EvaluationReview;
use App\Models\ProductVersion;
use App\Models\User;
use App\Models\VersionReview;
use App\Reports\DocxFinisher;
use App\Reports\ExportName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;
use ZipArchive;

/**
 * Word and Excel reports on the demo pack: standard demo-1, ACC-1 per asset
 * (DN-1 → N/A, DN-2 → PASS or FAIL), UPD-1 product-level.
 */
class ReportExportTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private User $owner;

    private ProductVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->owner();
        $this->version = $this->product($this->owner);
        $version = $this->version;
        $version->product->update(['reference' => 'SL-100', 'description' => 'A smart lock & its hub.']);

        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $version->scopingAnswers()->create(['question' => 'updates', 'value' => true]);

        $vault = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-1', 'name' => 'Key vault', 'attributes' => ['type' => 'security', 'stored' => true], 'position' => 1]);
        $logs = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-2', 'name' => '=HYPERLINK("http://evil")', 'attributes' => ['type' => 'data'], 'position' => 2]);
        $wifi = $version->inventoryItems()->create(['list' => 'interfaces', 'code' => 'IF-1', 'name' => 'Wi-Fi', 'attributes' => ['kind' => 'network'], 'position' => 1]);
        $version->inventoryLinks()->create(['relation' => 'asset_interface', 'from_item_id' => $vault->id, 'to_item_id' => $wifi->id]);

        $version->riskEntries()->create([
            'position' => 1, 'threat' => 'Firmware tampering', 'stride' => ['T'], 'impact' => 5, 'likelihood' => 4,
            'requirements' => ['UPD-1'], 'remediation' => 'Signed updates', 'residual_rank' => 1,
        ]);

        $pass = Evaluation::create([
            'product_version_id' => $version->id, 'standard' => 'demo-1', 'requirement' => 'ACC-1',
            'inventory_item_id' => $vault->id, 'decision_node' => 'DN-2', 'verdict' => Verdict::Pass,
            'justification' => 'Access needs a PIN.',
        ]);
        // DN-1 cannot lead to FAIL: an invalid combination
        Evaluation::create([
            'product_version_id' => $version->id, 'standard' => 'demo-1', 'requirement' => 'ACC-1',
            'inventory_item_id' => $logs->id, 'decision_node' => 'DN-1', 'verdict' => Verdict::Fail,
            'justification' => '+cmd|calc',
        ]);

        $reviewer = $this->member($version->product, ProductRole::Reviewer);
        EvaluationReview::create(['evaluation_id' => $pass->id, 'reviewer_id' => $reviewer->id, 'verdict' => 'agree', 'comment' => 'Fine.', 'fingerprint' => $pass->fingerprint()]);
        VersionReview::create(['product_version_id' => $version->id, 'reviewer_id' => $reviewer->id, 'verdict' => 'changes_requested', 'comment' => 'UPD-1 is missing.']);

        $version->einfoEntries()->create(['einfo' => 'E.Info.ACC-1.Asset', 'inventory_item_id' => $vault->id, 'text' => 'PIN on the keypad.']);
    }

    private function download(string $kind, string $format, ?User $as = null): TestResponse
    {
        return $this->as($as ?? $this->owner)->get(route('reports.download', ['version' => $this->version, 'kind' => $kind, 'format' => $format]));
    }

    /** Signed in with a fresh copy of the user (factory models lack the database defaults, such as `active`). */
    private function as(User $user): static
    {
        return $this->signIn($user->refresh());
    }

    /** The file a streamed download wrote, copied before it is deleted. */
    private function saved(TestResponse $response, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'redact-test-').'.'.$extension;
        $streamed = $response->baseResponse->getFile()->getPathname();
        copy($streamed, $path);
        // Tests never send the response, so the controller's temporary file is removed here
        @unlink($streamed);
        // REPORT_SAMPLES=<dir> keeps a copy of every file, to look at the layout by eye
        if (($samples = getenv('REPORT_SAMPLES')) && is_dir($samples)) {
            preg_match('/filename\*=UTF-8\'\'(.+)$/', (string) $response->headers->get('Content-Disposition'), $m);
            copy($path, $samples.DIRECTORY_SEPARATOR.rawurldecode($m[1] ?? basename($path)));
        }

        return $path;
    }

    /** @return array<string, string> part name => XML */
    private function docxParts(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The Word file is a zip');
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $parts[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        foreach ($parts as $name => $xml) {
            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                $this->assertNotFalse(@simplexml_load_string($xml), "{$name} is well-formed XML");
            }
        }

        return $parts;
    }

    public function test_the_reports_page_lists_the_exports(): void
    {
        $this->as($this->owner)->get(route('reports.index', $this->version))
            ->assertOk()
            ->assertSee(__('reports.kinds.assessment.label'))
            ->assertSee(__('reports.kinds.trees.label'))
            ->assertSee(__('reports.kinds.review.label'))
            ->assertSee(route('reports.download', ['version' => $this->version, 'kind' => 'assessment', 'format' => 'docx']), false);
    }

    public function test_the_assessment_word_document_is_valid_and_styled_like_crated(): void
    {
        $response = $this->download('assessment', 'docx')->assertOk();

        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment; filename="REDact - Smart lock 1.0 - Assessment.docx"', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''REDact%20-%20Smart%20lock%201.0%20-%20Assessment.docx", $disposition);

        $parts = $this->docxParts($path = $this->saved($response, 'docx'));
        $document = $parts['word/document.xml'];
        $this->assertStringContainsString('Smart lock', $document);
        $this->assertStringContainsString('DT.ACC-1.DN-2', $document);
        $this->assertStringContainsString('Access needs a PIN.', $document);
        $this->assertStringContainsString('PIN on the keypad.', $document);
        $this->assertStringContainsString('A smart lock &amp; its hub.', $document);
        $this->assertStringContainsString('Firmware tampering', $document);
        $this->assertStringContainsString(__('reports.ai_disclaimer'), $document);
        // The page, the cover's title box, the disclaimer frame, the repeated blue header rows
        $this->assertStringContainsString('<w:pgSz w:orient="portrait" w:w="11906" w:h="16838"/>', $document);
        $this->assertStringContainsString('w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"', $document);
        $this->assertStringContainsString('<w:titlePg/>', $document);
        $this->assertStringContainsString('<w:top w:val="single" w:sz="16" w:color="0044AA"/>', $document);
        $this->assertStringContainsString('w:yAlign="bottom"', $document);
        $this->assertStringContainsString('<w:tblHeader w:val="1"/>', $document);
        $this->assertStringContainsString('<w:shd w:val="clear" w:fill="0044AA"/>', $document);
        // Link-only contents: internal hyperlinks, no TOC field
        $this->assertStringContainsString('<w:hyperlink w:anchor="h_', $document);
        $this->assertStringNotContainsString('TOC ', $document);
        $this->assertStringNotContainsString(DocxFinisher::TAB, $document);

        $this->assertStringContainsString('Gill Sans MT', $parts['word/styles.xml']);
        $this->assertStringContainsString('Leelawadee UI', $parts['word/styles.xml']);
        $this->assertStringContainsString('<w:view w:val="print"/>', $parts['word/settings.xml']);
        $this->assertStringContainsString('<dc:creator>REDact</dc:creator>', $parts['docProps/core.xml']);

        $footers = implode('', array_filter($parts, fn ($name) => str_starts_with($name, 'word/footer'), ARRAY_FILTER_USE_KEY));
        $this->assertStringContainsString('Produced with REDact', $footers);
        $this->assertStringContainsString('<w:tab/>', $footers);
        $this->assertStringContainsString('PAGE', $footers);
        $this->assertStringContainsString('NUMPAGES', $footers);
        $this->assertNotEmpty(array_filter(array_keys($parts), fn ($name) => str_starts_with($name, 'word/media/')), 'The logo is embedded');

        @unlink($path);
    }

    public function test_the_trees_and_review_documents_hold_only_their_content(): void
    {
        $trees = $this->docxParts($path = $this->saved($this->download('trees', 'docx')->assertOk(), 'docx'))['word/document.xml'];
        @unlink($path);
        $this->assertStringContainsString('DT.ACC-1.DN-2', $trees);
        $this->assertStringContainsString('Access needs a PIN.', $trees);
        $this->assertStringNotContainsString('Firmware tampering', $trees);
        $this->assertStringNotContainsString('PIN on the keypad.', $trees);

        $review = $this->docxParts($path = $this->saved($this->download('review', 'docx')->assertOk(), 'docx'))['word/document.xml'];
        @unlink($path);
        $this->assertStringContainsString('UPD-1 is missing.', $review);
        $this->assertStringContainsString('Fine.', $review);
        $this->assertStringNotContainsString('Access needs a PIN.', $review);
    }

    public function test_the_workbook_has_its_sheets_brand_headers_and_strings_only(): void
    {
        $response = $this->download('assessment', 'xlsx')->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('REDact - Smart lock 1.0 - Assessment.xlsx', (string) $response->headers->get('Content-Disposition'));

        $book = IOFactory::load($path = $this->saved($response, 'xlsx'));
        $this->assertSame(
            ['Overview', 'Scoping', 'Risks', 'Assets', 'Interfaces', 'Links', 'E.DT demo-1', 'Summary DTs demo-1', 'E.Info demo-1', 'Reviews'],
            $book->getSheetNames(),
        );
        $this->assertSame('REDact', $book->getProperties()->getCreator());

        $edt = $book->getSheetByName('E.DT demo-1');
        $this->assertSame('FF0044AA', $edt->getStyle('A1')->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFFFFFFF', $edt->getStyle('A1')->getFont()->getColor()->getARGB());
        $this->assertTrue($edt->getStyle('A1')->getFont()->getBold());
        $this->assertSame('A2', $edt->getFreezePane());
        $rows = $edt->toArray(null, false, false);
        $this->assertContains('DT.ACC-1.DN-2', array_column($rows, 3));

        // Formula injection: texts that start with = or + are stored as strings
        $assets = $book->getSheetByName('Assets');
        $this->assertSame('=HYPERLINK("http://evil")', $assets->getCell('B3')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $assets->getCell('B3')->getDataType());
        $justification = null;
        foreach ($edt->getRowIterator(2) as $row) {
            $cell = $edt->getCell('F'.$row->getRowIndex());
            if ($cell->getValue() === '+cmd|calc') {
                $justification = $cell;
            }
        }
        $this->assertNotNull($justification);
        $this->assertSame(DataType::TYPE_STRING, $justification->getDataType());

        // The impossible combination DN-1 → FAIL is grey, and lists the object that claims it
        $summary = $book->getSheetByName('Summary DTs demo-1');
        $this->assertSame('FFD9DEE5', $summary->getStyle('D2')->getFill()->getStartColor()->getARGB());
        $this->assertSame('AS-2 · =HYPERLINK("http://evil")', $summary->getCell('D2')->getValue());

        $book->disconnectWorksheets();
        @unlink($path);
    }

    public function test_the_trees_and_review_workbooks_follow_their_view(): void
    {
        $book = IOFactory::load($path = $this->saved($this->download('trees', 'xlsx')->assertOk(), 'xlsx'));
        $this->assertSame(['Overview', 'E.DT demo-1', 'Summary DTs demo-1'], $book->getSheetNames());
        @unlink($path);

        $book = IOFactory::load($path = $this->saved($this->download('review', 'xlsx')->assertOk(), 'xlsx'));
        $this->assertSame(['Overview', 'Reviews', 'Assessment units'], $book->getSheetNames());
        @unlink($path);
    }

    public function test_someone_without_access_gets_a_404(): void
    {
        $stranger = $this->owner();

        $this->download('assessment', 'docx', $stranger)->assertNotFound();
        $this->download('assessment', 'xlsx', $stranger)->assertNotFound();
        $this->as($stranger)->get(route('reports.index', $this->version))->assertNotFound();
        $this->assertSame(0, Activity::where('log_name', 'report')->count());
    }

    public function test_a_reviewer_can_download_and_unknown_kinds_are_404(): void
    {
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        $this->download('review', 'docx', $reviewer)->assertOk();

        $this->as($this->owner)->get('/versions/'.$this->version->id.'/reports/everything.docx')->assertNotFound();
        $this->as($this->owner)->get('/versions/'.$this->version->id.'/reports/assessment.pdf')->assertNotFound();
    }

    public function test_an_export_is_logged(): void
    {
        $this->download('trees', 'xlsx')->assertOk();

        $activity = Activity::where('log_name', 'report')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame((string) $this->owner->id, (string) $activity->causer_id);
        $this->assertSame((string) $this->version->id, (string) $activity->subject_id);
        $this->assertSame('trees', $activity->properties['kind']);
        $this->assertSame('xlsx', $activity->properties['format']);
        $this->assertSame($this->version->id, $activity->properties['version']);
    }

    public function test_file_names_and_dispositions(): void
    {
        $this->assertSame('REDact - Smart lock 2.1 - Assessment.docx', ExportName::make(['Smart lock 2.1', 'Assessment'], 'docx'));
        $this->assertSame('REDact - a b c - Review.xlsx', ExportName::make(['a/b:c', '', null, 'Review'], 'xlsx'));
        $this->assertSame(
            "attachment; filename=\"REDact - _l_ve.docx\"; filename*=UTF-8''REDact%20-%20%C3%89l%C3%A8ve.docx",
            ReportController::disposition('REDact - Élève.docx'),
        );
    }
}
