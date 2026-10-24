{{--
    The reviews given on one evaluation or one assessment unit result: verdict, reviewer, self-review
    mark, stale mark, comment. A self-review given before a reviewer joined stays shown, marked as no
    longer counting (ReviewRights::counts).
    @include('review._opinions', ['reviews' => $e->reviews, 'stale' => fn ($r) => bool, 'counts' => $counts])
--}}
@if ($reviews->isNotEmpty())
    <ul class="a-opinions">
        @foreach ($reviews as $review)
            <li class="flex flex-wrap items-center gap-1">
                <span @class(['a-badge', 'a-badge-success' => $review->verdict === 'agree', 'a-badge-danger' => $review->verdict === 'disagree', 'a-badge-warning' => $review->verdict === 'question'])>{{ __('review.verdicts.'.$review->verdict) }}</span>
                <strong class="a-review-by">{{ $review->reviewer->name }}@if ($stale($review))<span class="a-review-stale" title="{{ __('review.stale') }}"><x-lucide-history aria-hidden="true" /><span class="a-sr-only">{{ __('review.stale') }}</span></span>@endif</strong>
                @if ($review->self_review)
                    <span class="a-badge a-badge-outline" title="{{ $counts($review) ? __('review.self_reviewed_help') : __('review.self_not_counted') }}"><x-lucide-user-round-x aria-hidden="true" />{{ __('review.self_reviewed') }}</span>
                @endif
                @if ($review->comment)<span class="a-muted">{{ $review->comment }}</span>@endif
            </li>
        @endforeach
    </ul>
@endif
