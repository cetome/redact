{{-- Completion of one evaluation: done, incomplete, invalid (verdict not reachable), unjustified
     (walked, justification not what the standard asks: App\Assessment\Justification), inconclusive
     (set by a person while evidence is missing), missing. --}}
@props(['status'])
@php
    $class = match ($status) {
        'done' => 'a-badge-success',
        'invalid' => 'a-badge-danger',
        'incomplete' => 'a-badge-warning',
        'unjustified' => 'a-badge-warning a-badge-unjustified',
        'inconclusive' => 'a-badge-warning a-badge-inconclusive',
        default => 'a-badge-neutral',
    };
@endphp
<span {{ $attributes->class(['a-badge', $class]) }}>@if ($status === 'unjustified')<x-lucide-scale aria-hidden="true" />@elseif ($status === 'inconclusive')<x-lucide-circle-help aria-hidden="true" />@endif{{ __('evaluations.status.'.$status) }}</span>
