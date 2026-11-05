{{--
    Sign in and Create an account (FAST's /login): the blue band with the page title, then one card
    with the two tabs on top. $tab: 'login' or 'register'. Coming from Premium on the pricing page
    (url.intended is /subscribe): the lead says what follows.
--}}
@php
    $subscribing = str_starts_with((string) session('url.intended'), route('subscribe'));
@endphp
<section class="c-hero r-hero-short r-auth-hero">
    <div class="c-hero-grid">
        <div class="c-hero-main">
            <p class="c-hero-kicker"><a href="{{ route('home') }}">REDact</a> <span aria-hidden="true">/</span> {{ __('auth.'.$tab.'.heading') }}</p>
            <h1 class="c-hero-title">{{ __('auth.'.$tab.'.hero') }}</h1>
            <p class="c-hero-lead">{{ $subscribing ? __('auth.subscribe_lead') : __('auth.'.$tab.'.lead') }}</p>
        </div>
    </div>
</section>
