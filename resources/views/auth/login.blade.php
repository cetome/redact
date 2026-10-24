<x-layouts.guest :title="__('auth.login.title')">
    <h1 class="c-h1">{{ __('auth.login.heading') }}</h1>
    <p class="c-lead mt-4">{{ __('auth.login.lead') }}</p>

    <div class="c-card mt-8" x-data="passkeyLogin" data-error="{{ __('auth.passkeys.error') }}">
        <div class="c-card-body space-y-6">
            <form method="POST" action="{{ route('login.send') }}" class="space-y-6" novalidate>
                @csrf
                <div class="c-field">
                    <label for="email" class="c-label">{{ __('auth.fields.email') }}</label>
                    <input id="email" name="email" type="email" class="c-input" required maxlength="191"
                        autocomplete="username webauthn" autofocus value="{{ old('email') }}"
                        @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')
                        <p id="email-error" class="c-error">{{ $message }}</p>
                    @enderror
                </div>
                @if ($rememberDays > 0)
                    {{-- One box for both ways in: the passkey button reads it too (passkeyLogin). --}}
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="remember" value="1" class="c-check mt-1" data-remember @checked(old('remember'))>
                        <span>{{ __('auth.login.remember', ['days' => $rememberDays]) }}</span>
                    </label>
                @endif
                <button type="submit" class="c-btn c-btn-primary c-btn-block">{{ __('auth.login.send') }}</button>
            </form>

            <div x-show="supported">
                <p class="c-meta text-center mb-4">{{ __('auth.login.or') }}</p>
                <button type="button" class="c-btn c-btn-outline c-btn-block" x-on:click="signIn" x-bind:disabled="busy">
                    {{ __('auth.login.passkey') }}
                </button>
                <p class="c-error mt-3" role="alert" x-show="error" x-text="error"></p>
            </div>
        </div>
    </div>

    <div class="mt-8 space-y-3">
        @if ($pending)
            <p><a href="{{ route('login.code') }}" class="c-link">{{ __('auth.login.have_code') }}</a></p>
        @endif
        @if (config('redact.auth.signup'))
            <p class="c-text">{{ __('auth.login.no_account') }} <a href="{{ route('register') }}" class="c-link">{{ __('auth.login.create') }}</a></p>
        @endif
        <x-guest-try-link />
    </div>
</x-layouts.guest>
