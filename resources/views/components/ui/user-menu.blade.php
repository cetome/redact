{{--
    The signed-in account's menu (initials, name, products, team, account, sign out): the app header
    and, for a signed-in account, the public pages' header. Not for sandbox guests (x-guest-actions).
--}}
@php
    $user = auth()->user();
    $initials = collect(preg_split('/\s+/', trim((string) $user?->name)) ?: [])->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '?';
@endphp
<details class="a-menu">
    <summary class="a-user-btn" aria-label="{{ __('nav.account') }}">
        <span class="a-avatar" aria-hidden="true">{{ $initials }}</span>
        <span class="a-user-name">{{ $user?->name }}</span>
        <x-lucide-chevron-down class="size-3.5 text-ink-subtle" aria-hidden="true" />
    </summary>
    <div class="a-menu-panel">
        <p class="a-menu-head"><strong>{{ $user?->name }}</strong>{{ $user?->email }}</p>
        <a class="a-menu-item" href="{{ route('dashboard') }}"><x-lucide-layout-grid aria-hidden="true" /> {{ __('nav.products') }}</a>
        <a class="a-menu-item a-menu-only-mobile" href="{{ route('trees.index') }}"><x-lucide-git-fork aria-hidden="true" /> {{ __('nav.trees') }}</a>
        @if ($user?->isStaff())
            <a class="a-menu-item a-menu-only-mobile" href="{{ route('admin.dashboard') }}"><x-lucide-shield aria-hidden="true" /> {{ __('nav.admin') }}</a>
        @endif
        @if ($user?->organisations()->exists())
            <a class="a-menu-item" href="{{ route('organisations.team.mine') }}"><x-lucide-users aria-hidden="true" /> {{ __('orgteam.title') }}</a>
        @endif
        <a class="a-menu-item" href="{{ route('account.show') }}" data-shortcut="g a" data-shortcut-label="{{ __('shortcuts.go.account') }}"><x-lucide-user aria-hidden="true" /> {{ __('nav.account') }}</a>
        <div class="a-menu-sep"></div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="a-menu-item"><x-lucide-log-out aria-hidden="true" /> {{ __('nav.sign_out') }}</button>
        </form>
    </div>
</details>
