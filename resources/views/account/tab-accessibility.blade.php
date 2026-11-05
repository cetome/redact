{{-- Account, Accessibility tab: the colour theme (light, dark, the device's) and the keyboard shortcuts.
     Both follow the account on every device. AccountController::updateAccessibility. --}}
<div class="a-account">
    <form method="POST" action="{{ route('account.accessibility') }}" class="a-panel" aria-label="{{ __('auth.account.tab.accessibility') }}">
        @csrf
        @method('PUT')
        <div class="a-panel-body a-stack gap-5!">
            <fieldset class="a-fieldset a-stack gap-2!">
                <legend class="a-label">{{ __('auth.accessibility.theme') }}</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach (['system' => 'monitor', 'light' => 'sun', 'dark' => 'moon'] as $value => $icon)
                        <label class="a-toggle-card">
                            <input type="radio" name="theme" value="{{ $value }}" @checked(old('theme', $user->theme) === $value) required>
                            <span class="min-w-0">
                                <span class="flex items-center gap-2 font-semibold"><x-dynamic-component :component="'lucide-'.$icon" class="size-4" aria-hidden="true" />{{ __('auth.accessibility.themes.'.$value) }}</span>
                                <span class="a-toggle-card-text">{{ __('auth.accessibility.themes_help.'.$value) }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('theme')<p class="a-error">{{ $message }}</p>@enderror
            </fieldset>

            <fieldset class="a-fieldset a-stack gap-2!">
                <legend class="a-label">{{ __('auth.account.shortcuts') }}</legend>
                <input type="hidden" name="shortcuts" value="0">
                <label class="a-check">
                    <input type="checkbox" name="shortcuts" value="1" aria-describedby="shortcuts-pref-help" @checked(old('shortcuts', $user->shortcuts_enabled))>
                    <span>{{ __('auth.accessibility.shortcuts_on') }}</span>
                </label>
                <p id="shortcuts-pref-help" class="a-help">{{ __('auth.account.shortcuts_help') }}</p>
                @error('shortcuts')<p class="a-error">{{ $message }}</p>@enderror
            </fieldset>
        </div>
        <div class="a-panel-foot">
            <button type="submit" class="a-btn a-btn-primary" data-shortcut="mod+s" data-shortcut-label="{{ __('common.save') }}">
                <x-lucide-check aria-hidden="true" />{{ __('common.save') }}
            </button>
            <span class="a-kbd-hint a-keys-combo" data-kbd="mod+s" aria-hidden="true"></span>
        </div>
    </form>
</div>
