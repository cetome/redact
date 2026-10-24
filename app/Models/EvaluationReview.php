<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A reviewer's opinion on one evaluation. Given by people only.
 *
 * @property int $id
 * @property string $evaluation_id
 * @property int $reviewer_id
 * @property string $verdict
 * @property string|null $comment
 * @property string $fingerprint
 * @property bool $self_review given by the owner or an organisation admin while the product had no reviewer (App\Assessment\ReviewRights)
 * @property array{decision_node?: string|null, path?: list<array{node: string, answer: string}>|null, verdict?: string|null, justification?: string|null}|null $snapshot
 * @property Carbon|null $updated_at
 * @property-read Evaluation $evaluation
 * @property-read User $reviewer
 */
#[Fillable(['evaluation_id', 'reviewer_id', 'verdict', 'comment', 'fingerprint', 'snapshot', 'self_review'])]
class EvaluationReview extends Model
{
    use LogsActivity;

    public const VERDICTS = ['agree', 'disagree', 'question'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'self_review' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['evaluation_id', 'verdict', 'comment', 'self_review'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('review');
    }

    /** @return BelongsTo<Evaluation, $this> */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isStale(): bool
    {
        return $this->fingerprint !== $this->evaluation->fingerprint();
    }
}
