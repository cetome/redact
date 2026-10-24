<?php

namespace App\Models;

use App\Enums\Verdict;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The verdict of one assessment case of the standard (conceptual, functional completeness,
 * functional sufficiency) for one requirement of one version, always with its evidence.
 * Provided by the manufacturer's team (owner, contributors, organisation admins) in the "Assessment
 * units" step; reviewers validate them in the Review step (AssessmentCaseReview). Recorded by people
 * only. `independent` is computed when saved (App\Assessment\AssessmentCases).
 *
 * @property string $id
 * @property string $product_version_id
 * @property string $standard
 * @property string $requirement
 * @property string $case_key
 * @property Verdict $verdict
 * @property string $evidence
 * @property string|null $document_id
 * @property int|null $recorded_by
 * @property bool $independent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $recorder
 * @property-read Document|null $document
 * @property-read Collection<int, AssessmentCaseReview> $reviews
 */
#[Fillable(['product_version_id', 'standard', 'requirement', 'case_key', 'verdict', 'evidence', 'document_id', 'recorded_by', 'independent'])]
class AssessmentCaseResult extends Model
{
    use HasUlids, LogsActivity;

    /** The assessment cases of EN 18031, in the standard's order (the pack may name others). */
    public const CASES = ['conceptual', 'conceptual_completeness', 'functional_completeness', 'functional_sufficiency'];

    protected function casts(): array
    {
        return [
            'verdict' => Verdict::class,
            'independent' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['standard', 'requirement', 'case_key', 'verdict', 'evidence', 'document_id', 'recorded_by', 'independent'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('assessment_case');
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'product_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasMany<AssessmentCaseReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(AssessmentCaseReview::class);
    }

    /** Changes when anything a reviewer judged changes (verdict, evidence, document): their review is then stale. */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([$this->verdict->value, trim($this->evidence), (string) $this->document_id]));
    }

    /**
     * What a reviewer judges, kept with their review so the review page can show what changed.
     *
     * @return array{verdict: string, evidence: string, document_id: string|null}
     */
    public function reviewSnapshot(): array
    {
        return ['verdict' => $this->verdict->value, 'evidence' => $this->evidence, 'document_id' => $this->document_id];
    }
}
