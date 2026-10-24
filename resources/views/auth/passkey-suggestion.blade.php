<x-layouts.app :title="__('auth.suggest.title')">
    <div class="max-w-2xl">
        <h1 class="c-h1">{{ __('auth.suggest.heading') }}</h1>
        <p class="c-lead mt-4">{{ __('auth.suggest.lead') }}</p>

        <ul class="c-rule-list mt-6">
            <li>{{ __('auth.suggest.point_fast') }}</li>
            <li>{{ __('auth.suggest.point_safe') }}</li>
            <li>{{ __('auth.suggest.point_private') }}</li>
        </ul>

        <div class="c-card mt-8" x-data="passkeyRegister"
            data-default-name="{{ \App\Auth\Sessions::device((string) request()->userAgent()) }}"
            data-redirect="{{ $continueUrl }}"
            data-error="{{ __('auth.passkeys.error') }}"
            data-exists="{{ __('auth.passkeys.exists') }}">
            <div class="c-card-body space-y-6">
                <div class="c-field">
                    <label for="passkey-name" class="c-label">{{ __('auth.passkeys.name') }}</label>
                    <input id="passkey-name" type="text" class="c-input" maxlength="60" x-model="name" aria-describedby="passkey-name-help">
                    <p id="passkey-name-help" class="c-help">{{ __('auth.passkeys.name_help') }}</p>
                </div>
                <div class="c-btn-row">
                    <button type="button" class="c-btn c-btn-primary" x-on:click="add" x-bind:disabled="busy">{{ __('auth.passkeys.add') }}</button>
                    <a href="{{ $continueUrl }}" class="c-btn c-btn-ghost">{{ __('auth.suggest.skip') }}</a>
                </div>
                <p class="c-error" role="alert" x-show="error" x-text="error"></p>
                <p class="c-help" x-show="unsupported">{{ __('auth.passkeys.unsupported') }}</p>
            </div>
        </div>
    </div>
</x-layouts.app>
