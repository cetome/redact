{{--
    The signed-in account's menu, as FAST's (owner 2026-11-05): the person on top (picture, name,
    e-mail, a gear: the whole row opens the account), then Team and Plan and billing (the payer),
    Support (the contact form), then sign out. The app header and, for a signed-in account, the public pages' header. Not for
    sandbox guests (x-guest-actions).
--}}
@php
    $user = auth()->user();
    $initials = collect(preg_split('/\s+/', trim((string) $user?->name)) ?: [])->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '?';
@endphp
<details {{ $attributes->class(['a-menu']) }}>
    <summary class="a-user-btn" aria-label="{{ __('nav.account') }}">
        @if ($user?->picture_path)
            <img class="a-avatar a-avatar-img" src="{{ route('people.picture', $user) }}" alt="" aria-hidden="true">
        @else
            <span class="a-avatar" aria-hidden="true">{{ $initials }}</span>
        @endif
        <span class="a-user-name">{{ $user?->name }}</span>
        <x-lucide-chevron-down class="size-3.5 text-ink-subtle" aria-hidden="true" />
    </summary>
    <div class="a-menu-panel">
        {{-- The person, and their account settings (profile, security, accessibility, data) behind the gear. --}}
        <a class="a-menu-account" href="{{ route('account.show') }}" data-shortcut="g a" data-shortcut-label="{{ __('shortcuts.go.account') }}">
            @if ($user?->picture_path)
                <img class="a-avatar a-avatar-img" src="{{ route('people.picture', $user) }}" alt="" aria-hidden="true">
            @else
                <span class="a-avatar" aria-hidden="true">{{ $initials }}</span>
            @endif
            <span class="a-menu-account-text">
                <strong>{{ $user?->name }}</strong>
                @if ($user?->hasName())<span>{{ $user->email }}</span>@endif
            </span>
            <x-lucide-settings class="a-menu-account-gear" aria-hidden="true" />
            <span class="a-sr-only">{{ __('nav.account') }}</span>
        </a>
        <div class="a-menu-sep"></div>
        @if ($user?->organisations()->exists())
            <a class="a-menu-item" href="{{ route('organisations.team.mine') }}"><x-lucide-users aria-hidden="true" /> {{ __('orgteam.title') }}</a>
        @endif
        {{-- The payer (an organisation admin): plan, invoices, payment method, the Stripe portal. --}}
        @if (\App\Billing\Plan::billingOrganisation($user) !== null)
            <a class="a-menu-item" href="{{ route('billing.mine') }}"><x-lucide-credit-card aria-hidden="true" /> {{ __('plans.billing.title') }}</a>
        @endif
        {{-- Support: the contact form, its topic chosen, the sender the account. --}}
        <a class="a-menu-item" href="{{ route('contact.topic', 'support') }}"><x-lucide-life-buoy aria-hidden="true" /> {{ __('nav.support') }}</a>
        {{-- cetome staff: the admin console (owner 2026-11-05: in this menu, not the top bar). --}}
        @if ($user?->isStaff())
            <a class="a-menu-item" href="{{ route('admin.dashboard') }}" @if (request()->routeIs('admin.*')) aria-current="page" @endif><x-lucide-shield aria-hidden="true" /> {{ __('nav.admin') }}</a>
        @endif
        <div class="a-menu-sep"></div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="a-menu-item"><x-lucide-log-out aria-hidden="true" /> {{ __('nav.sign_out') }}</button>
        </form>
    </div>
</details>
