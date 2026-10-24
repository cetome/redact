{{--
    Progress bar: a native <progress>, so no inline style. Green when complete.
    <x-ui.progress :value="12" :max="40" />          bar only
    <x-ui.progress :value="12" :max="40" line />     bar and "12/40"
--}}
@props(['value' => 0, 'max' => 0, 'line' => false, 'label' => null])
@php
    $max = max((int) $max, 0);
    $value = min(max((int) $value, 0), $max);
    $done = $max > 0 && $value === $max;
    $text = $label ?? __('common.progress', ['done' => $value, 'total' => $max]);
@endphp
@if ($line)
    <span {{ $attributes->class(['a-progress-line']) }}>
        <progress @class(['a-progress', 'is-done' => $done]) value="{{ $value }}" max="{{ max($max, 1) }}" aria-label="{{ $text }}">{{ $text }}</progress>
        <span>{{ $value }}/{{ $max }}</span>
    </span>
@else
    <progress {{ $attributes->class(['a-progress', 'is-done' => $done]) }} value="{{ $value }}" max="{{ max($max, 1) }}" aria-label="{{ $text }}">{{ $text }}</progress>
@endif
