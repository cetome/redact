{{--
    A small bar line of values per day (console overview: actions per day of a person or product).
    <x-ui.sparkline :values="[0, 3, 1, …]" :label="__('…')" />  Server-drawn SVG, no script, no style attribute.
--}}
@props(['values' => [], 'label' => ''])
@php
    $values = array_values($values);
    $max = max(1, ...($values ?: [0]));
    $step = 3;
@endphp
<svg {{ $attributes->class('a-spark') }} viewBox="0 0 {{ max(1, count($values) * $step) }} 20" preserveAspectRatio="none" role="img" aria-label="{{ $label }}">
    <rect class="a-spark-base" x="0" y="19.5" width="{{ max(1, count($values) * $step) }}" height="0.5" />
    @foreach ($values as $i => $value)
        @if ($value > 0)
            @php($height = max(2, round($value / $max * 19, 2)))
            <rect x="{{ $i * $step }}" y="{{ 19.5 - $height }}" width="2" height="{{ $height }}" />
        @endif
    @endforeach
</svg>
