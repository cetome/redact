<?php

namespace Tests\Feature\Workflow;

use App\Assessment\AssessmentCases;
use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Enums\VersionStatus;
use App\Models\AssessmentCaseResult;
use App\Models\AssessmentCaseReview;
use App\Models\Document;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Review = view and validate, never edit (owner, 2026-10-23): each evaluation opens a read-only
 * evidence panel (path, verdict, justification, E.Info, documents) with Agree / Disagree / Question;
 * the assessment units the manufacturer provided are validated the same way (stale once changed);
 * approval waits for every unit and for every disagreement or question to be resolved.
 */
class ReviewValidateTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private const JUSTIFICATION = 'Access needs the paired owner app, checked on the bench.';

    private User $owner;

    private User $reviewer;

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
        $this->signIn($this->owner)->put(route('versions.evaluate.update', [$this->version, 'demo-1', 'ACC-1']), [
            'target' => $this->asset->id, 'answers' => ['No', 'Yes'], 'justification' => self::JUSTIFICATION,
        ])->assertSessionHasNoErrors();
        $this->signIn($this->owner)->put(route('versions.einfo.update', [$this->version, 'demo-1']), [
            'entries' => [$this->asset->id => ['E.Info.ACC-1.Asset' => 'The key opens the door only with the paired app.']],
        ])->assertRedirect();
        $this->reviewer = $this->member($this->version->product, ProductRole::Reviewer);
    }

    public function test_the_evidence_panel_shows_everything_and_changes_nothing(): void
    {
        $document = Document::create(['product_version_id' => $this->version->id, 'uploaded_by' => $this->owner->id, 'original_name' => 'lab-report.pdf',
            'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('a', 64), 'path' => 'documents/x.pdf']);
        $this->provideUnits($this->version);
        AssessmentCaseResult::where('requirement', 'ACC-1')->where('case_key', 'functional_sufficiency')->update(['document_id' => $document->id]);
        $this->submit();
        $evaluation = Evaluation::firstOrFail();

        // The Review page: every row opens its panel, nothing links to an editor.
        $page = $this->signIn($this->reviewer)->get(route('versions.review', $this->version))->assertOk();
        $main = $this->main($page);
        $this->assertStringContainsString('href="'.route('versions.review.evidence', [$this->version, $evaluation]).'" data-row-link data-dialog-fetch="evidence"', $main);
        $this->assertStringNotContainsString('/evaluate/', $main);
        $this->assertStringNotContainsString('/assessment-units', $main);
        $this->assertStringNotContainsString('data-review-form', $main, 'The form lives in the panel.');

        // The panel (fetched in place, or the page with it open without script).
        $panel = $this->dialog($this->signIn($this->reviewer)->get(route('versions.review.evidence', [$this->version, $evaluation]))->assertOk());
        foreach (['DT.ACC-1.DN-1', 'Is the asset meant to be public?', 'Is access to the asset controlled?', self::JUSTIFICATION,
            'E.Info.ACC-1.Asset', 'The key opens the door only with the paired app.', 'lab-report.pdf',
            route('versions.documents.download', [$this->version, $document])] as $text) {
            $this->assertStringContainsString(e($text), $panel);
        }
        $this->assertStringNotContainsString('/evaluate/', $panel);
        $this->assertStringNotContainsString('/einfo/', $panel);
        $this->assertStringNotContainsString('/assessment-units', $panel);
        // The only inputs: the review (verdict, comment), posted to the review route.
        preg_match_all('/<(?:input|textarea|select)\b[^>]*\bname="([^"]+)"/', $panel, $names);
        $this->assertSame(['_token', 'comment', 'verdict'], collect($names[1])->unique()->sort()->values()->all());
        preg_match_all('/<form\b[^>]*action="([^"]+)"/', $panel, $actions);
        $this->assertSame([route('versions.review.evaluation', [$this->version, $evaluation])], $actions[1]);

        // The owner, staff-like readers: the same panel without the form.
        $owner = $this->dialog($this->signIn($this->owner)->get(route('versions.review.evidence', [$this->version, $evaluation]))->assertOk());
        $this->assertStringContainsString(e(self::JUSTIFICATION), $owner);
        $this->assertStringNotContainsString('<form', $owner);

        // An evaluation of another version is not found here.
        $other = $this->product($this->owner, '2.0');
        $this->signIn($this->reviewer)->get(route('versions.review.evidence', [$other, $evaluation]))->assertNotFound();

        // The units view of the review: read-only results, the validation form only.
        $units = $this->main($this->signIn($this->reviewer)->get(route('versions.review.units', $this->version))->assertOk());
        $this->assertStringNotContainsString('data-case-form', $units);
        $this->assertStringNotContainsString('name="evidence"', $units);
        $this->assertStringNotContainsString('/evaluate/', $units);
        $this->assertStringContainsString('Checked on the bench, as documented in the test report.', $units);
        $this->assertStringContainsString(__('assessment.self_short'), $units);
        $this->assertStringContainsString('lab-report.pdf', $units);
        $this->assertStringContainsString('data-review-form', $units);
        $this->assertStringContainsString('data-shortcut="a"', $units);
    }

    public function test_units_are_validated_and_a_change_makes_the_review_stale(): void
    {
        $this->provideUnits($this->version);
        $this->submit();
        $result = AssessmentCaseResult::where('requirement', 'ACC-1')->where('case_key', 'conceptual')->firstOrFail();

        // Only reviewers, only in review, only units of this version; disagreeing needs a reason.
        $this->unit($this->owner, $result, ['verdict' => 'agree'])->assertNotFound();
        $this->unit($this->reviewer, $result, ['verdict' => 'disagree'])->assertSessionHasErrorsIn('case-demo-1-ACC-1-conceptual', ['comment']);
        $this->unit($this->reviewer, $result, ['verdict' => 'agree'])
            ->assertRedirect(route('versions.review.units', $this->version).'#case-demo-1-ACC-1-conceptual');
        $review = AssessmentCaseReview::firstOrFail();
        $this->assertSame(['agree', false, false], [$review->verdict, $review->self_review, $review->isStale()]);
        $this->assertSame(['verdict' => 'PASS', 'evidence' => $result->evidence, 'document_id' => null], $review->snapshot);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'review', 'subject_type' => AssessmentCaseReview::class, 'subject_id' => $review->id,
            'product_version_id' => $this->version->id]);
        // The owner's history names it; reviewers never see the history.
        $this->signIn($this->owner)->get(route('products.history', $this->version->product))->assertOk()
            ->assertSee('ACC-1 · '.__('assessment.cases.conceptual'));
        $this->signIn($this->reviewer)->get(route('products.history', $this->version->product))->assertNotFound();

        // Back to the manufacturer, who changes the evidence: the review is stale and says what changed.
        $this->signIn($this->reviewer)->post(route('versions.review.decide', $this->version), ['verdict' => 'changes_requested', 'comment' => 'More detail.'])->assertRedirect();
        $this->unit($this->reviewer, $result, ['verdict' => 'agree'])->assertNotFound();
        $this->travel(1)->minutes();
        $this->signIn($this->owner)->post(route('versions.units.record', $this->version), [
            'standard' => 'demo-1', 'requirement' => 'ACC-1', 'case' => 'conceptual', 'verdict' => 'PASS',
            'evidence' => 'Every path was checked against the paired app behaviour on two samples.',
        ])->assertRedirect();
        $this->assertTrue($review->fresh()->isStale());
        $this->submit();
        $this->signIn($this->reviewer)->get(route('versions.review.units', $this->version))->assertOk()
            ->assertSee(__('review.review_again'))
            ->assertSee('data-facet-value="todo changed"', false)
            ->assertSee('<ins class="a-diff-ins">', false);
        $this->unit($this->reviewer, $result, ['verdict' => 'agree'])->assertRedirect();
        $this->assertFalse($review->fresh()->isStale());
    }

    public function test_approval_waits_for_every_unit_and_every_open_question(): void
    {
        $this->provideUnits($this->version);
        $this->submit();
        $evaluation = Evaluation::firstOrFail();

        // A unit not provided (here removed after the submission).
        $missing = AssessmentCaseResult::where('requirement', 'UPD-1')->where('case_key', 'functional_completeness')->firstOrFail();
        $missing->delete();
        $this->approve()->assertSessionHas('error', trans_choice('assessment.approve_refused_missing', 1, ['count' => 1, 'list' => 'UPD-1 · '.__('assessment.cases.functional_completeness')]))
            ->assertSessionHas('error_link.href', route('versions.review.units', $this->version).'#case-demo-1-UPD-1-functional_completeness');
        $this->provideUnits($this->version);

        // A disagreement on an evaluation, a question on a unit: refused until they are resolved.
        $this->signIn($this->reviewer)->post(route('versions.review.evaluation', [$this->version, $evaluation]), ['verdict' => 'disagree', 'comment' => 'The app is not the only way in.'])->assertRedirect();
        $unit = AssessmentCaseResult::where('requirement', 'ACC-1')->where('case_key', 'conceptual')->firstOrFail();
        $this->unit($this->reviewer, $unit, ['verdict' => 'question', 'comment' => 'Which samples?'])->assertRedirect();
        $this->approve()->assertSessionHas('error', trans_choice('assessment.approve_refused_open', 2, ['count' => 2, 'list' => 'ACC-1 · '.$this->asset->label().', ACC-1 · '.__('assessment.cases.conceptual')]))
            ->assertSessionHas('error_link.href', route('versions.review.evidence', [$this->version, $evaluation]));
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);

        $this->signIn($this->reviewer)->post(route('versions.review.evaluation', [$this->version, $evaluation]), ['verdict' => 'agree'])->assertRedirect();
        $this->unit($this->reviewer, $unit, ['verdict' => 'agree'])->assertRedirect();
        $this->approve()->assertSessionHas('status', __('review.decision_saved'));
        $this->assertSame(VersionStatus::Approved, $this->version->fresh()->status);
        $this->assertSame(Verdict::Pass, $unit->fresh()->verdict, 'A review never changes the assessment.');
    }

    public function test_submitting_without_the_units_warns_and_reviewers_validate_the_evaluations_meanwhile(): void
    {
        // Never refused: a warning, a link to the first unit, and "outside REDact" offered (POST, path only).
        $this->signIn($this->owner)->from(route('versions.show', $this->version))->post(route('versions.submit', $this->version))
            ->assertRedirect(route('versions.show', $this->version))
            ->assertSessionMissing('error')
            ->assertSessionHas('warning', trans_choice('assessment.submitted_units_missing', 5, ['count' => 5]))
            ->assertSessionHas('warning_link.href', route('versions.units', $this->version).'#case-demo-1-ACC-1-conceptual')
            ->assertSessionHas('warning_post', ['action' => route('versions.units.where', $this->version), 'fields' => ['where' => 'external'], 'label' => __('assessment.where.lab_action')]);
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);

        // The units tab says none is provided yet; the reviewer agrees on the evaluation all the same.
        $this->signIn($this->reviewer)->get(route('versions.review.units', $this->version))->assertOk()
            ->assertSee(trans_choice('assessment.none_yet', 5, ['count' => 5]));
        $evaluation = Evaluation::firstOrFail();
        $this->signIn($this->reviewer)->post(route('versions.review.evaluation', [$this->version, $evaluation]), ['verdict' => 'agree'])
            ->assertSessionHas('status', __('review.saved'));

        // The team provides a unit in review (no "locked" notice on that step): it shows in the units tab to validate.
        $this->signIn($this->owner)->get(route('versions.units', $this->version))->assertOk()
            ->assertSee('data-case-form', false)->assertDontSee(__('versions.locked', ['status' => mb_strtolower(VersionStatus::InReview->label())]));
        $this->signIn($this->owner)->post(route('versions.units.record', $this->version), [
            'standard' => 'demo-1', 'requirement' => 'ACC-1', 'case' => 'conceptual', 'verdict' => 'PASS', 'evidence' => self::JUSTIFICATION,
        ])->assertRedirect();
        $result = AssessmentCaseResult::firstOrFail();
        $this->signIn($this->reviewer)->get(route('versions.review.units', $this->version))->assertOk()
            ->assertDontSee(trans_choice('assessment.none_yet', 5, ['count' => 5]))
            ->assertSee('id="'.AssessmentCases::anchor('demo-1', 'ACC-1', 'conceptual').'"', false);
        $this->unit($this->reviewer, $result, ['verdict' => 'agree'])->assertRedirect();

        // Approval waits for the four others (in REDact).
        $this->approve()->assertSessionHas('error', trans_choice('assessment.approve_refused_missing', 4, ['count' => 4,
            'list' => 'ACC-1 · '.__('assessment.cases.functional_completeness').', ACC-1 · '.__('assessment.cases.functional_sufficiency')
                .', UPD-1 · '.__('assessment.cases.conceptual').', UPD-1 · '.__('assessment.cases.functional_completeness')]));
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);
    }

    private function submit(): void
    {
        $this->signIn($this->owner)->post(route('versions.submit', $this->version))->assertRedirect()->assertSessionMissing('error');
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);
    }

    private function approve(): TestResponse
    {
        return $this->signIn($this->reviewer)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])->assertRedirect();
    }

    /** @param array<string, string> $data */
    private function unit(User $user, AssessmentCaseResult $result, array $data): TestResponse
    {
        return $this->signIn($user)->from(route('versions.review.units', $this->version))->post(route('versions.review.unit', [$this->version, $result]), $data);
    }

    /** The page's own content: the sidebar of steps (navigation) left out. */
    private function main(TestResponse $response): string
    {
        $html = (string) $response->getContent();
        $this->assertSame(1, preg_match('#<main id="main".*?</main>#s', $html, $m));

        return $m[0];
    }

    private function dialog(TestResponse $response): string
    {
        $html = (string) $response->getContent();
        $this->assertSame(1, preg_match('#<dialog id="evidence".*?</dialog>#s', $html, $m));

        return $m[0];
    }
}
