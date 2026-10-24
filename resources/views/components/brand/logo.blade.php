{{--
    cetome logo (public/brand/*.svg, copied from cetome.com).

    <x-brand.logo />                              colour logo (cetome blue), for light grounds
    <x-brand.logo variant="white" />              white logo, for the blue hero and the dark footer
    <x-brand.logo variant="auto" />               colour logo, white logo when <html class="dark">
    <x-brand.logo subtitled />                    the logo with the "we make cyber work" line
    <x-brand.logo class="w-40" />                 extra classes go on the <img> (both for "auto")

    Loaded as <img>, not inlined: the SVG files carry style attributes that a strict CSP
    (no 'unsafe-inline' in style-src) would block if they were part of the page.
--}}
@props([
    'variant' => 'color',
    'subtitled' => false,
    'alt' => 'cetome',
])

@php
    $suffix = $subtitled ? '_subtitled' : '';
    $colour = asset("brand/cetome{$suffix}.svg");
    $white = asset("brand/cetome{$suffix}_white.svg");
    // the files' own proportions: 53.3 x 10.58 (5:1), subtitled 53.3 x 12.81 (4.2:1)
    $width = $subtitled ? 200 : 201;
    $height = $subtitled ? 48 : 40;
@endphp

@if ($variant === 'auto')
    <img src="{{ $colour }}" alt="{{ $alt }}" width="{{ $width }}" height="{{ $height }}" {{ $attributes->class(['c-logo', 'c-only-light']) }}>
    <img src="{{ $white }}" alt="{{ $alt }}" width="{{ $width }}" height="{{ $height }}" {{ $attributes->class(['c-logo', 'c-only-dark']) }}>
@else
    <img src="{{ $variant === 'white' ? $white : $colour }}" alt="{{ $alt }}" width="{{ $width }}" height="{{ $height }}" {{ $attributes->class(['c-logo']) }}>
@endif
