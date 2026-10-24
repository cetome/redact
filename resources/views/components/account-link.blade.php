{{--
    "Create an account" on the public pages: a visitor goes to sign-up, a sandbox guest to "Keep my
    work" (the sandbox comes along), an account to its dashboard (the label then says "Go to dashboard").
    <x-account-link class="c-btn c-btn-primary">{{ __('nav.sign_up') }}</x-account-link>
    `account-label` / `account-href`: what an account reads and opens instead of "Go to dashboard" (an action,
    e.g. "Upgrade" to New product).
--}}
@props(['accountLabel' => null, 'accountHref' => null])
@php
    $user = auth()->user();
    $href = $user === null ? route('register') : ($user->is_guest ? route('guest.claim') : ($accountHref ?? route('dashboard')));
@endphp
<a href="{{ $href }}" {{ $attributes }}>@if ($user !== null && ! $user->is_guest){{ $accountLabel ?? __('public.go_dashboard') }}@else{{ $slot }}@endif</a>
