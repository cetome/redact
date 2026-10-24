<x-layouts.app :title="__('auth.confirm.title')">
    <div class="max-w-2xl">
        <h1 class="c-h1">{{ __('auth.confirm.heading') }}</h1>
        <p class="c-lead mt-4">{{ __('auth.confirm.lead') }}</p>

        @if ($staffNeedsPasskey)
            <x-ui.alert tone="warning" icon="key-round" role="note" class="mt-6">{{ $hasPasskeys ? __('auth.confirm.staff_note') : __('auth.confirm.staff_no_passkey') }}</x-ui.alert>
        @endif

        @if ($hasPasskeys)
            <div class="c-card mt-8" x-data="passkeyConfirm"
                data-options-url="{{ route('passkey.confirm-options') }}"
                data-submit-url="{{ route('passkey.confirm') }}"
                data-fallback="{{ route('account.show') }}"
                data-error="{{ __('auth.passkeys.error') }}">
                <div class="c-card-body">
                    <h2 class="c-h3">{{ __('auth.confirm.with_passkey') }}</h2>
                    <button type="button" class="c-btn c-btn-primary mt-4" x-on:click="confirm" x-bind:disabled="busy">
                        {{ __('auth.confirm.use_passkey') }}
                    </button>
                    <p class="c-error mt-3" role="alert" x-show="error" x-text="error"></p>
                </div>
            </div>
        @endif

        <div class="c-card mt-6">
            <div class="c-card-body">
                <h2 class="c-h3">{{ __('auth.confirm.with_email') }}</h2>
                @if ($codeSent)
                    <form method="POST" action="{{ route('auth.confirm.verify') }}" class="mt-4 space-y-6">
                        @csrf
                        <div class="c-field">
                            <label for="code" class="c-label">{{ __('auth.fields.code') }}</label>
                            <input id="code" name="code" type="text" class="c-input" required inputmode="numeric"
                                pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" autofocus
                                @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
                            @error('code')
                                <p id="code-error" class="c-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="c-btn c-btn-primary">{{ __('auth.confirm.submit_code') }}</button>
                    </form>
                    <form method="POST" action="{{ route('auth.confirm.send') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="c-btn c-btn-ghost c-btn-sm">{{ __('auth.confirm.resend') }}</button>
                    </form>
                @else
                    <p class="c-text mt-2">{{ __('auth.confirm.email_help', ['email' => auth()->user()->email]) }}</p>
                    <form method="POST" action="{{ route('auth.confirm.send') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="c-btn {{ $hasPasskeys ? 'c-btn-outline' : 'c-btn-primary' }}">{{ __('auth.confirm.send_code') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
