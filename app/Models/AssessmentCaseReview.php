<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A reviewer's opinion on one assessment unit result the manufacturer provided (Review step,
 * "Assessment units"). Given by people only, like EvaluationReview: stale once the result changes.
 *
 * @property int $id
 * @property string $assessment_case_result_id
 * @property int $reviewer_id
 * @property string $verdict
 * @property string|null $comment
 * @property string $fingerprint
 * @property array{verdict?: string|null, evidence?: string|null, document_id?: string|null}|null $snapshot
 * @property bool $self_review
 * @property Carbon|null $updated_at
 * @property-read AssessmentCaseResult $result
 * @property-read User $reviewer
 */
#[Fillable(['assessment_case_result_id', 'reviewer_id', 'verdict', 'comment', 'fingerprint', 'snapshot', 'self_review'])]
class AssessmentCaseReview extends Model
{
    use LogsActivity;

    public const VERDICTS = EvaluationReview::VERDICTS;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'self_review' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['assessment_case_result_id', 'verdict', 'comment', 'self_review'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('review');
    }

    /** @return BelongsTo<AssessmentCaseResult, $this> */
    public function result(): BelongsTo
    {
        return $this->belongsTo(AssessmentCaseResult::class, 'assessment_case_result_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isStale(): bool
    {
        return $this->fingerprint !== $this->result->fingerprint();
    }
}
