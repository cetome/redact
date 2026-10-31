<?php

namespace App\Models;

use App\Enums\Verdict;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The walk through one EN 18031 decision tree for one object:
 * the last decision node reached (E.DT), the verdict and the justification (E.Just).
 *
 * @property string $id
 * @property string $product_version_id
 * @property string $standard
 * @property string $requirement
 * @property string|null $inventory_item_id
 * @property string|null $decision_node
 * @property list<array{node: string, answer: string}>|null $path
 * @property Verdict|null $verdict
 * @property string|null $justification
 * @property string|null $shared_group
 * @property string|null $shared_source
 * @property bool $shared_untied
 * @property bool $recheck to confirm again after a significant change (App\Assessment\SignificantChange)
 * @property int|null $updated_by
 * @property Carbon|null $updated_at
 * @property-read User|null $updater
 */
#[Fillable(['product_version_id', 'standard', 'requirement', 'inventory_item_id', 'decision_node', 'path',
    'verdict', 'justification', 'shared_group', 'shared_source', 'shared_untied', 'updated_by'])]
class Evaluation extends Model
{
    use HasUlids, LogsActivity;

    /**
     * Names of the other parts a shared save also updated (App\Assessment\SharedWalks): written
     * into this save's activity entry, so one entry tells the whole change.
     *
     * @var list<string>
     */
    public array $sharedWith = [];

    protected function casts(): array
    {
        return [
            'path' => 'array',
            'verdict' => Verdict::class,
            'shared_untied' => 'boolean',
            'recheck' => 'boolean',
        ];
    }

    /** A new node, path, verdict or justification is a re-check done: the mark goes. */
    protected static function booted(): void
    {
        static::saving(function (Evaluation $evaluation) {
            if ($evaluation->recheck && $evaluation->isDirty(['decision_node', 'path', 'verdict', 'justification'])) {
                $evaluation->recheck = false;
            }
        });
    }

    /** Called by spatie/laravel-activitylog before an entry about this evaluation is saved. */
    public function beforeActivityLogged(Activity $activity, string $event): void
    {
        if ($this->sharedWith !== []) {
            $activity->properties = collect($activity->properties ?? [])->put('shared_with', implode(', ', $this->sharedWith));
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['standard', 'requirement', 'inventory_item_id', 'decision_node', 'verdict', 'justification'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('evaluation');
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'product_version_id');
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<EvaluationReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(EvaluationReview::class);
    }

    /** `DT.ACM-1.DN-4`, the identifier the standard asks for in E.Info.DT. */
    public function nodeReference(): ?string
    {
        return $this->decision_node ? 'DT.'.$this->requirement.'.'.$this->decision_node : null;
    }

    /** Changes when anything a reviewer judged changes, which makes their review stale. */
    public function fingerprint(): string
    {
        return self::fingerprintOf($this->decision_node, $this->verdict?->value, $this->justification);
    }

    /** The fingerprint of a reviewed state (also of one rebuilt from the activity log). */
    public static function fingerprintOf(?string $decisionNode, ?string $verdict, ?string $justification): string
    {
        return hash('sha256', (string) json_encode([$decisionNode, $verdict, (string) $justification]));
    }

    /**
     * What a reviewer judges, stored with their review so the review page can show what changed.
     *
     * @return array{decision_node: string|null, path: list<array{node: string, answer: string}>|null, verdict: string|null, justification: string|null}
     */
    public function reviewSnapshot(): array
    {
        return [
            'decision_node' => $this->decision_node,
            'path' => $this->path,
            'verdict' => $this->verdict?->value,
            'justification' => $this->justification,
        ];
    }
}
