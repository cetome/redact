<x-layouts.app :title="__('guest.claim.title')">
    <div class="mx-auto max-w-2xl">
        <x-ui.page-header :title="__('guest.claim.heading')" :subtitle="__('guest.claim.lead')" />

        @if ($pending)
            <div class="a-panel">
                <div class="a-panel-body a-stack">
                    <h2 class="a-h3">{{ __('guest.claim.code_heading') }}</h2>
                    <p class="text-sm">{{ __('guest.claim.code_lead', ['email' => $email]) }}</p>
                    <form method="POST" action="{{ route('guest.claim.verify') }}" class="a-stack">
                        @csrf
                        <div class="a-field">
                            <label for="code" class="a-label">{{ __('auth.fields.code') }}</label>
                            <input id="code" name="code" type="text" class="a-input" required inputmode="numeric"
                                pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" autofocus
                                @error('code') aria-invalid="true" aria-describedby="code-error" @else aria-describedby="code-help" @enderror>
                            @error('code')
                                <p id="code-error" class="a-error">{{ $message }}</p>
                            @else
                                <p id="code-help" class="a-help">{{ __('auth.code.help') }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="a-btn a-btn-primary a-btn-block">{{ __('guest.claim.code_submit') }}</button>
                    </form>
                    <p><a href="{{ route('guest.claim.other') }}" class="a-link-btn">{{ __('guest.claim.other_address') }}</a></p>
                </div>
            </div>
        @else
            <p class="a-help mb-3">{{ __('guest.claim.existing') }}</p>

            <div class="a-panel">
                <div class="a-panel-body">
                    <form method="POST" action="{{ route('guest.claim.send') }}" class="a-stack">
                        @csrf
                        <div class="a-field">
                            <label for="name" class="a-label">{{ __('auth.fields.name') }}</label>
                            <input id="name" name="name" type="text" class="a-input" required maxlength="120"
                                autocomplete="name" value="{{ old('name', $form['name'] ?? '') }}"
                                @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                            @error('name')
                                <p id="name-error" class="a-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="a-field">
                            <label for="email" class="a-label">{{ __('auth.fields.work_email') }}</label>
                            <input id="email" name="email" type="email" class="a-input" required maxlength="191"
                                autocomplete="email" value="{{ old('email', $form['email'] ?? '') }}"
                                @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                            @error('email')
                                <p id="email-error" class="a-error">{{ $message }}</p>
                            @enderror
                        </div>


                        <div class="a-stack gap-2!">
                            <label class="a-check">
                                <input type="checkbox" name="terms" value="1" required @checked(old('terms'))
                                    @error('terms') aria-invalid="true" @enderror>
                                <span>
                                    {{ __('auth.register.terms_before') }}
                                    <a href="{{ route('legal.terms') }}" class="a-link-btn" target="_blank" rel="noopener">{{ __('auth.register.terms_link') }}</a>
                                    {{ __('auth.register.terms_and') }}
                                    <a href="{{ route('legal.privacy') }}" class="a-link-btn" target="_blank" rel="noopener">{{ __('auth.register.privacy_link') }}</a>
                                </span>
                            </label>
                            @error('terms')
                                <p class="a-error">{{ $message }}</p>
                            @enderror

                            <label class="a-check">
                                <input type="checkbox" name="product_news" value="1" @checked(old('product_news', $form['product_news'] ?? false))>
                                <span>{{ __('auth.register.product_news') }}</span>
                            </label>
                        </div>

                        <div>
                            <altcha-widget challenge="{{ route('guest.altcha') }}" name="altcha" auto="onfocus"
                                language="{{ \App\Support\Locales::detail('altcha') }}"></altcha-widget>
                            @error('altcha')
                                <p class="a-error mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit" class="a-btn a-btn-primary a-btn-block">{{ __('guest.claim.submit') }}</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
