{{--
    The account page, in tabs: /account (profile), /account/security|sessions|data|delete. Only the
    selected tab is rendered (works without script, linkable), and every form comes back to its
    own tab (AccountController::tabUrl(), or back() to the URL it was posted from).
--}}
@php
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone($user->timezone)->isoFormat('D MMMM YYYY') : __('auth.passkeys.never');
    $dateTime = fn ($value) => \Illuminate\Support\Carbon::parse($value)->timezone($user->timezone)->isoFormat('D MMMM YYYY, HH:mm');
    $tabs = [
        'profile' => ['user', null],
        'security' => ['key-round', $passkeys->count()],
        'sessions' => ['monitor-smartphone', $sessions->count()],
        'data' => ['download', null],
        'delete' => ['trash-2', null],
    ];
@endphp
<x-layouts.app :title="__('auth.account.tab.'.$tab).' · '.__('auth.account.title')">
    <x-ui.page-header :title="__('auth.account.heading')" :subtitle="__('auth.account.lead')" />

    <nav class="a-tabs a-account-tabs" aria-label="{{ __('auth.account.tabs') }}">
        @foreach ($tabs as $key => [$icon, $count])
            <a href="{{ \App\Http\Controllers\AccountController::tabUrl($key) }}" class="a-tab" @if ($tab === $key) aria-current="page" @endif>
                <x-dynamic-component :component="'lucide-'.$icon" class="size-4" aria-hidden="true" />
                {{ __('auth.account.tab.'.$key) }}
                @if ($count !== null)<span class="a-tab-count">{{ $count }}</span>@endif
            </a>
        @endforeach
    </nav>

    @include('account.tab-'.$tab)
</x-layouts.app>
