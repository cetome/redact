{{-- Verdict chip (CRAted VerdictBadge): Pass green, Fail red, Not applicable slate, Inconclusive amber, none dashed. --}}
@props(['verdict' => null, 'icon' => true])
@php
    $verdict = is_string($verdict) ? \App\Enums\Verdict::tryFrom($verdict) : $verdict;
    [$class, $glyph] = match ($verdict?->value) {
        'PASS' => ['a-verdict-pass', 'circle-check'],
        'FAIL' => ['a-verdict-fail', 'circle-x'],
        'NOT_APPLICABLE' => ['a-verdict-na', 'circle-minus'],
        'INCONCLUSIVE' => ['a-verdict-inconclusive', 'circle-help'],
        default => ['a-verdict-none', 'circle-dashed'],
    };
@endphp
<span {{ $attributes->class(['a-verdict', $class]) }}>
    @if ($icon)<x-dynamic-component :component="'lucide-'.$glyph" aria-hidden="true" />@endif{{ $verdict?->label() ?? __('verdicts.none') }}
</span>
