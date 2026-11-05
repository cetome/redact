{{--
    Sign in (FAST's /login): the two tabs, then how to sign in, Passkey or E-mail code (the e-mail by
    default, as FAST). The switch shows only where passkeys work (passkeyLogin "supported");
    modules/login-method.js swaps the two panes.
--}}
<x-layouts.guest :bare="true" :title="__('auth.login.title')">
    @include('auth._frame-top', ['tab' => 'login'])

    <section class="c-container r-auth">
        <div class="r-auth-card" x-data="passkeyLogin" data-error="{{ __('auth.passkeys.error') }}" data-login-methods>
            @include('auth._tabs', ['tab' => 'login'])
            <p class="r-auth-text">{{ __('auth.login.text') }}</p>

            <div class="r-auth-method" role="tablist" aria-label="{{ __('auth.login.method') }}" x-show="supported">
                <button type="button" class="r-auth-seg" role="tab" aria-selected="false" aria-controls="pane-passkey" data-method="passkey">{{ __('auth.login.tab_passkey') }}</button>
                <button type="button" class="r-auth-seg is-active" role="tab" aria-selected="true" aria-controls="pane-email" data-method="email">{{ __('auth.login.tab_email') }}</button>
            </div>

            <div id="pane-passkey" role="tabpanel" data-method-pane="passkey" hidden>
                @if ($rememberDays > 0)
                    @include('auth._remember', ['rememberDays' => $rememberDays, 'id' => 'remember-passkey'])
                @endif
                <button type="button" class="c-btn c-btn-primary c-btn-block" x-on:click="signIn" x-bind:disabled="busy">{{ __('auth.login.passkey') }}</button>
                <p class="r-auth-help">{{ __('auth.login.passkey_help') }}</p>
                <p class="c-error mt-3" role="alert" x-show="error" x-text="error"></p>
            </div>

            <form method="POST" action="{{ route('login.send') }}" class="space-y-5" id="pane-email" role="tabpanel" data-method-pane="email" novalidate>
                @csrf
                <div class="c-field">
                    <label for="email" class="c-label">{{ __('auth.login.email') }}</label>
                    <input id="email" name="email" type="email" class="c-input" required maxlength="191"
                        autocomplete="username webauthn" autofocus value="{{ old('email') }}"
                        aria-describedby="email-help" @error('email') aria-invalid="true" @enderror>
                    <p id="email-help" class="r-auth-help">{{ __('auth.login.email_help') }}</p>
                    @error('email')
                        <p class="c-error">{{ $message }}</p>
                    @enderror
                </div>
                @if ($rememberDays > 0)
                    @include('auth._remember', ['rememberDays' => $rememberDays, 'id' => 'remember-email'])
                @endif
                <button type="submit" class="c-btn c-btn-primary">{{ __('auth.login.send') }}</button>
            </form>
        </div>

        <div class="r-auth-after">
            @if ($pending)
                <p><a href="{{ route('login.code') }}" class="c-link">{{ __('auth.login.have_code') }}</a></p>
            @endif
            <x-guest-try-link />
        </div>
    </section>
</x-layouts.guest>
