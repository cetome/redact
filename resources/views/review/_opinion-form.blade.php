{{--
    Agree / Disagree / Question (+ comment, required unless agreeing) on one evaluation or one
    assessment unit result. Nothing else: a review never changes the assessment.
    @include('review._opinion-form', ['action' => url, 'mine' => my review or null, 'changed' => bool, 'bag' => error bag or 'default'])
--}}
@php
    $bag ??= 'default';
    $errorsHere = $errors->getBag($bag);
@endphp
@if ($changed)
    <p class="a-review-again"><x-lucide-history aria-hidden="true" /><span>{{ __('review.review_again') }}</span></p>
@endif
<form method="POST" action="{{ $action }}" class="a-opinion-form" data-review-form>
    @csrf
    <fieldset class="a-chips">
        <legend class="a-sr-only">{{ __('review.your_review') }}</legend>
        @foreach (\App\Models\EvaluationReview::VERDICTS as $verdict)
            <label class="a-chip-toggle h-6! px-2!"><input type="radio" name="verdict" value="{{ $verdict }}" @checked(($mine?->verdict ?? 'agree') === $verdict)><span>{{ __('review.verdicts.'.$verdict) }}</span></label>
        @endforeach
    </fieldset>
    <span class="flex gap-1.5">
        <input name="comment" class="a-input a-input-sm" maxlength="5000" placeholder="{{ __('review.comment') }}" aria-label="{{ __('review.comment') }}" value="{{ $mine?->comment }}" data-review-comment
               @if ($errorsHere->has('comment')) aria-invalid="true" @endif>
        <button type="submit" @class(['a-btn a-btn-sm', 'a-btn-outline' => ! $changed, 'a-btn-primary' => $changed]) data-review-save>
            @if ($changed)
                <x-lucide-refresh-cw aria-hidden="true" />{{ __('review.save_again') }}
            @else
                <x-lucide-check aria-hidden="true" />{{ __('common.save') }}
            @endif
        </button>
    </span>
    @if ($errorsHere->has('comment'))<p class="a-error">{{ $errorsHere->first('comment') }}</p>@endif
</form>
