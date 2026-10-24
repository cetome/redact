{{--
    index: only the public pages meant for search engines (home, about, try without an account) pass true;
    everything else stays "noindex, nofollow". pageTitle replaces the "<title> ◼ REDact" pattern.
    jsonLd: structured data (schema.org) for indexed pages, printed as a data block (not executed).
    description: the page's own search and social description (default: the home page's).
    canonical: the address of the page when it has several (the contact form with a topic chosen).
--}}
@props(['title' => null, 'social' => false, 'index' => false, 'pageTitle' => null, 'jsonLd' => null, 'description' => null, 'canonical' => null])
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light dark">
@if ($index)
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
<link rel="canonical" href="{{ $canonical ?? url()->current() }}">
@else
<meta name="robots" content="noindex, nofollow">
@endif
<title>{{ $pageTitle ?? ($title ? $title.' ◼ REDact' : 'REDact ◼ cetome') }}</title>
@if ($jsonLd)
<script type="application/ld+json" nonce="{{ app('csp-nonce') }}">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endif
@if ($social)
@php
    // Search and social metadata for the public pages (<x-layouts.guest> passes :social="true"), as on
    // cetome.com (partials/seo-head.html) and FAST (app/layout.php). Signed-in pages carry none: no network
    // can fetch them, and their own address must not leak into the page.
    // The card is public/images/og-redact.png, 2400 x 1260 (the 1200 x 630 card at twice the size);
    // a new picture is a new address (its version in the name, PublicController::ogImageUrl), so LinkedIn
    // and the others fetch it again.
    $ogTitle = $pageTitle ?? ($title ? $title.' ◼ REDact' : 'REDact ◼ cetome');
    $ogImage = \App\Http\Controllers\PublicController::ogImageUrl();
    $description ??= __('public.meta.description');
@endphp
<meta name="description" content="{{ $description }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="REDact">
<meta property="og:locale" content="{{ \App\Support\Locales::detail('og') }}">
<meta property="og:url" content="{{ $canonical ?? url()->current() }}">
<meta property="og:title" content="{{ $ogTitle }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:image" content="{{ $ogImage }}">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="2400">
<meta property="og:image:height" content="1260">
<meta property="og:image:alt" content="{{ __('public.meta.image_alt') }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $ogTitle }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $ogImage }}">
<meta name="twitter:image:alt" content="{{ __('public.meta.image_alt') }}">
@endif
<link rel="icon" href="{{ asset('brand/redact-favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('brand/redact-touch-icon.png') }}">
<script src="{{ asset('js/theme-init.js') }}" nonce="{{ app('csp-nonce') }}"></script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
