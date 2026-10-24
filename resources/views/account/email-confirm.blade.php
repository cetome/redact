<x-layouts.app :title="__('auth.email_change.title')">
    <div class="max-w-xl">
        <x-ui.page-header :title="__('auth.email_change.heading')" :subtitle="__('auth.email_change.lead', ['email' => $email])">
            <x-slot:eyebrow><a href="{{ \App\Http\Controllers\AccountController::tabUrl('security') }}">{{ __('nav.account') }}</a></x-slot:eyebrow>
        </x-ui.page-header>

        <form method="POST" action="{{ route('account.email.verify') }}" class="a-panel">
            @csrf
            <div class="a-panel-body">
                <div class="a-field">
                    <label for="code" class="a-label">{{ __('auth.fields.code') }}</label>
                    <input id="code" name="code" type="text" class="a-input max-w-48 text-center text-lg! font-bold tracking-[0.3em]" required inputmode="numeric"
                        pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" autofocus
                        @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
                    @error('code')
                        <p id="code-error" class="a-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <div class="a-panel-foot">
                <button type="submit" class="a-btn a-btn-primary"><x-lucide-check aria-hidden="true" />{{ __('auth.email_change.submit') }}</button>
                <a href="{{ \App\Http\Controllers\AccountController::tabUrl('security') }}" class="a-btn a-btn-ghost">{{ __('common.cancel') }}</a>
            </div>
        </form>
    </div>
</x-layouts.app>
