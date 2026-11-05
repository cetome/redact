{{-- Account, Profile tab: picture, name, language, time zone; the organisations aside. --}}
<div class="a-account a-account-split">
    {{-- The tab says "Profile": the box needs no heading. Laid out as the organisation's card
         (organisations/_card): the picture on the left, the fields beside it. --}}
    <section class="a-panel" aria-label="{{ __('auth.account.profile') }}">
        <div class="a-panel-body">
            <div class="grid gap-5 md:grid-cols-[10rem_minmax(0,1fr)]">
                {{-- The person's picture, shown instead of the initials to the people they work with. --}}
                <x-picture-box
                    :src="$user->picture_path ? route('people.picture', $user) : null"
                    :store="route('account.picture.store')"
                    :destroy="route('account.picture.destroy')"
                    field="picture"
                    :add="__('auth.account.add_picture')"
                    :alt="$user->name"
                    :confirm="__('auth.account.remove_picture_confirm')"
                    :help="__('auth.account.picture_help')" />

                <form method="POST" action="{{ route('account.update') }}" class="a-stack gap-4!">
                    @csrf
                    @method('PUT')
                    <div class="a-field">
                        <label for="name" class="a-label">{{ __('auth.fields.name') }}</label>
                        <input id="name" name="name" type="text" class="a-input" maxlength="120" autocomplete="name"
                            value="{{ old('name', $user->hasName() ? $user->name : '') }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                        @error('name')
                            <p id="name-error" class="a-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="a-form-grid">
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
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="submit" class="a-btn a-btn-primary" data-shortcut="mod+s" data-shortcut-label="{{ __('common.save') }}">
                            <x-lucide-check aria-hidden="true" />{{ __('common.save') }}
                        </button>
                        <span class="a-kbd-hint a-keys-combo" data-kbd="mod+s" aria-hidden="true"></span>
                    </div>
                </form>
            </div>
        </div>
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
