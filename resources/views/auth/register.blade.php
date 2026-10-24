<x-layouts.guest :title="__('auth.register.title')">
    <h1 class="c-h1">{{ __('auth.register.heading') }}</h1>
    <p class="c-lead mt-4">{{ __('auth.register.lead') }}</p>

    <div class="c-card mt-8">
        <div class="c-card-body">
            <form method="POST" action="{{ route('register.store') }}" class="space-y-6">
                @csrf
                <div class="c-field">
                    <label for="name" class="c-label">{{ __('auth.fields.name') }}</label>
                    <input id="name" name="name" type="text" class="c-input" required maxlength="120"
                        autocomplete="name" value="{{ old('name') }}"
                        @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    @error('name')
                        <p id="name-error" class="c-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="c-field">
                    <label for="email" class="c-label">{{ __('auth.fields.work_email') }}</label>
                    <input id="email" name="email" type="email" class="c-input" required maxlength="191"
                        autocomplete="email" value="{{ old('email') }}"
                        @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')
                        <p id="email-error" class="c-error">{{ $message }}</p>
                    @enderror
                </div>


                <div class="space-y-3">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="terms" value="1" class="c-check mt-1" required @checked(old('terms'))
                            @error('terms') aria-invalid="true" @enderror>
                        <span>
                            {{ __('auth.register.terms_before') }}
                            <a href="{{ route('legal.terms') }}" class="c-link" target="_blank" rel="noopener">{{ __('auth.register.terms_link') }}</a>
                            {{ __('auth.register.terms_and') }}
                            <a href="{{ route('legal.privacy') }}" class="c-link" target="_blank" rel="noopener">{{ __('auth.register.privacy_link') }}</a>
                        </span>
                    </label>
                    @error('terms')
                        <p class="c-error">{{ $message }}</p>
                    @enderror

                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="product_news" value="1" class="c-check mt-1" @checked(old('product_news'))>
                        <span>{{ __('auth.register.product_news') }}</span>
                    </label>
                </div>

                <div>
                    <altcha-widget challenge="{{ route('altcha.challenge') }}" name="altcha" auto="onfocus"
                        language="{{ \App\Support\Locales::detail('altcha') }}"></altcha-widget>
                    @error('altcha')
                        <p class="c-error mt-2">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="c-btn c-btn-primary c-btn-block">{{ __('auth.register.submit') }}</button>
            </form>
        </div>
    </div>

    <p class="c-text mt-8">{{ __('auth.register.have_account') }} <a href="{{ route('login') }}" class="c-link">{{ __('auth.register.sign_in') }}</a></p>
    <x-guest-try-link />
</x-layouts.guest>
