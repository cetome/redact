{{--
    After an e-mail sign-in while the account has no passkey (after the payment when signing up to buy):
    one centred card like the sign-in page, what a passkey brings in a box, then add it, not now, or
    don't ask me again (never shown at sign-in after that).
--}}
<x-layouts.app :title="__('auth.suggest.title')">
    <section class="a-passkey-card" x-data="passkeyRegister"
        data-default-name="{{ \App\Auth\Sessions::device((string) request()->userAgent()) }}"
        data-redirect="{{ $continueUrl }}"
        data-error="{{ __('auth.passkeys.error') }}"
        data-exists="{{ __('auth.passkeys.exists') }}"
        aria-labelledby="passkey-heading">
        <span class="a-passkey-icon" aria-hidden="true"><x-lucide-fingerprint /></span>
        <h1 id="passkey-heading" class="a-passkey-title">{{ __('auth.suggest.heading') }}</h1>
        <p class="a-passkey-lead">{{ __('auth.suggest.lead') }}</p>

        <ul class="a-passkey-points">
            <li><x-lucide-zap aria-hidden="true" /><span>{{ __('auth.suggest.point_fast') }}</span></li>
            <li><x-lucide-shield-check aria-hidden="true" /><span>{{ __('auth.suggest.point_safe') }}</span></li>
            <li><x-lucide-lock aria-hidden="true" /><span>{{ __('auth.suggest.point_private') }}</span></li>
        </ul>

        <div class="a-field">
            <label for="passkey-name" class="a-label">{{ __('auth.passkeys.name') }}</label>
            <input id="passkey-name" type="text" class="a-input" maxlength="60" x-model="name" aria-describedby="passkey-name-help">
            <p id="passkey-name-help" class="a-help">{{ __('auth.passkeys.name_help') }}</p>
        </div>

        <div class="a-passkey-actions">
            <button type="button" class="a-btn a-btn-primary" x-on:click="add" x-bind:disabled="busy"><x-lucide-fingerprint aria-hidden="true" />{{ __('auth.passkeys.add') }}</button>
            <a href="{{ $continueUrl }}" class="a-btn a-btn-ghost">{{ __('auth.suggest.skip') }}</a>
        </div>
        <form method="POST" action="{{ route('auth.passkey.never') }}" class="a-passkey-never">
            @csrf
            <button type="submit" class="a-link-btn">{{ __('auth.suggest.never') }}</button>
        </form>
        <p class="a-error" role="alert" x-show="error" x-text="error"></p>
        <p class="a-help" x-show="unsupported">{{ __('auth.passkeys.unsupported') }}</p>
        <p class="a-passkey-note">{{ __('auth.suggest.later') }}</p>
    </section>
</x-layouts.app>
