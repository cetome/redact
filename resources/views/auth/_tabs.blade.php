{{-- The two ways in, on top of the card (FAST's login tabs). Sign-up closed: no tabs. --}}
@if (config('redact.auth.signup'))
    <nav class="r-auth-tabs" aria-label="{{ __('auth.tabs') }}">
        <a href="{{ route('login') }}" class="r-auth-tab" @if ($tab === 'login') aria-current="page" @endif>{{ __('auth.login.heading') }}</a>
        <a href="{{ route('register') }}" class="r-auth-tab" @if ($tab === 'register') aria-current="page" @endif>{{ __('auth.register.heading') }}</a>
    </nav>
@endif
