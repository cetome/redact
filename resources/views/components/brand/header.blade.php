{{--
    cetome.com header: logo left, menu, actions right; 5rem, sticky, slate-200 rule under it.

    <x-brand.header :href="route('home')">
        <a href="{{ route('dashboard') }}" class="c-nav-link" aria-current="page">{{ __('Dashboard') }}</a>
        <a href="{{ route('reports') }}" class="c-nav-link">{{ __('Reports') }}</a>
        <x-slot:actions>
            <a href="{{ route('logout') }}" class="c-btn c-btn-primary">{{ __('Log out') }}</a>
        </x-slot:actions>
    </x-brand.header>

    Default slot: the menu links (.c-nav-link; mark the current one with aria-current="page").
    `actions` slot: buttons on the right.
    Below 64rem both move behind a hamburger: a <details> element, so the menu needs no script
    and no inline code (strict CSP). The slots are rendered twice (bar + mobile panel), so do
    not put id attributes in them.
--}}
@props([
    'href' => url('/'),
    'label' => null,
    'homeLabel' => null,
])

@php
    $hasMenu = trim((string) $slot) !== '';
    $hasActions = isset($actions) && trim((string) $actions) !== '';
@endphp

<header {{ $attributes->class(['c-nav']) }}>
    <a href="{{ $href }}" class="c-nav-logo" aria-label="{{ $homeLabel ?? __('cetome home') }}">
        @isset($logo)
            {{ $logo }}
        @else
            <x-brand.logo variant="auto" alt="cetome" />
        @endisset
    </a>

    @if ($hasMenu)
        <nav class="c-nav-menu" aria-label="{{ $label ?? __('Main') }}">
            {{ $slot }}
        </nav>
    @endif

    @if ($hasActions)
        <div class="c-nav-actions c-nav-actions-bar">
            {{ $actions }}
        </div>
    @endif

    @if ($hasMenu || $hasActions)
        <details class="c-nav-mobile">
            <summary class="c-hamburger" aria-label="{{ __('Menu') }}">
                <span class="c-hamburger-line"></span>
                <span class="c-hamburger-line"></span>
                <span class="c-hamburger-line"></span>
            </summary>
            <div class="c-nav-mobile-panel">
                @if ($hasMenu)
                    <nav class="c-nav-mobile-menu" aria-label="{{ $label ?? __('Main') }}">
                        {{ $slot }}
                    </nav>
                @endif
                @if ($hasActions)
                    <div class="c-nav-actions">
                        {{ $actions }}
                    </div>
                @endif
            </div>
        </details>
    @endif
</header>
