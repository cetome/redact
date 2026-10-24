<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\AssessmentCases;
use App\Assessment\VersionFactory;
use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Enums\VersionStatus;
use App\Models\AssessmentCaseResult;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * The "Assessment units" step: the manufacturer provides the result of each assessment unit with its
 * evidence (fixture criteria: ACC-1 has the three cases, UPD-1 a conceptual case without NOT
 * APPLICABLE and a functional completeness case). Reviewers validate them, never record them.
 */
class AssessmentCasesTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private const EVIDENCE = 'Checked the pairing flow on the bench: access refused without the app.';

    private User $owner;

    private ProductVersion $version;

    private InventoryItem $asset;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = $this->owner();
        $this->version = $this->product($this->owner);
        $this->version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $this->asset = $this->version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Door key', 'attributes' => ['type' => 'security']]);
        $this->walk('ACC-1', $this->asset, ['No', 'Yes']);
        $this->walk('UPD-1', null, ['Yes']);
    }

    public function test_a_verdict_is_never_recorded_without_its_evidence(): void
    {
        $this->record($this->owner, ['verdict' => 'PASS'])
            ->assertSessionHasErrorsIn('case-demo-1-ACC-1-conceptual', ['evidence' => __('assessment.evidence_required')]);
        $this->record($this->owner, ['verdict' => 'PASS', 'evidence' => '   too short    '])
            ->assertSessionHasErrorsIn('case-demo-1-ACC-1-conceptual', ['evidence' => __('assessment.evidence_min', ['min' => 20])]);
        $this->record($this->owner, ['evidence' => self::EVIDENCE])
            ->assertSessionHasErrorsIn('case-demo-1-ACC-1-conceptual', ['verdict']);
        // A verdict the case does not give (UPD-1 conceptual has no NOT APPLICABLE), an unknown case.
        $this->record($this->owner, ['requirement' => 'UPD-1', 'verdict' => 'NOT_APPLICABLE', 'evidence' => self::EVIDENCE])
            ->assertSessionHasErrorsIn('case-demo-1-UPD-1-conceptual', ['verdict']);
        $this->record($this->owner, ['requirement' => 'UPD-1', 'case' => 'functional_sufficiency', 'verdict' => 'PASS', 'evidence' => self::EVIDENCE])->assertNotFound();
        $this->record($this->owner, ['requirement' => 'NOPE-1', 'verdict' => 'PASS', 'evidence' => self::EVIDENCE])->assertNotFound();
        $this->assertSame(0, AssessmentCaseResult::count());

        $this->record($this->owner, ['verdict' => 'PASS', 'evidence' => self::EVIDENCE])
            ->assertRedirect(route('versions.units', $this->version).'#case-demo-1-ACC-1-conceptual')
            ->assertSessionHas('status', __('assessment.saved'));
        $result = AssessmentCaseResult::firstOrFail();
        $this->assertSame(Verdict::Pass, $result->verdict);
        $this->assertSame(self::EVIDENCE, $result->evidence);
        $this->assertFalse($result->independent);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'assessment_case', 'subject_id' => $result->id]);
    }

    public function test_the_manufacturer_provides_the_units_never_a_reviewer(): void
    {
        $contributor = $this->member($this->version->product, ProductRole::Contributor);
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        $admin = User::factory()->create();
        $this->version->product->organisation->members()->attach($admin->id, ['role' => 'admin']);

        // Owner, contributor and organisation admin record on a draft: not independent.
        $this->record($contributor, ['verdict' => 'PASS', 'evidence' => self::EVIDENCE])->assertRedirect();
        $this->record($admin, ['case' => 'functional_completeness', 'verdict' => 'PASS', 'evidence' => self::EVIDENCE])->assertRedirect();
        $this->assertSame(2, AssessmentCaseResult::where('independent', false)->count());
        $this->assertSame($admin->id, AssessmentCaseResult::where('case_key', 'functional_completeness')->value('recorded_by'));

        // A reviewer never records, neither on the draft nor in review. In review the units stay open
        // to the team (the lock exception: things are not sequential); nothing once approved.
        $this->record($reviewer, ['case' => 'functional_sufficiency', 'verdict' => 'PASS', 'evidence' => self::EVIDENCE])->assertNotFound();
        $this->provideUnits($this->version);
        $this->signIn($this->owner)->post(route('versions.submit', $this->version))->assertRedirect();
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);
        $this->record($reviewer, ['verdict' => 'FAIL', 'evidence' => self::EVIDENCE])->assertNotFound();
        $this->record($this->owner, ['verdict' => 'FAIL', 'evidence' => self::EVIDENCE])->assertRedirect()->assertSessionHas('status', __('assessment.saved'));
        $this->assertSame(Verdict::Fail, AssessmentCaseResult::where('case_key', 'conceptual')->where('requirement', 'ACC-1')->firstOrFail()->verdict);
        $this->version->forceFill(['status' => VersionStatus::Approved])->save();
        $this->record($this->owner, ['verdict' => 'PASS', 'evidence' => self::EVIDENCE])->assertNotFound();
        $this->version->forceFill(['status' => VersionStatus::InReview])->save();

        // The step shows that the results are not independent; read-only once in review.
        $this->signIn($reviewer)->get(route('versions.units', $this->version))->assertOk()
            ->assertSee(__('assessment.self_short'))->assertDontSee('data-case-form', false);
    }

    public function test_who_may_record_and_see(): void
    {
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        $staff = User::factory()->create();
        Role::findOrCreate('staff', 'web');
        $staff->assignRole('staff');
        $outsider = User::factory()->create();

        $this->signIn($outsider)->get(route('versions.units', $this->version))->assertNotFound();
        $this->record($outsider, ['verdict' => 'PASS', 'evidence' => self::EVIDENCE])->assertNotFound();
        $this->record($this->owner(), ['verdict' => 'PASS', 'evidence' => self::EVIDENCE])->assertNotFound();

        // Staff (support access) and reviewers look, read-only. The owner records, with the keyboard shortcuts.
        $this->signIn($staff)->get(route('versions.units', $this->version))->assertOk()
            ->assertSee(__('common.support_access'))->assertDontSee('data-case-form', false);
        $this->record($staff, ['verdict' => 'PASS', 'evidence' => self::EVIDENCE])->assertNotFound();
        $this->signIn($reviewer)->get(route('versions.units', $this->version))->assertOk()
            ->assertSee(__('assessment.read_only'))->assertDontSee('data-case-form', false);
        $this->signIn($this->owner)->get(route('versions.units', $this->version))->assertOk()
            ->assertSee('data-case-form', false)
            ->assertSee('data-shortcut="1"', false)
            ->assertSee('data-shortcut="mod+s"', false)
            ->assertSee('minlength="20"', false);
        $this->assertSame(0, AssessmentCaseResult::count());
    }

    public function test_the_conceptual_verdict_is_proposed_from_the_trees(): void
    {
        $this->assertSame(Verdict::Pass, $this->suggestion('ACC-1')['verdict']);

        // A justification too short: FAIL with its reason.
        $this->walk('ACC-1', $this->asset, ['No', 'Yes'], 'Too short.');
        $suggestion = $this->suggestion('ACC-1');
        $this->assertSame(Verdict::Fail, $suggestion['verdict']);
        $this->assertSame([trans_choice('assessment.suggest.unjustified', 1, ['count' => 1])], $suggestion['reasons']);

        // A path ending FAIL.
        $this->walk('UPD-1', null, ['No']);
        $this->signIn($this->owner)->put(route('versions.einfo.update', [$this->version, 'demo-1']), [
            'entries' => ['product' => ['E.Info.UPD-1.Product' => 'No update mechanism at all.']],
        ])->assertRedirect();
        $this->assertSame(Verdict::Fail, $this->suggestion('UPD-1')['verdict']);

        // NOT APPLICABLE and justified.
        $this->walk('ACC-1', $this->asset, ['Yes']);
        $this->signIn($this->owner)->put(route('versions.einfo.update', [$this->version, 'demo-1']), [
            'entries' => [$this->asset->id => ['E.Info.ACC-1.Asset' => 'The key is public by design.']],
        ])->assertRedirect();
        $this->assertSame(Verdict::NotApplicable, $this->suggestion('ACC-1')['verdict']);

        // A tree still to walk: INCONCLUSIVE.
        $this->version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-02', 'name' => 'Spare key', 'attributes' => ['type' => 'security']]);
        $this->assertSame(Verdict::Inconclusive, $this->suggestion('ACC-1')['verdict']);

        $this->signIn($this->owner)->get(route('versions.units', $this->version))->assertOk()
            ->assertSee(__('assessment.suggested'))
            // Named after the list walked, never "objects".
            ->assertSee(trans_choice('assessment.suggest.pending', 1, ['what' => '1 asset']));
    }

    public function test_the_review_page_counts_the_cases(): void
    {
        $this->record($this->owner, ['verdict' => 'FAIL', 'evidence' => self::EVIDENCE])->assertRedirect();
        $overview = AssessmentCases::for(Assessment::for($this->version->fresh()))->overview();
        $this->assertSame(['total' => 5, 'recorded' => 1, 'fail' => 1, 'inconclusive' => 0, 'self' => 1, 'stale' => 0], $overview['counts']);

        $page = $this->signIn($this->owner)->get(route('versions.units', $this->version))->assertOk();
        $page->assertSee(__('assessment.cases.functional_sufficiency'))
            ->assertSee('Sample purpose: try to bypass the control.')
            ->assertSee('data-facet-value="fail"', false);
        // The step in the sidebar and the Overview: recorded / total, not done.
        $this->assertStringContainsString('<span class="a-step-count">1/5</span>', (string) $page->getContent());
        $step = collect(Assessment::for($this->version->fresh())->steps())->firstWhere('key', 'units');
        $this->assertFalse($step['done']);
        $this->assertSame([1, 5], [$step['done_count'], $step['total']]);
        // The Review step: the evaluations view stays the default, the units tab counts them.
        $this->signIn($this->owner)->get(route('versions.review', $this->version))->assertOk()
            ->assertSee('<span class="a-tab-count">1/5</span>', false)
            ->assertSee('data-review', false)->assertDontSee('data-cases', false);

        // An evaluation changed after the case was recorded.
        $this->travel(1)->minutes();
        $this->walk('ACC-1', $this->asset, ['No', 'Yes'], 'Access needs the paired owner app, checked again.');
        $this->assertSame(1, AssessmentCases::for(Assessment::for($this->version->fresh()))->overview()['counts']['stale']);
    }

    public function test_a_new_version_does_not_copy_the_case_results(): void
    {
        $this->record($this->owner, ['verdict' => 'PASS', 'evidence' => self::EVIDENCE])->assertRedirect();
        $copy = app(VersionFactory::class)->create($this->version->product, '2.0', $this->owner, $this->version);

        $this->assertSame(1, $this->version->caseResults()->count());
        $this->assertSame(0, $copy->caseResults()->count());
        $this->assertSame(2, $copy->evaluations()->count());
    }

    public function test_the_review_report_lists_the_cases_and_the_assessment_report_the_justification(): void
    {
        $this->record($this->owner, ['verdict' => 'PASS', 'evidence' => self::EVIDENCE])->assertRedirect();
        $this->walk('UPD-1', null, ['Yes'], 'Short.');

        $xlsx = $this->download('review', 'xlsx');
        $book = IOFactory::load($xlsx);
        $sheet = $book->getSheetByName(__('assessment.report.sheet'));
        $this->assertNotNull($sheet);
        $rows = $sheet->toArray();
        $this->assertSame(['demo-1', 'ACC-1', __('assessment.cases.conceptual'), '1.1.4.1', __('verdicts.PASS'), self::EVIDENCE], array_slice($rows[1], 0, 6));
        $this->assertSame(__('common.no'), $rows[1][9]);
        $this->assertSame(__('assessment.report.not_recorded'), $rows[2][4]);
        @unlink($xlsx);

        $docx = $this->download('review', 'docx');
        $zip = new \ZipArchive;
        $zip->open($docx);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($docx);
        $this->assertStringContainsString(__('assessment.report.cases'), $xml);
        $this->assertStringContainsString(self::EVIDENCE, $xml);
        $this->assertStringContainsString(e(__('assessment.self')), $xml);

        $xlsx = $this->download('assessment', 'xlsx');
        $rows = IOFactory::load($xlsx)->getSheetByName('E.DT demo-1')->toArray();
        @unlink($xlsx);
        $justified = array_column(array_slice($rows, 1), 6, 0);
        $this->assertSame(__('assessment.report.justified'), $justified['ACC-1']);
        $this->assertSame(__('assessment.report.not_justified'), $justified['UPD-1']);
    }

    /** @param list<string> $answers */
    private function walk(string $requirement, ?InventoryItem $item, array $answers, string $justification = 'Access needs the paired owner app.'): void
    {
        $this->signIn($this->owner)->put(route('versions.evaluate.update', [$this->version, 'demo-1', $requirement]), [
            'target' => $item->id ?? 'product', 'answers' => $answers, 'justification' => $justification,
        ])->assertSessionHasNoErrors();
    }

    /** @param array<string, string> $data */
    private function record(User $user, array $data): TestResponse
    {
        return $this->signIn($user->refresh())->from(route('versions.units', $this->version))
            ->post(route('versions.units.record', $this->version), $data + ['standard' => 'demo-1', 'requirement' => 'ACC-1', 'case' => 'conceptual']);
    }

    /** @return array{verdict: Verdict|null, reasons: list<string>} */
    private function suggestion(string $requirement): array
    {
        $cases = AssessmentCases::for(Assessment::for($this->version->fresh()));

        return $cases->suggestion($cases->summary('demo-1', $requirement));
    }

    private function download(string $kind, string $format): string
    {
        $response = $this->signIn($this->owner->refresh())->get(route('reports.download', ['version' => $this->version, 'kind' => $kind, 'format' => $format]))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'redact-test-').'.'.$format;
        $streamed = $response->baseResponse->getFile()->getPathname();
        copy($streamed, $path);
        @unlink($streamed);

        return $path;
    }
}
