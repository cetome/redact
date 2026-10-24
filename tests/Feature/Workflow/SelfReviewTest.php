<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\ReviewRights;
use App\Enums\ProductRole;
use App\Enums\VersionStatus;
use App\Models\AssessmentCaseResult;
use App\Models\AssessmentCaseReview;
use App\Models\Evaluation;
use App\Models\EvaluationReview;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Models\VersionReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Self-review (owner, 2026-10-23): without a reviewer on the product, its owner and the organisation's
 * admins submit and review the version themselves, behind a warning that an independent reviewer is
 * better; every such review is flagged and shown "Self-reviewed". Once a reviewer is a member, only
 * reviewers review, and the self-reviews no longer count.
 */
class SelfReviewTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

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
        $this->signIn($this->owner)->put(route('versions.evaluate.update', [$this->version, 'demo-1', 'ACC-1']), [
            'target' => $this->asset->id, 'answers' => ['No', 'Yes'], 'justification' => 'Access needs the paired owner app.',
        ])->assertSessionHasNoErrors();
        $this->provideUnits($this->version);
    }

    public function test_without_a_reviewer_the_owner_reviews_it_behind_a_warning_and_it_shows_self_reviewed(): void
    {
        // The Overview offers to submit; submitting leads to the Review page.
        $this->signIn($this->owner)->get(route('versions.show', $this->version))->assertOk()->assertSee(route('versions.submit', $this->version), false);
        $this->signIn($this->owner)->post(route('versions.submit', $this->version))
            ->assertRedirect(route('versions.review', $this->version))->assertSessionHas('status', __('review.submitted_self'));
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);

        $this->signIn($this->owner)->get(route('versions.review', $this->version))->assertOk()
            ->assertSee(__('review.self_warning'))
            ->assertSee(route('team.reviewer', $this->version->product), false)
            ->assertSee(__('review.self_review'))
            ->assertSee(route('versions.review.decide', $this->version), false);

        $evaluation = Evaluation::firstOrFail();
        $this->signIn($this->owner)->get(route('versions.review.evidence', [$this->version, $evaluation]))->assertOk()->assertSee('data-review-form', false);
        $this->signIn($this->owner)->post(route('versions.review.evaluation', [$this->version, $evaluation]), ['verdict' => 'agree'])->assertRedirect();
        $result = AssessmentCaseResult::where('requirement', 'ACC-1')->where('case_key', 'conceptual')->firstOrFail();
        $this->signIn($this->owner)->post(route('versions.review.unit', [$this->version, $result]), ['verdict' => 'agree'])->assertRedirect();
        $this->signIn($this->owner)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])->assertSessionHas('status');

        // Approved by self-review, and every review is flagged.
        $this->assertSame(VersionStatus::Approved, $this->version->fresh()->status);
        $this->assertTrue(EvaluationReview::firstOrFail()->self_review);
        $this->assertTrue(AssessmentCaseReview::firstOrFail()->self_review);
        $this->assertTrue(VersionReview::firstOrFail()->self_review);
        $this->assertTrue(ReviewRights::selfApproved($this->version->fresh()));

        // "Self-reviewed" wherever the review status shows: Review, Overview, steps, declaration, technical file, reports.
        $this->signIn($this->owner)->get(route('versions.review', $this->version))->assertOk()->assertSee(__('review.self_reviewed'));
        $this->signIn($this->owner)->get(route('versions.show', $this->version))->assertOk()->assertSee(__('review.self_reviewed'));
        $step = collect(Assessment::for($this->version->fresh())->steps())->firstWhere('key', 'review');
        $this->assertStringContainsString(__('review.self_reviewed'), $step['detail']);
        $this->signIn($this->owner)->get(route('versions.declaration', $this->version))->assertOk()
            ->assertSee(__('declaration.checks.approved_self'));
        $this->signIn($this->owner)->get(route('versions.declaration-file', $this->version))->assertOk()
            ->assertSee(__('declaration.file.notes.review').' · '.__('review.self_reviewed'));
        $response = $this->signIn($this->owner)->get(route('reports.download', ['version' => $this->version, 'kind' => 'review', 'format' => 'xlsx']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'redact-test-').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $path);
        @unlink($response->baseResponse->getFile()->getPathname());
        $rows = IOFactory::load($path)->getSheetByName(__('reports.sheets.reviews'))->toArray();
        @unlink($path);
        $this->assertContains($this->owner->name.' · '.__('review.self_reviewed'), array_column($rows, 0));
    }

    public function test_an_organisation_admin_may_self_review_a_contributor_never(): void
    {
        $contributor = $this->member($this->version->product, ProductRole::Contributor);
        $admin = User::factory()->create();
        $this->version->product->organisation->members()->attach($admin->id, ['role' => 'admin']);
        $this->signIn($this->owner)->post(route('versions.submit', $this->version))->assertRedirect();
        $evaluation = Evaluation::firstOrFail();

        $this->signIn($contributor)->post(route('versions.review.evaluation', [$this->version, $evaluation]), ['verdict' => 'agree'])->assertNotFound();
        $this->signIn($contributor)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])->assertNotFound();
        $this->signIn($contributor)->get(route('versions.review', $this->version))->assertOk()
            ->assertSee(__('review.self_warning'))->assertDontSee(route('versions.review.decide', $this->version), false);

        $this->signIn($admin)->post(route('versions.review.evaluation', [$this->version, $evaluation]), ['verdict' => 'question', 'comment' => 'Which app version?'])->assertRedirect();
        $this->assertTrue(EvaluationReview::where('reviewer_id', $admin->id)->firstOrFail()->self_review);
        // Its own question holds the approval until resolved.
        $this->signIn($admin)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])
            ->assertSessionHas('error', trans_choice('assessment.approve_refused_open', 1, ['count' => 1, 'list' => 'ACC-1 · '.$this->asset->label()]));
    }

    public function test_once_a_reviewer_exists_only_reviewers_review_and_self_reviews_no_longer_count(): void
    {
        $this->signIn($this->owner)->post(route('versions.submit', $this->version))->assertRedirect();
        $evaluation = Evaluation::firstOrFail();
        $this->signIn($this->owner)->post(route('versions.review.evaluation', [$this->version, $evaluation]), ['verdict' => 'disagree', 'comment' => 'Not sure about the app.'])->assertRedirect();

        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);

        // The owner's review buttons are gone, the warning too; the self-review stays shown as such.
        $panel = $this->signIn($this->owner)->get(route('versions.review.evidence', [$this->version, $evaluation]))->assertOk();
        $panel->assertDontSee('data-review-form', false)->assertDontSee(__('review.self_warning'))
            ->assertDontSee(route('versions.review.decide', $this->version), false)
            ->assertSee(__('review.self_reviewed'))->assertSee(__('review.self_not_counted'));
        $this->signIn($this->owner)->post(route('versions.review.evaluation', [$this->version, $evaluation]), ['verdict' => 'agree'])->assertNotFound();
        $this->signIn($this->owner)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])->assertNotFound();

        // The owner's disagreement no longer counts: the reviewer approves.
        $this->signIn($reviewer)->get(route('versions.review.evidence', [$this->version, $evaluation]))->assertOk()->assertSee('data-review-form', false);
        $this->signIn($reviewer)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])->assertSessionHas('status');
        $this->assertSame(VersionStatus::Approved, $this->version->fresh()->status);
        $this->assertFalse(VersionReview::firstOrFail()->self_review);
        $this->assertFalse(ReviewRights::selfApproved($this->version->fresh()));
    }

    public function test_with_a_reviewer_the_submission_stays_on_its_page(): void
    {
        $this->member($this->version->product, ProductRole::Reviewer);
        $this->signIn($this->owner)->from(route('versions.show', $this->version))->post(route('versions.submit', $this->version))
            ->assertRedirect(route('versions.show', $this->version))->assertSessionHas('status', __('versions.submitted'));
        $this->signIn($this->owner)->get(route('versions.review', $this->version))->assertOk()->assertDontSee(__('review.self_warning'));
    }
}
