<x-layouts.guest :title="__('auth.code.title')">
    <h1 class="c-h1">{{ __('auth.code.heading') }}</h1>
    <p class="c-lead mt-4">{{ __('auth.code.lead', ['email' => $email]) }}</p>

    <div class="c-card mt-8">
        <div class="c-card-body">
            <form method="POST" action="{{ route('login.verify') }}" class="space-y-6">
                @csrf
                <div class="c-field">
                    <label for="code" class="c-label">{{ __('auth.fields.code') }}</label>
                    <input id="code" name="code" type="text" class="c-input" required inputmode="numeric"
                        pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" autofocus
                        @error('code') aria-invalid="true" aria-describedby="code-error" @else aria-describedby="code-help" @enderror>
                    @error('code')
                        <p id="code-error" class="c-error">{{ $message }}</p>
                    @else
                        <p id="code-help" class="c-help">{{ __('auth.code.help') }}</p>
                    @enderror
                </div>
                <button type="submit" class="c-btn c-btn-primary c-btn-block">{{ __('auth.code.submit') }}</button>
            </form>
        </div>
    </div>

    <p class="c-text mt-8">{{ __('auth.code.no_mail') }} <a href="{{ route('login') }}" class="c-link">{{ __('auth.code.try_again') }}</a></p>
</x-layouts.guest>
