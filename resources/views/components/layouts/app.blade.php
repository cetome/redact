{{--
    Signed-in shell (app UI kit, APP-UI.md): a compact top bar (logo, breadcrumb with the product
    switch, Decision trees, Console for staff, theme, account), then either the workspace of a
    product version (left sidebar with the workflow steps + page) or the page alone, and a one-line
    footer. The full cetome footer stays on the public pages (<x-layouts.guest>).

    <x-layouts.app :title="__('products.title')">… page …</x-layouts.app>
    <x-layouts.app :title="$product->name" :product="$product">…</x-layouts.app>   breadcrumb to the product

    A version page registers its version with <x-version-nav> in the `subnav` slot (or passes
    :version and step=""): the sidebar and the breadcrumb follow. `wide` is kept for older pages:
    app pages always use the full width.
--}}
@props(['title' => null, 'wide' => false, 'product' => null, 'version' => null, 'step' => 'overview'])
@php
    $workspace = request()->attributes->get('redact.workspace');
    if ($version) {
        $workspace = ['version' => $version, 'active' => $step];
    }
    $wsVersion = $workspace['version'] ?? null;
    $crumbProduct = $wsVersion?->product ?? $product;
    $user = auth()->user();

    // The product switch: the person's other products (same rule as the dashboard).
    $switch = collect();
    if ($crumbProduct && $user) {
        $adminOrgs = $user->organisations()->wherePivot('role', 'admin')->pluck('organisations.id');
        $switch = \App\Models\Product::query()
            ->where(fn ($q) => $q->where('owner_id', $user->id)
                ->orWhereIn('organisation_id', $adminOrgs)
                ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id)
                    ->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now()))))
            ->whereKeyNot($crumbProduct->id)
            ->orderByDesc('updated_at')->limit(12)->get(['id', 'name', 'reference']);
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-layouts.head :title="$title" />
</head>
<body class="a-body text-ink font-brand antialiased" @if ($user && ! $user->shortcuts_enabled) data-shortcuts="off" @endif>
    <a href="#main" class="c-skip-link">{{ __('common.skip_to_content') }}</a>

    <header class="a-topbar">
        <a href="{{ route('dashboard') }}" class="a-topbar-brand" aria-label="{{ __('nav.products') }}">
            <x-brand.redact-logo />
        </a>

        {{-- Inside a product: the product, then the version. "Products" itself is in the menu on the right. --}}
        <nav class="a-crumbs" aria-label="{{ __('common.breadcrumb') }}">
            @if ($crumbProduct)
                <span class="a-crumb-product">
                    <a href="{{ $crumbProduct->homeUrl() }}" @if (! $wsVersion) aria-current="page" @endif data-crumb-name title="{{ $crumbProduct->name }}">{{ $crumbProduct->name }}</a>
                    @if ($switch->isNotEmpty())
                        <details class="a-menu">
                            <summary class="a-close size-6!" aria-label="{{ __('ui.switch_product') }}"><x-lucide-chevrons-up-down class="size-3.5!" aria-hidden="true" /></summary>
                            <div class="a-menu-panel a-menu-panel-left">
                                <p class="a-menu-head"><strong>{{ __('ui.switch_product') }}</strong></p>
                                @foreach ($switch as $other)
                                    <a class="a-menu-item" href="{{ route('products.show', $other) }}">
                                        <x-ui.thumb :product="$other" size="sm" />
                                        <span class="min-w-0 truncate">{{ $other->name }}@if ($other->reference) <span class="a-muted font-normal">· {{ $other->reference }}</span>@endif</span>
                                    </a>
                                @endforeach
                                <div class="a-menu-sep"></div>
                                <a class="a-menu-item" href="{{ route('dashboard') }}"><x-lucide-layout-grid aria-hidden="true" /> {{ __('ui.all_products') }}</a>
                            </div>
                        </details>
                    @endif
                </span>
            @endif
            @if ($wsVersion)
                <span class="a-crumb-sep" aria-hidden="true">/</span>
                <a class="a-crumb-version" href="{{ route('versions.show', $wsVersion) }}" aria-current="page">{{ __('versions.version') }} {{ $wsVersion->label }}</a>
            @endif
        </nav>

        <nav class="a-topnav" aria-label="{{ __('nav.main') }}">
            <a href="{{ route('dashboard') }}" class="a-topnav-link" @if (request()->routeIs('dashboard')) aria-current="page" @endif
               data-shortcut="g p" data-shortcut-label="{{ __('shortcuts.go.products') }}">
                <x-lucide-layout-grid class="size-4" aria-hidden="true" />{{ __('nav.products') }}</a>
            <a href="{{ route('trees.index') }}" class="a-topnav-link" @if (request()->routeIs('trees.*')) aria-current="page" @endif
               data-shortcut="g t" data-shortcut-label="{{ __('shortcuts.go.trees') }}">
                <x-lucide-git-fork class="size-4" aria-hidden="true" />{{ __('nav.trees') }}</a>
        </nav>

        <div class="a-topbar-actions">
            {{-- Phones: a Products button and the menu drawer (components/ui/mobile-menu). --}}
            <x-ui.mobile-menu />
            @if (\App\Billing\Plan::showsUpgrade($user))
                {{-- Phones: the icon only, so the account menu keeps its place. --}}
                <a href="{{ route('subscribe') }}" class="a-btn a-btn-primary a-btn-sm" @if (request()->routeIs('billing.subscribe')) aria-current="page" @endif>
                    <x-lucide-sparkles aria-hidden="true" /><span class="max-sm:sr-only">{{ __('plans.upgrade_to', ['plan' => __('public.pricing.plans.paid.name')]) }}</span></a>
            @endif
            <span class="a-hint a-desktop-only">
                <button type="button" class="a-btn a-btn-ghost a-btn-icon a-kbd-hint" data-shortcuts-help aria-label="{{ __('shortcuts.button') }}" aria-describedby="shortcuts-tip">
                    <x-lucide-keyboard aria-hidden="true" />
                </button>
                <span class="a-hint-text" role="tooltip" id="shortcuts-tip">{{ __('shortcuts.button_tip') }} <kbd class="a-kbd">?</kbd></span>
            </span>
            <x-ui.locale-switch class="a-desktop-only" />
            <x-ui.theme-toggle class="a-desktop-only" />
            @if ($user?->is_guest)
                <x-guest-actions />
            @else
                <x-ui.user-menu class="a-desktop-only" />
            @endif
        </div>
    </header>

    <x-guest-banner />

    @if ($wsVersion)
        <div class="a-workspace">
            <x-ui.sidebar :version="$wsVersion" :active="$workspace['active'] ?? 'overview'" />
            <main id="main" class="a-main">
                <x-flash />
                {{ $slot }}
            </main>
        </div>
    @else
        <main id="main" class="a-main a-main-narrow flex-1">
            <x-flash />
            {{ $slot }}
        </main>
    @endif

    <footer class="a-footer">
        <div class="a-footer-inner">
            <span>© cetome 2017-{{ now()->year }} · REDact</span>
            <nav aria-label="{{ __('ui.legal') }}">
                <a href="{{ route('legal.terms') }}">{{ __('nav.terms') }}</a>
                <a href="{{ route('legal.privacy') }}">{{ __('nav.privacy') }}</a>
                {{-- The security page (how to report an issue), as on the contact page; security.txt stays for scanners. --}}
                @if (filled(config('redact.security_url')))
                    <a href="{{ config('redact.security_url') }}" target="_blank" rel="noopener">{{ __('nav.security') }}</a>
                @endif
            </nav>
        </div>
    </footer>

    {{-- Keyboard shortcuts (ui.js, APP-UI.md): the help dialog, filled when it opens from the
         page's [data-shortcut][data-shortcut-label] elements, and the global keys it lists. --}}
    <div hidden>
        <span data-shortcut="?" data-shortcut-doc data-shortcut-group="global" data-shortcut-label="{{ __('shortcuts.keys.help') }}"></span>
        <span data-shortcut="/" data-shortcut-doc data-shortcut-group="global" data-shortcut-label="{{ __('shortcuts.keys.search') }}"></span>
        <span data-shortcut="Escape" data-shortcut-doc data-shortcut-group="global" data-shortcut-label="{{ __('shortcuts.keys.escape') }}"></span>
    </div>
    <x-ui.drawer id="shortcuts-help" :modal="true" :title="__('shortcuts.title')" class="a-shortcuts" data-then="{{ __('shortcuts.then') }}">
        <p class="a-alert a-alert-warning" data-shortcuts-off hidden><x-lucide-keyboard-off aria-hidden="true" /><span>{{ __('shortcuts.off') }}</span></p>
        <div class="a-shortcuts-grid" tabindex="-1" data-autofocus>
            <section data-shortcuts-section="global" aria-labelledby="shortcuts-global">
                <h3 class="a-micro" id="shortcuts-global">{{ __('shortcuts.global') }}</h3>
                <ul class="a-keys" data-shortcuts-list></ul>
            </section>
            <section data-shortcuts-section="page" aria-labelledby="shortcuts-page">
                <h3 class="a-micro" id="shortcuts-page">{{ __('shortcuts.page') }}</h3>
                <ul class="a-keys" data-shortcuts-list></ul>
                <p class="a-help" data-shortcuts-empty>{{ __('shortcuts.page_empty') }}</p>
            </section>
            <section data-shortcuts-section="nav" class="a-shortcuts-nav" aria-labelledby="shortcuts-nav">
                <h3 class="a-micro" id="shortcuts-nav">{{ __('shortcuts.navigation') }} <span class="a-shortcuts-note">{{ __('shortcuts.sequence_help') }}</span></h3>
                <ul class="a-keys a-keys-cols" data-shortcuts-list></ul>
            </section>
        </div>
        @if ($user && ! $user->is_guest)
            <x-slot:footer>
                <a href="{{ route('account.tab', 'accessibility') }}" class="a-link-btn"><x-lucide-settings-2 aria-hidden="true" />{{ __('shortcuts.manage') }}</a>
                <button type="button" class="a-btn a-btn-outline a-btn-sm a-spacer" data-dialog-close>{{ __('common.close') }}</button>
            </x-slot:footer>
        @endif
    </x-ui.drawer>
    @includeIf('hosted.body')
</body>
</html>
