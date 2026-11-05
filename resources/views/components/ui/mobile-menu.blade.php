{{--
    The app's menu on phones (owner 2026-11-05): in the header a Products button and a hamburger, then
    one drawer under the header: the account (its card, Team, Plan and billing, Support, Admin
    console), then Decision trees, and a footer with the language, the theme and Sign out. Products
    is the header button (owner 2026-11-05). Works without script (details/summary, the drawer sits under the header so the
    hamburger, a cross while it is open, stays reachable); modules/mobile-menu.js closes it on Escape.
    Shown below 64rem only (the top bar's links and the account menu take over above).
    <x-ui.mobile-menu />
--}}
@php
    $user = auth()->user();
    $initials = collect(preg_split('/\s+/', trim((string) $user?->name)) ?: [])->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '?';
    $billing = $user && ! $user->is_guest ? \App\Billing\Plan::billingOrganisation($user) : null;
@endphp
<div class="a-mobile">
    <a href="{{ route('dashboard') }}" class="a-btn a-btn-ghost a-btn-sm a-mobile-products" @if (request()->routeIs('dashboard')) aria-current="page" @endif>
        <x-lucide-layout-grid aria-hidden="true" />{{ __('nav.products') }}</a>
    <details class="a-mobile-menu" data-mobile-menu>
        {{-- The hamburger becomes a cross while the drawer is open (the header stays above it). --}}
        <summary class="a-btn a-btn-ghost a-btn-icon" aria-label="{{ __('nav.menu') }}"><x-lucide-menu class="a-mobile-icon-open" aria-hidden="true" /><x-lucide-x class="a-mobile-icon-close" aria-hidden="true" /></summary>
        <div class="a-mobile-panel">
            <nav class="a-mobile-body" aria-label="{{ __('nav.main') }}">
                {{-- Products is the button in the header. --}}
                @if ($user && ! $user->is_guest)
                    <p class="a-mobile-label">{{ __('nav.account') }}</p>
                    <a href="{{ route('account.show') }}" class="a-mobile-account" @if (request()->routeIs('account.*')) aria-current="page" @endif>
                        @if ($user->picture_path)
                            <img class="a-avatar a-avatar-img" src="{{ route('people.picture', $user) }}" alt="" aria-hidden="true">
                        @else
                            <span class="a-avatar" aria-hidden="true">{{ $initials }}</span>
                        @endif
                        <span class="a-mobile-account-text"><strong>{{ $user->name }}</strong>@if ($user->hasName())<span>{{ $user->email }}</span>@endif</span>
                        <x-lucide-chevron-right class="a-mobile-chev" aria-hidden="true" />
                    </a>
                    @if ($user->organisations()->exists())
                        <a href="{{ route('organisations.team.mine') }}" class="a-mobile-row"><x-lucide-users aria-hidden="true" />{{ __('orgteam.title') }}<x-lucide-chevron-right class="a-mobile-chev" aria-hidden="true" /></a>
                    @endif
                    @if ($billing !== null)
                        <a href="{{ route('billing.mine') }}" class="a-mobile-row"><x-lucide-credit-card aria-hidden="true" />{{ __('plans.billing.title') }}<x-lucide-chevron-right class="a-mobile-chev" aria-hidden="true" /></a>
                    @endif
                    <a href="{{ route('contact.topic', 'support') }}" class="a-mobile-row"><x-lucide-life-buoy aria-hidden="true" />{{ __('nav.support') }}<x-lucide-chevron-right class="a-mobile-chev" aria-hidden="true" /></a>
                    @if ($user->isStaff())
                        <a href="{{ route('admin.dashboard') }}" class="a-mobile-row" @if (request()->routeIs('admin.*')) aria-current="page" @endif><x-lucide-shield aria-hidden="true" />{{ __('nav.admin') }}<x-lucide-chevron-right class="a-mobile-chev" aria-hidden="true" /></a>
                    @endif
                    <div class="a-menu-sep"></div>
                @endif
                <a href="{{ route('trees.index') }}" class="a-mobile-row" @if (request()->routeIs('trees.*')) aria-current="page" @endif>
                    <x-lucide-git-fork aria-hidden="true" />{{ __('nav.trees') }}<x-lucide-chevron-right class="a-mobile-chev" aria-hidden="true" /></a>
            </nav>
            <div class="a-mobile-foot">
                <div class="a-mobile-switches">
                    <x-ui.locale-switch :full="true" class="a-menu-up" />
                    <x-ui.theme-toggle />
                </div>
                @if ($user?->is_guest)
                    <x-guest-actions />
                @elseif ($user)
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="a-btn a-btn-outline w-full"><x-lucide-log-out aria-hidden="true" />{{ __('nav.sign_out') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </details>
</div>
