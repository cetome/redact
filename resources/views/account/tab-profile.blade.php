{{-- Account, Profile tab: name, language, time zone, preferences; the organisations aside. --}}
<div class="a-account a-account-split">
    <section class="a-panel" aria-labelledby="profile-heading">
        <div class="a-panel-head"><h2 id="profile-heading" class="a-h3">{{ __('auth.account.profile') }}</h2></div>
        <form method="POST" action="{{ route('account.update') }}">
            @csrf
            @method('PUT')
            <div class="a-panel-body a-form-grid">
                <div class="a-field a-span-all">
                    <label for="name" class="a-label">{{ __('auth.fields.name') }}</label>
                    <input id="name" name="name" type="text" class="a-input" required maxlength="120" autocomplete="name"
                        value="{{ old('name', $user->name) }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    @error('name')
                        <p id="name-error" class="a-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="a-field">
                    <label for="locale" class="a-label">{{ __('auth.fields.locale') }}</label>
                    <select id="locale" name="locale" class="a-select">
                        @foreach ($locales as $code => $label)
                            <option value="{{ $code }}" @selected(old('locale', $user->locale) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="a-field">
                    <label for="timezone" class="a-label">{{ __('auth.fields.timezone') }}</label>
                    <select id="timezone" name="timezone" class="a-select">
                        @foreach ($timezones as $zone)
                            <option value="{{ $zone }}" @selected(old('timezone', $user->timezone) === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </div>
                <fieldset class="a-fieldset a-span-all">
                    <legend class="a-micro">{{ __('auth.account.preferences') }}</legend>
                    <div class="a-account-pref">
                        <label class="a-check">
                            <input type="checkbox" name="product_news" value="1" @checked(old('product_news', $user->wants_product_news))>
                            <span>{{ __('auth.register.product_news') }}</span>
                        </label>
                        <input type="hidden" name="shortcuts" value="0">
                        <label class="a-check">
                            <input type="checkbox" name="shortcuts" value="1" aria-describedby="shortcuts-pref-help" @checked(old('shortcuts', $user->shortcuts_enabled))>
                            <span>{{ __('auth.account.shortcuts') }}</span>
                        </label>
                        <p id="shortcuts-pref-help" class="a-help">{{ __('auth.account.shortcuts_help') }}</p>
                    </div>
                </fieldset>
            </div>
            <div class="a-panel-foot">
                <button type="submit" class="a-btn a-btn-primary" data-shortcut="mod+s" data-shortcut-label="{{ __('common.save') }}">
                    <x-lucide-check aria-hidden="true" />{{ __('common.save') }}
                </button>
                <span class="a-kbd-hint a-keys-combo" data-kbd="mod+s" aria-hidden="true"></span>
            </div>
        </form>
    </section>

    <section class="a-panel" aria-labelledby="organisations-heading">
        <div class="a-panel-head"><h2 id="organisations-heading" class="a-h3">{{ __('auth.account.organisations') }}</h2></div>
        @if ($organisations->isEmpty())
            <p class="a-panel-body a-muted">{{ __('common.none') }}</p>
        @else
            <ul class="a-checklist">
                @foreach ($organisations as $organisation)
                    <li><span class="flex items-center justify-between gap-4 px-4 py-2 text-sm">
                        <span class="min-w-0 truncate">{{ $organisation->name }}</span>
                        <span class="a-badge a-badge-neutral">{{ __('auth.account.org_role.'.$organisation->pivot->role) }}</span>
                    </span>
                    @unless ($user->is_guest)
                        {{-- The organisation's plan (App\Billing\Plan): "Free plan · 1 of 1 product · 37 AI units left · …" --}}
                        <div class="px-4 pb-2">@include('plans._line', ['plan' => \App\Billing\Plan::for($organisation)])</div>
                    @endunless
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
