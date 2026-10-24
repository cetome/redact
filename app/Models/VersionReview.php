<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A reviewer's overall decision on a version submitted for review.
 *
 * @property int $id
 * @property string $product_version_id
 * @property int $reviewer_id
 * @property string $verdict
 * @property string|null $comment
 * @property bool $self_review given by the owner or an organisation admin while the product had no reviewer (App\Assessment\ReviewRights)
 * @property Carbon|null $updated_at
 * @property-read User $reviewer
 */
#[Fillable(['product_version_id', 'reviewer_id', 'verdict', 'comment', 'self_review'])]
class VersionReview extends Model
{
    use LogsActivity;

    public const VERDICTS = ['approved', 'changes_requested'];

    protected function casts(): array
    {
        return ['self_review' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['product_version_id', 'verdict', 'comment', 'self_review'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('review');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
