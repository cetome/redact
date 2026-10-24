<?php

namespace Tests\Feature\Workflow;

use App\Assessment\ReviewDiff;
use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Enums\VersionStatus;
use App\Models\Evaluation;
use App\Models\EvaluationReview;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * "What changed since the last review": snapshot at review time, the diff, the filter.
 */
class ReviewChangesTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_a_review_stores_a_snapshot_of_what_was_reviewed(): void
    {
        [, $version, , $reviewer, $evaluation] = $this->inReview();

        $this->signIn($reviewer)->post(route('versions.review.evaluation', [$version, $evaluation]), ['verdict' => 'agree'])->assertRedirect();

        $review = EvaluationReview::firstOrFail();
        $this->assertSame([
            'decision_node' => 'DN-2',
            'path' => [['node' => 'DN-1', 'answer' => 'No'], ['node' => 'DN-2', 'answer' => 'Yes']],
            'verdict' => 'PASS',
            'justification' => 'Access is controlled by the paired app.',
        ], $review->snapshot);
        $this->assertSame($evaluation->fingerprint(), $review->fingerprint);
        $this->assertNull(app(ReviewDiff::class)->forReview($review));
    }

    public function test_changes_after_the_review_are_listed_with_a_word_diff_and_a_new_review_refreshes_them(): void
    {
        [$owner, $version, $asset, $reviewer, $evaluation] = $this->inReview();
        $this->signIn($reviewer)->post(route('versions.review.evaluation', [$version, $evaluation]), ['verdict' => 'agree'])->assertRedirect();

        // Changes requested, the owner walks the tree again, resubmits.
        $this->signIn($reviewer)->post(route('versions.review.decide', $version), ['verdict' => 'changes_requested', 'comment' => 'Check DN-2'])->assertRedirect();
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'answers' => ['No', 'No'], 'justification' => 'Access is not controlled by the paired app.',
        ])->assertRedirect();
        // A FAIL at DN-2 is justified by the asset's E.Info (fixture criteria): written before resubmitting.
        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'entries' => [$asset->id => ['E.Info.ACC-1.Asset' => 'Anyone nearby can open it.']],
        ])->assertRedirect();
        $this->provideUnits($version);
        $this->signIn($owner)->post(route('versions.submit', $version))->assertRedirect();

        $review = EvaluationReview::firstOrFail();
        $this->assertTrue($review->isStale());
        $changes = app(ReviewDiff::class)->forReview($review);

        $this->assertNotNull($changes);
        $this->assertTrue($changes->recorded);
        $this->assertSame('snapshot', $changes->source);
        $this->assertSame(['verdict', 'path', 'justification'], $changes->fields());
        $this->assertSame(['old' => 'PASS', 'new' => 'FAIL'], $changes->verdict);
        $this->assertSame('Yes', $changes->path['old'][1]['answer']);
        $this->assertSame('No', $changes->path['new'][1]['answer']);
        $this->assertContains(['type' => 'ins', 'text' => 'not'], $changes->justification);

        // What changed shows in the evidence panel of the evaluation (the row says "Changed").
        $page = $this->signIn($reviewer)->get(route('versions.review.evidence', [$version, $evaluation]))->assertOk();
        $page->assertSee(__('review.changes_since', ['name' => $reviewer->name, 'date' => $review->updated_at->isoFormat('D MMMM YYYY')]), false);
        $page->assertSee('<ins class="a-diff-ins"><span class="a-sr-only">['.__('review.added').'] </span>not</ins>', false);
        $page->assertSee(__('review.review_again'));
        $page->assertSee('data-facet-value="changed todo"', false);

        // Reviewing again (same opinion) refreshes the fingerprint and the snapshot.
        $this->signIn($reviewer)->post(route('versions.review.evaluation', [$version, $evaluation]), ['verdict' => 'agree'])->assertRedirect();
        $review->refresh();
        $this->assertFalse($review->isStale());
        $this->assertSame('FAIL', $review->snapshot['verdict']);
        $this->signIn($reviewer)->get(route('versions.review.evidence', [$version, $evaluation]))->assertOk()
            ->assertDontSee(__('review.review_again'))
            ->assertDontSee('data-facet-value="changed', false);
    }

    public function test_a_review_without_snapshot_is_rebuilt_from_the_activity_log(): void
    {
        [, $version, , $reviewer, $evaluation] = $this->inReview();
        $this->legacyReview($evaluation, $reviewer);

        $evaluation->update(['decision_node' => 'DN-1', 'verdict' => Verdict::NotApplicable, 'path' => [['node' => 'DN-1', 'answer' => 'Yes']]]);
        $evaluation->update(['justification' => 'The asset is public.']);

        $changes = app(ReviewDiff::class)->forReview(EvaluationReview::firstOrFail());

        $this->assertTrue($changes->recorded);
        $this->assertSame('activity', $changes->source);
        $this->assertSame(['old' => 'PASS', 'new' => 'NOT_APPLICABLE'], $changes->verdict);
        $this->assertSame(['old' => 'DN-2', 'new' => 'DN-1'], $changes->node);
        $this->assertNull($changes->path); // not in the activity log
        $this->assertContains(['type' => 'del', 'text' => 'controlled by the paired app'], $changes->justification);
        $this->assertContains(['type' => 'ins', 'text' => 'public'], $changes->justification);

        $this->signIn($reviewer)->get(route('versions.review.evidence', [$version, $evaluation]))->assertOk()->assertSee(__('review.changes_rebuilt'));
    }

    public function test_a_legacy_review_without_a_matching_history_says_details_were_not_recorded(): void
    {
        [, $version, , $reviewer, $evaluation] = $this->inReview();
        $this->legacyReview($evaluation, $reviewer, fingerprint: str_repeat('0', 64));

        $changes = app(ReviewDiff::class)->forReview(EvaluationReview::firstOrFail());

        $this->assertFalse($changes->recorded);
        $this->assertSame([], $changes->fields());
        $this->signIn($reviewer)->get(route('versions.review.evidence', [$version, $evaluation]))->assertOk()->assertSee(__('review.changes_not_recorded'));
    }

    public function test_the_changed_filter_counts_and_who_sees_what(): void
    {
        [$owner, $version, $asset, $reviewer, $evaluation] = $this->inReview();
        $second = $version->evaluations()->create([
            'standard' => 'demo-1', 'requirement' => 'ACC-1', 'inventory_item_id' => $version->inventoryItems()->create([
                'list' => 'assets', 'code' => 'AS-02', 'name' => 'Logs', 'attributes' => ['type' => 'security'],
            ])->id,
            'decision_node' => 'DN-1', 'verdict' => Verdict::NotApplicable, 'path' => [['node' => 'DN-1', 'answer' => 'Yes']],
        ]);
        foreach ([$evaluation, $second] as $e) {
            $this->signIn($reviewer)->post(route('versions.review.evaluation', [$version, $e]), ['verdict' => 'agree'])->assertRedirect();
        }
        $evaluation->update(['justification' => 'Rewritten.']);

        $reviewerPage = $this->signIn($reviewer)->get(route('versions.review.evidence', [$version, $evaluation]))->assertOk();
        $reviewerPage->assertSee('data-facet="changed"', false)
            ->assertSee('data-shortcut="c"', false)
            ->assertSee('data-shortcut="a"', false)
            ->assertSee(__('review.save_again'));
        $this->assertSame(1, substr_count($reviewerPage->getContent(), 'class="a-changes"'));
        $this->assertMatchesRegularExpression('/data-facet="changed"[^>]*>.*?<span class="a-seg-count is-warning">1<\/span>/s', $reviewerPage->getContent());

        // The owner sees what changed (closed), not the reviewer's prompt nor the review keys.
        $ownerPage = $this->signIn($owner)->get(route('versions.review.evidence', [$version, $evaluation]))->assertOk();
        $ownerPage->assertSee('class="a-changes"', false)
            ->assertDontSee(__('review.review_again'))
            ->assertDontSee('data-shortcut="a"', false);
        $this->assertDoesNotMatchRegularExpression('/<details class="a-changes"\s+open/', $ownerPage->getContent());
        $this->assertMatchesRegularExpression('/<details class="a-changes"\s+open/', $reviewerPage->getContent());

        // Outsiders get a 404, staff can look (read-only).
        $this->signIn(User::factory()->create())->get(route('versions.review', $version))->assertNotFound();
        $other = $this->owner();
        $this->signIn($other)->get(route('versions.review', $version))->assertNotFound();
        $this->signIn($reviewer)->post(route('versions.review.evaluation', [$version, $evaluation]), ['verdict' => 'agree'])->assertRedirect();
        $this->signIn($owner)->post(route('versions.review.evaluation', [$version, $evaluation]), ['verdict' => 'agree'])->assertNotFound();
        $this->assertSame(2, EvaluationReview::count());
    }

    /** @return array{0: User, 1: ProductVersion, 2: InventoryItem, 3: User, 4: Evaluation} */
    private function inReview(): array
    {
        Mail::fake();
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $asset = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Door key', 'attributes' => ['type' => 'security']]);
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'answers' => ['No', 'Yes'], 'justification' => 'Access is controlled by the paired app.',
        ])->assertRedirect();
        $reviewer = $this->member($version->product, ProductRole::Reviewer);
        $this->provideUnits($version);
        $this->signIn($owner)->post(route('versions.submit', $version))->assertRedirect();
        $this->assertSame(VersionStatus::InReview, $version->fresh()->status);

        return [$owner, $version, $asset, $reviewer, Evaluation::firstOrFail()];
    }

    /** A review as stored before snapshots existed. */
    private function legacyReview(Evaluation $evaluation, User $reviewer, ?string $fingerprint = null): void
    {
        EvaluationReview::create([
            'evaluation_id' => $evaluation->id, 'reviewer_id' => $reviewer->id, 'verdict' => 'question',
            'comment' => 'Why?', 'fingerprint' => $fingerprint ?? $evaluation->fingerprint(),
        ]);
    }
}
