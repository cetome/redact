{{-- Opened from an assessment page (DecisionTreeController::back, kept in the session): the way back, on every trees page. --}}
@if ($back)
    <a href="{{ $back }}" class="a-btn a-btn-outline a-btn-sm mb-3"><x-lucide-arrow-left aria-hidden="true" />{{ __('trees.back') }}</a>
@endif
