{{-- Organisation role as two selectable cards (Member, Admin). $locked: the only admin stays admin. --}}
<fieldset class="a-fieldset">
    <legend class="a-label">{{ __('orgteam.organisation') }}</legend>
    <div class="a-orgteam-roles">
        @foreach (['member', 'admin'] as $value)
            <label @class(['a-toggle-card', 'is-disabled' => $locked && $value !== 'admin'])>
                <input type="radio" name="{{ $name }}" value="{{ $value }}" @checked($current === $value) @disabled($locked && $value !== 'admin')>
                <span>
                    <strong class="block">{{ __('orgteam.role.'.$value) }}</strong>
                    <span class="a-toggle-card-text">{{ __('orgteam.role_help.'.$value) }}</span>
                </span>
            </label>
        @endforeach
    </div>
</fieldset>
