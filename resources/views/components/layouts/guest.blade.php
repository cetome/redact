{{--
    Public pages and sign-in: REDact header with sign-in actions.
    narrow (default): centred narrow column. bare: no container, the page lays out
    its own full-width sections (home page).
    inlineErrors: the page shows each validation error next to its field (contact form): no summary on top.
--}}
@props(['title' => null, 'narrow' => true, 'bare' => false, 'index' => false, 'pageTitle' => null, 'jsonLd' => null, 'description' => null, 'inlineErrors' => false, 'canonical' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-layouts.head :title="$title" :social="true" :index="$index" :page-title="$pageTitle" :json-ld="$jsonLd" :description="$description" :canonical="$canonical" />
</head>
<body class="min-h-screen flex flex-col bg-canvas text-ink font-brand antialiased">
    <a href="#main" class="c-skip-link">{{ __('common.skip_to_content') }}</a>

    <x-brand.header :href="route('home')" :home-label="__('public.home')">
        <x-slot:logo><x-brand.redact-logo /></x-slot:logo>
        {{-- Dashboard: an account or a sandbox guest goes to theirs; a visitor opens a sandbox straight from
             this page (data-sandbox-open, guest/_open-form), landing on the empty sandbox dashboard. --}}
        <x-try-link class="c-nav-link">{{ __('nav.dashboard') }}</x-try-link>
        <a href="{{ route('about') }}" class="c-nav-link" @if (request()->routeIs('about')) aria-current="page" @endif>{{ __('nav.about') }}</a>
        <a href="{{ route('pricing') }}" class="c-nav-link" @if (request()->routeIs('pricing')) aria-current="page" @endif>{{ __('nav.pricing') }}</a>
        <a href="{{ route('templates') }}" class="c-nav-link" @if (request()->routeIs('templates')) aria-current="page" @endif>{{ __('templates.nav') }}</a>
        <x-slot:actions>
            <x-ui.theme-toggle />
            {{-- A sandbox guest has no account yet: same buttons as a visitor ("Create an account" keeps the sandbox work). --}}
            @if (auth()->guest() || auth()->user()->is_guest)
                <a href="{{ route('login') }}" class="c-btn c-btn-outline c-btn-sm">{{ __('nav.sign_in') }}</a>
                @if (config('redact.auth.signup'))
                    <a href="{{ auth()->check() ? route('guest.claim') : route('register') }}" class="c-btn c-btn-primary c-btn-sm">{{ __('nav.sign_up') }}</a>
                @endif
            @else
                {{-- A signed-in account: its menu, as in the app, instead of Sign in / Create an account. --}}
                <x-ui.user-menu />
            @endif
        </x-slot:actions>
    </x-brand.header>

    <main id="main" @class(['flex-1', 'py-12' => ! $bare, 'c-container-narrow' => ! $bare && $narrow, 'c-container' => ! $bare && ! $narrow])>
        @if ($bare)
            <div class="c-container"><x-flash :summary="! $inlineErrors" /></div>
        @else
            <x-flash :summary="! $inlineErrors" />
        @endif
        {{ $slot }}
    </main>

    <x-layouts.footer />
    @include('guest._open-form')
</body>
</html>
