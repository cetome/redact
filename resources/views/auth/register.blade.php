{{-- Create an account (FAST's /login, second tab): the e-mail address only, the human check (invisible), the terms line. --}}
<x-layouts.guest :bare="true" :title="__('auth.register.title')">
    @include('auth._frame-top', ['tab' => 'register'])

    <section class="c-container r-auth">
        <div class="r-auth-card">
            @include('auth._tabs', ['tab' => 'register'])
            <p class="r-auth-text">{{ __('auth.register.text') }}</p>

            <form method="POST" action="{{ route('register.store') }}" class="space-y-5" data-contact-form>
                @csrf
                <div class="c-field">
                    <label for="email" class="c-label">{{ __('auth.register.email') }}</label>
                    <input id="email" name="email" type="email" class="c-input" required maxlength="191" autofocus
                        autocomplete="email" value="{{ old('email') }}"
                        aria-describedby="email-help" @error('email') aria-invalid="true" @enderror>
                    <p id="email-help" class="r-auth-help">{{ __('auth.register.email_help') }}</p>
                    @error('email')
                        <p class="c-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    {{-- Invisible: the proof of work runs on page load; a checkbox only when it fails. --}}
                    <altcha-widget challenge="{{ route('altcha.challenge') }}" name="altcha" auto="onload" @unless ($errors->has('altcha')) display="invisible" @endunless
                        language="{{ \App\Support\Locales::detail('altcha') }}"></altcha-widget>
                    @error('altcha')
                        <p class="c-error mt-2">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="c-btn c-btn-primary" data-contact-submit><span data-contact-label data-busy="{{ __('auth.register.sending') }}">{{ __('auth.register.submit') }}</span></button>
                @include('auth._terms-line')
            </form>
        </div>

        <div class="r-auth-after">
            <x-guest-try-link />
        </div>
    </section>
</x-layouts.guest>
