<?php

namespace App\Assessment;

use App\Models\Evaluation;
use App\Models\EvaluationReview;
use App\Support\WordDiff;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * What changed in an evaluation since a reviewer judged it: verdict, last decision node, path of
 * answers and a word diff of the justification.
 *
 * The reviewed state comes from the review's snapshot. Reviews given before snapshots were kept
 * have none: the state is then rebuilt from the evaluation's activity log (it records the old
 * node, verdict and justification of every change) by undoing changes from the newest until the
 * review's fingerprint matches. When it never matches, the change is reported without details.
 */
final class ReviewDiff
{
    private const REVIEWED = ['decision_node', 'verdict', 'justification'];

    /**
     * Changes for every stale review of these evaluations (their reviews loaded), keyed by
     * review id. One activity log query covers all the reviews without a snapshot.
     *
     * @param  iterable<Evaluation>  $evaluations
     * @return array<int, ReviewChanges>
     */
    public function forEvaluations(iterable $evaluations): array
    {
        $evaluations = collect($evaluations);
        $legacy = $evaluations
            ->filter(fn (Evaluation $e) => $e->reviews->contains(fn (EvaluationReview $r) => $r->snapshot === null && $r->fingerprint !== $e->fingerprint()))
            ->pluck('id');
        $activities = $legacy->isEmpty() ? collect() : $this->activities($legacy->all());

        $changes = [];
        foreach ($evaluations as $evaluation) {
            $fingerprint = $evaluation->fingerprint();
            foreach ($evaluation->reviews as $review) {
                if ($review->fingerprint !== $fingerprint) {
                    $changes[$review->id] = $this->compute($review, $evaluation, $activities->get($evaluation->id, collect()));
                }
            }
        }

        return $changes;
    }

    /** Changes since this review, or null when the evaluation is as the reviewer saw it. */
    public function forReview(EvaluationReview $review, ?Evaluation $evaluation = null): ?ReviewChanges
    {
        $evaluation ??= $review->evaluation;
        if ($review->fingerprint === $evaluation->fingerprint()) {
            return null;
        }

        $activities = $review->snapshot === null ? $this->activities([$evaluation->id])->get($evaluation->id, collect()) : collect();

        return $this->compute($review, $evaluation, $activities);
    }

    /** @param  Collection<int, Activity>  $activities  newest first */
    private function compute(EvaluationReview $review, Evaluation $evaluation, Collection $activities): ReviewChanges
    {
        $now = $evaluation->reviewSnapshot();
        $source = 'snapshot';
        $then = $review->snapshot;

        if ($then === null) {
            $source = 'activity';
            $then = $this->rebuild($review->fingerprint, $now, $activities);
            if ($then === null) {
                return ReviewChanges::unrecorded();
            }
        }

        $oldVerdict = $then['verdict'] ?? null;
        $oldNode = $then['decision_node'] ?? null;
        $oldText = (string) ($then['justification'] ?? '');
        $newText = (string) $now['justification'];
        $oldPath = array_key_exists('path', $then) ? ($then['path'] ?? []) : null;
        $newPath = $now['path'] ?? [];

        $changes = new ReviewChanges(
            recorded: true,
            source: $source,
            verdict: $oldVerdict !== $now['verdict'] ? ['old' => $oldVerdict, 'new' => $now['verdict']] : null,
            node: $oldNode !== $now['decision_node'] ? ['old' => $oldNode, 'new' => $now['decision_node']] : null,
            path: $oldPath !== null && $oldPath !== $newPath ? ['old' => $oldPath, 'new' => $newPath] : null,
            justification: trim($oldText) !== trim($newText) ? WordDiff::segments($oldText, $newText) : null,
        );

        return $changes->fields() === [] ? ReviewChanges::unrecorded() : $changes;
    }

    /**
     * Undo the logged changes, newest first, until the state has the review's fingerprint.
     *
     * @param  array{decision_node: string|null, path: mixed, verdict: string|null, justification: string|null}  $now
     * @param  Collection<int, Activity>  $activities
     * @return array{decision_node: string|null, verdict: string|null, justification: string|null}|null
     */
    private function rebuild(string $fingerprint, array $now, Collection $activities): ?array
    {
        $state = ['decision_node' => $now['decision_node'], 'verdict' => $now['verdict'], 'justification' => $now['justification']];

        foreach ($activities as $activity) {
            $old = $activity->attribute_changes?->get('old');
            if (! is_array($old)) {
                break;
            }
            foreach (self::REVIEWED as $field) {
                if (array_key_exists($field, $old)) {
                    $state[$field] = is_scalar($old[$field]) ? (string) $old[$field] : null;
                }
            }
            if (Evaluation::fingerprintOf($state['decision_node'], $state['verdict'], $state['justification']) === $fingerprint) {
                return $state;
            }
        }

        return null;
    }

    /**
     * Logged updates of these evaluations, newest first, grouped by evaluation id.
     *
     * @param  list<string>  $ids
     * @return Collection<string, Collection<int, Activity>>
     */
    private function activities(array $ids): Collection
    {
        /** @var Collection<string, Collection<int, Activity>> */
        return Activity::query()
            ->where('subject_type', (new Evaluation)->getMorphClass())
            ->whereIn('subject_id', $ids)
            ->where('event', 'updated')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (Activity $activity) => (string) $activity->subject_id);
    }
}
