<?php

namespace App\Assessment;

use App\Enums\ProductRole;
use App\Models\AssessmentCaseReview;
use App\Models\EvaluationReview;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use App\Models\VersionReview;
use App\Support\ProductAccess;
use Closure;
use Illuminate\Support\Collection;

/**
 * Who reviews a version (Review step: view and validate, never edit).
 *
 * The product's reviewers (ProductRole::Reviewer). While the product has none, its owner and the
 * organisation's administrators may review the version themselves: a self-review, always flagged
 * (`self_review` on the three review tables) and shown "Self-reviewed", behind a warning that an
 * independent reviewer is better (owner, 2026-10-23). As soon as a reviewer is a member, only
 * reviewers review: self-reviews stay visible as such but no longer count (counts()).
 */
final readonly class ReviewRights
{
    private function __construct(
        /** The person is a reviewer of the product. */
        public bool $reviewer,
        /** The product has no reviewer member: its owner and organisation admins review it themselves. */
        public bool $selfMode,
        /** The person may self-review (owner or organisation admin, the product editable by them). */
        public bool $selfReviewer,
    ) {}

    public static function for(User $user, ProductVersion|Product $subject, ?ProductAccess $access = null): self
    {
        $product = $subject instanceof Product ? $subject : $subject->product;
        $access ??= ProductAccess::for($user, $product);
        $selfMode = ! self::hasReviewer($product);
        $owner = $access->role === ProductRole::Owner || $access->orgAdmin;

        return new self(
            reviewer: $access->canReview(),
            selfMode: $selfMode,
            selfReviewer: $selfMode && $owner && $access->canManage(),
        );
    }

    /** A reviewer is a member of the product (an open invitation does not count). */
    public static function hasReviewer(Product $product): bool
    {
        return self::reviewerIds($product)->isNotEmpty();
    }

    /** @return Collection<int, int> */
    public static function reviewerIds(Product $product): Collection
    {
        return $product->members()->where('role', ProductRole::Reviewer)->pluck('user_id')->map(fn ($id) => (int) $id);
    }

    /** May review (judge evaluations and units, decide), as a reviewer or as a self-reviewer. */
    public function canReview(): bool
    {
        return $this->reviewer || $this->selfReviewer;
    }

    /** What this person's reviews are flagged: a self-review. */
    public function isSelf(): bool
    {
        return ! $this->reviewer && $this->selfReviewer;
    }

    /**
     * Which reviews count towards approval and refusals on this product: the reviewers' (never a
     * self-review once a reviewer exists), or the self-reviews while there is no reviewer.
     *
     * @return Closure(EvaluationReview|AssessmentCaseReview|VersionReview): bool
     */
    public static function counts(Product $product): Closure
    {
        $ids = self::reviewerIds($product);
        if ($ids->isEmpty()) {
            return fn (EvaluationReview|AssessmentCaseReview|VersionReview $review): bool => (bool) $review->self_review;
        }

        return fn (EvaluationReview|AssessmentCaseReview|VersionReview $review): bool => ! $review->self_review && $ids->contains((int) $review->reviewer_id);
    }

    /** An approved version whose approval came from self-reviews only: "Self-reviewed". */
    public static function selfApproved(ProductVersion $version): bool
    {
        if ($version->status->value !== 'approved') {
            return false;
        }
        $approvals = $version->reviews()->where('verdict', 'approved')->pluck('self_review');

        return $approvals->isNotEmpty() && $approvals->every(fn ($self) => (bool) $self);
    }
}
