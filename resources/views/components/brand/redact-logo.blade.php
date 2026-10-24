{{--
    The cetome REDact logo (square, diamond and green tick, "cetome", "REDact"), from the
    original REDact site. Letters are outlines in the SVG: no font is shipped.

    <x-brand.redact-logo />                  colour on light grounds, white on dark (follows <html class="dark">)
    <x-brand.redact-logo variant="white" />  for the blue hero and dark bands
--}}
@props(['variant' => 'auto'])
@php
    $colour = asset('brand/redact.svg');
    $white = asset('brand/redact_white.svg');
@endphp
@if ($variant === 'auto')
    <img src="{{ $colour }}" alt="cetome REDact" width="190" height="50" {{ $attributes->class(['c-logo c-logo-redact', 'c-only-light']) }}>
    <img src="{{ $white }}" alt="cetome REDact" width="190" height="50" {{ $attributes->class(['c-logo c-logo-redact', 'c-only-dark']) }}>
@else
    <img src="{{ $variant === 'white' ? $white : $colour }}" alt="cetome REDact" width="190" height="50" {{ $attributes->class(['c-logo c-logo-redact']) }}>
@endif
