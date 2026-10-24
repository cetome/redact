{{--
    Stat tile: micro label, big figure, optional "/ total" and progress bar.
    <x-ui.stat :label="__('…')" :value="28" :total="58" tone="ok|bad|info" :href="…" bar />
--}}
@props(['label', 'value', 'total' => null, 'tone' => null, 'href' => null, 'bar' => false])
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class(['a-stat', 'a-stat-'.$tone => $tone]) }}>
@else
    <div {{ $attributes->class(['a-stat', 'a-stat-'.$tone => $tone]) }}>
@endif
    <span class="a-stat-label">{{ $label }}</span>
    <span class="a-stat-value">{{ $value }}@if ($total !== null)<small> / {{ $total }}</small>@endif</span>
    @if ($bar && $total)
        <x-ui.progress :value="(int) $value" :max="(int) $total" />
    @endif
@if ($href)
    </a>
@else
    </div>
@endif
