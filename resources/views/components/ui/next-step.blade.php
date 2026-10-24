{{--
    The link to the step that follows $after in the workflow (Assessment::ORDER), at the foot of a
    step page: "Next step · Risk assessment  [Continue to Risk assessment →]".
    Renders nothing after the last step.

    <x-ui.next-step :version="$version" after="risks" />
    In a controller: Assessment::for($version)->nextStepAfter('inventory') → ['key', 'label', 'href'].
--}}
@props(['version', 'after'])
@php
    $next = \App\Assessment\Assessment::for($version)->nextStepAfter($after);
@endphp
@if ($next)
    <nav {{ $attributes->merge(['class' => 'a-next-step']) }} aria-label="{{ __('workflow.next_step') }}">
        <span class="a-next-step-text">
            <span class="a-micro">{{ __('workflow.next_step') }}</span>
            <span class="a-next-step-label"><x-dynamic-component :component="'lucide-'.\App\Support\WorkflowSummary::icon($next['key'])" aria-hidden="true" />{{ $next['label'] }}</span>
        </span>
        <a href="{{ $next['href'] }}" class="a-btn a-btn-primary">{{ __('workflow.next_step_go', ['step' => $next['label']]) }}<x-lucide-arrow-right aria-hidden="true" /></a>
    </nav>
@endif
