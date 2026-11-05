{{-- The 6-digit code from the e-mail (FAST's /login code step): the blue band, then the code in one large field. --}}
<x-layouts.guest :bare="true" :title="__('auth.code.title')">
    <section class="c-hero r-hero-short r-auth-hero">
        <div class="c-hero-grid">
            <div class="c-hero-main">
                <p class="c-hero-kicker"><a href="{{ route('home') }}">REDact</a> <span aria-hidden="true">/</span> {{ __('auth.login.heading') }}</p>
                <h1 class="c-hero-title">{{ __('auth.code.heading') }}</h1>
                <p class="c-hero-lead">{{ __('auth.code.lead', ['email' => $email]) }}</p>
            </div>
        </div>
    </section>

    <section class="c-container r-auth">
        <div class="r-auth-card">
            <form method="POST" action="{{ route('login.verify') }}" class="space-y-5">
                @csrf
                <div class="c-field">
                    <label for="code" class="c-label">{{ __('auth.fields.code') }}</label>
                    <input id="code" name="code" type="text" class="c-input r-auth-code" required inputmode="numeric"
                        pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" autofocus
                        @error('code') aria-invalid="true" aria-describedby="code-error" @else aria-describedby="code-help" @enderror>
                    @error('code')
                        <p id="code-error" class="c-error">{{ $message }}</p>
                    @else
                        <p id="code-help" class="r-auth-help">{{ __('auth.code.help') }}</p>
                    @enderror
                </div>
                <button type="submit" class="c-btn c-btn-primary c-btn-block">{{ __('auth.code.submit') }}</button>
            </form>
        </div>

        <div class="r-auth-after">
            <p class="c-text">{{ __('auth.code.no_mail') }} <a href="{{ route('login') }}" class="c-link">{{ __('auth.code.try_again') }}</a></p>
        </div>
    </section>
</x-layouts.guest>
