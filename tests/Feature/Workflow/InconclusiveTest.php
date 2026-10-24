<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\AssessmentCases;
use App\Assessment\Conformity;
use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Enums\VersionStatus;
use App\Models\AssessmentCaseResult;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Models\VersionReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * INCONCLUSIVE: not a verdict of EN 18031, set by a person while evidence is missing, always with a
 * justification; counted on its own, pending for conformity, blocks the approval, not the submission.
 */
class InconclusiveTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private const WHY = 'Waiting for the pairing test report from the lab.';

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
        $this->save('UPD-1', null, ['answers' => ['Yes'], 'justification' => 'Signed updates over the air, checked.'])->assertSessionHasNoErrors();
    }

    public function test_an_evaluation_is_marked_inconclusive_only_with_its_justification_and_keeps_the_path(): void
    {
        $this->save('ACC-1', $this->asset, ['inconclusive' => '1', 'partial' => ['No'], 'justification' => 'Lab report.'])
            ->assertSessionHasErrors(['justification' => __('assessment.inconclusive_needs_reason', ['min' => 20])]);
        $this->assertSame(1, Evaluation::count());

        // A tree outcome can never be INCONCLUSIVE.
        $this->save('ACC-1', $this->asset, ['decision_node' => 'DN-2', 'verdict' => 'INCONCLUSIVE', 'justification' => self::WHY])
            ->assertSessionHasErrors('verdict');

        $this->save('ACC-1', $this->asset, ['inconclusive' => '1', 'partial' => ['No'], 'justification' => self::WHY])
            ->assertSessionHasNoErrors()->assertSessionHas('status', __('assessment.inconclusive_saved'));
        $evaluation = Evaluation::where('requirement', 'ACC-1')->firstOrFail();
        $this->assertSame(Verdict::Inconclusive, $evaluation->verdict);
        $this->assertSame('DN-1', $evaluation->decision_node);
        $this->assertSame([['node' => 'DN-1', 'answer' => 'No']], $evaluation->path);

        // Expert entry: the decision node chosen is kept.
        $this->save('ACC-1', $this->asset, ['inconclusive' => '1', 'decision_node' => 'DN-2', 'justification' => self::WHY])->assertSessionHasNoErrors();
        $this->assertSame('DN-2', $evaluation->fresh()->decision_node);

        $this->signIn($this->owner)->get(route('versions.evaluate.target', [$this->version, 'demo-1', 'ACC-1', $this->asset->id]))
            ->assertOk()
            ->assertSee(__('assessment.inconclusive_action'))
            ->assertSee(__('assessment.inconclusive_note'))
            ->assertSee(__('evaluations.status.inconclusive'));
    }

    public function test_inconclusive_is_counted_apart_and_pending_for_conformity(): void
    {
        $this->save('ACC-1', $this->asset, ['inconclusive' => '1', 'partial' => ['No'], 'justification' => self::WHY])->assertSessionHasNoErrors();
        $assessment = Assessment::for($this->version->fresh());

        $progress = $assessment->evaluationProgress('demo-1');
        $this->assertSame(['total' => 2, 'done' => 1, 'inconclusive' => 1, 'pass' => 1, 'fail' => 0, 'na' => 0],
            array_intersect_key($progress, array_flip(['total', 'done', 'inconclusive', 'pass', 'fail', 'na'])));
        $evaluation = Evaluation::where('requirement', 'ACC-1')->firstOrFail();
        $this->assertSame('inconclusive', $assessment->status($evaluation, $assessment->pack->tree('demo-1', 'ACC-1')));
        $check = $assessment->justification()->check($evaluation);
        $this->assertTrue($check->inconclusive);
        $this->assertFalse($check->justified);
        $this->assertNull((new Conformity($assessment))->en18031Fully());
        $this->assertSame([], $assessment->unjustified());

        // The conceptual case is proposed INCONCLUSIVE.
        $cases = AssessmentCases::for($assessment);
        $this->assertSame(Verdict::Inconclusive, $cases->suggestion($cases->summary('demo-1', 'ACC-1'))['verdict']);

        $this->signIn($this->owner)->get(route('versions.evaluate', [$this->version, 'demo-1']))->assertOk()
            ->assertSee('data-facet="inconclusive"', false)->assertSee(__('verdicts.INCONCLUSIVE'));
    }

    public function test_submitting_warns_and_approving_waits_for_inconclusive_items(): void
    {
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        $this->save('ACC-1', $this->asset, ['inconclusive' => '1', 'partial' => ['No'], 'justification' => self::WHY])->assertSessionHasNoErrors();

        $this->provideUnits($this->version);
        $this->signIn($this->owner)->post(route('versions.submit', $this->version))->assertRedirect()
            ->assertSessionHas('warning', trans_choice('assessment.submitted_with_inconclusive', 1, ['count' => 1, 'list' => 'ACC-1 · '.$this->asset->label()]));
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);

        $this->signIn($reviewer)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])->assertRedirect()
            ->assertSessionHas('error', trans_choice('assessment.approve_refused', 1, ['count' => 1, 'list' => 'ACC-1 · '.$this->asset->label()]))
            ->assertSessionHas('error_link.href', route('versions.review.evidence', [$this->version, Evaluation::where('requirement', 'ACC-1')->firstOrFail()]));
        $this->assertSame(0, VersionReview::count());
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);

        // Requesting changes stays possible.
        $this->signIn($reviewer)->post(route('versions.review.decide', $this->version), ['verdict' => 'changes_requested', 'comment' => 'Resolve ACC-1.'])->assertRedirect();
        $this->assertSame(VersionStatus::Draft, $this->version->fresh()->status);
    }

    public function test_an_inconclusive_unit_needs_evidence_and_blocks_the_approval(): void
    {
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        $this->save('ACC-1', $this->asset, ['answers' => ['No', 'Yes'], 'justification' => 'Access needs the paired owner app.'])->assertSessionHasNoErrors();

        // The manufacturer provides it INCONCLUSIVE (with what is missing), the others PASS.
        $case = ['standard' => 'demo-1', 'requirement' => 'ACC-1', 'case' => 'functional_sufficiency', 'verdict' => 'INCONCLUSIVE'];
        $this->signIn($this->owner)->post(route('versions.units.record', $this->version), $case)
            ->assertSessionHasErrorsIn('case-demo-1-ACC-1-functional_sufficiency', ['evidence']);
        $this->signIn($this->owner)->post(route('versions.units.record', $this->version), $case + ['evidence' => self::WHY])->assertRedirect();
        $this->assertSame(Verdict::Inconclusive, AssessmentCaseResult::firstOrFail()->verdict);
        $this->signIn($this->owner)->get(route('versions.units', $this->version))->assertOk()
            ->assertSee('data-case-verdict="4"', false)
            ->assertSee('data-facet-value="inconclusive"', false);
        // Not done while a unit is INCONCLUSIVE, even with every unit provided.
        $this->provideUnits($this->version);
        $this->assertFalse(collect(Assessment::for($this->version->fresh())->steps())->firstWhere('key', 'units')['done']);

        $this->signIn($this->owner)->post(route('versions.submit', $this->version))->assertRedirect()
            ->assertSessionHas('warning', trans_choice('assessment.submitted_with_inconclusive', 1, ['count' => 1, 'list' => 'ACC-1 · '.__('assessment.cases.functional_sufficiency')]));
        $this->signIn($reviewer)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])
            ->assertSessionHas('error', trans_choice('assessment.approve_refused', 1, ['count' => 1, 'list' => 'ACC-1 · '.__('assessment.cases.functional_sufficiency')]))
            ->assertSessionHas('error_link.href', route('versions.review.units', $this->version).'#case-demo-1-ACC-1-functional_sufficiency');
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);

        // Back to the manufacturer, resolved, submitted again: approved.
        $this->signIn($reviewer)->post(route('versions.review.decide', $this->version), ['verdict' => 'changes_requested', 'comment' => 'Resolve ACC-1.'])->assertRedirect();
        $this->signIn($this->owner)->post(route('versions.units.record', $this->version), ['verdict' => 'PASS'] + $case + ['evidence' => 'Tried to open it without the app: refused.'])->assertRedirect();
        $this->assertTrue(collect(Assessment::for($this->version->fresh())->steps())->firstWhere('key', 'units')['done']);
        $this->signIn($this->owner)->post(route('versions.submit', $this->version))->assertRedirect()->assertSessionMissing('warning');
        $this->signIn($reviewer)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])->assertSessionHas('status');
        $this->assertSame(VersionStatus::Approved, $this->version->fresh()->status);
    }

    public function test_reports_show_inconclusive_with_its_justification_and_count_it(): void
    {
        $this->save('ACC-1', $this->asset, ['inconclusive' => '1', 'partial' => ['No'], 'justification' => self::WHY])->assertSessionHasNoErrors();

        $path = $this->download('assessment', 'xlsx');
        $book = IOFactory::load($path);
        $rows = $book->getSheetByName('E.DT demo-1')->toArray();
        $acc = collect($rows)->first(fn ($r) => $r[0] === 'ACC-1');
        $this->assertSame(__('verdicts.INCONCLUSIVE'), $acc[4]);
        $this->assertSame(self::WHY, $acc[5]);
        $overview = $book->getSheet(0)->toArray();
        $header = collect($overview)->first(fn ($r) => in_array(__('reports.buckets.inconclusive'), $r, true));
        $this->assertNotNull($header);
        $at = array_search(__('reports.buckets.inconclusive'), $header, true);
        $line = collect($overview)->first(fn ($r) => ($r[0] ?? null) === 'DEMO-1 (sample standard)');
        $this->assertSame(1, (int) $line[$at]);
        @unlink($path);

        $path = $this->download('assessment', 'docx');
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString(__('verdicts.INCONCLUSIVE'), $xml);
        $this->assertStringContainsString(self::WHY, $xml);
    }

    /** @param array<string, mixed> $data */
    private function save(string $requirement, ?InventoryItem $item, array $data): TestResponse
    {
        return $this->signIn($this->owner)->put(route('versions.evaluate.update', [$this->version, 'demo-1', $requirement]), $data + ['target' => $item->id ?? 'product']);
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
