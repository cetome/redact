{{--
    One attribute input from a content-pack field definition, in the app kit.
    select (up to 8 options) and yesno: chip radios, one click. More options: a select.
    A select whose options carry a "help" or "standards" (the asset type) shows them as cards, one
    choice: meaning, the EN 18031 parts it brings in, greyed when none of them is in scope
    ($scopeStandards: in-scope codes once the scoping is answered, else null; $standardNames).
    multiselect: toggle chips. boolean: a checkbox line (grouped by _item-form). text, textarea.
    Params: $field, $prefix (unique per form), $values (attributes).
--}}
@php
    $text = fn ($v) => \App\Content\Text::of($v);
    $id = $prefix.'-'.$field['key'];
    $name = 'attributes['.$field['key'].']';
    $current = $values[$field['key']] ?? null;
    $required = ! empty($field['required']);
    $help = ! empty($field['help']) ? $text($field['help']) : null;
    $errorKey = 'attributes.'.$field['key'];
@endphp
@switch($field['type'])
    @case('boolean')
        <span class="a-check">
            <input type="hidden" name="{{ $name }}" value="0">
            <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" @checked(filter_var($current, FILTER_VALIDATE_BOOLEAN))>
            <label for="{{ $id }}">{{ $text($field['label']) }}</label>
            @if ($help)<x-ui.tip :text="$help" />@endif
        </span>
        @break
    @case('yesno')
    @case('select')
        @php
            $options = $field['type'] === 'yesno'
                ? [['value' => 'yes', 'label' => __('inventory.yes')], ['value' => 'no', 'label' => __('inventory.no')], ['value' => 'na', 'label' => __('inventory.na')]]
                : ($field['options'] ?? []);
            $cards = $field['type'] === 'select' && collect($options)->contains(fn ($o) => isset($o['help']) || isset($o['standards']));
            $inScope = $scopeStandards ?? null;
            $names = $standardNames ?? [];
            $partsOf = fn (array $codes) => \App\Assessment\InventoryFields::partsList(array_map(fn ($c) => $names[$c] ?? $c, $codes));
        @endphp
        @if ($cards)
            <fieldset class="a-fieldset a-field a-span-all">
                <legend class="a-label">{{ $text($field['label']) }}@if ($required) <span class="a-label-hint">· {{ __('common.required') }}</span>@endif</legend>
                <div class="a-type-cards">
                    @foreach ($options as $option)
                        @php
                            $parts = array_values((array) ($option['standards'] ?? []));
                            $out = $inScope === null ? [] : array_values(array_diff($parts, $inScope));
                            $isOut = $parts !== [] && count($out) === count($parts);
                        @endphp
                        <label @class(['a-toggle-card a-type-card', 'is-out' => $isOut])>
                            <input type="radio" name="{{ $name }}" value="{{ $option['value'] }}" @checked((string) $current === (string) $option['value']) @required($required)>
                            <span class="min-w-0">
                                <span class="a-type-card-title">{{ $text($option['label']) }}</span>
                                @if (! empty($option['help']))<span class="a-toggle-card-text">{{ $text($option['help']) }}</span>@endif
                                @if ($parts !== [])<span class="a-type-card-parts">{{ __('inventory.type_parts', ['parts' => $partsOf($parts)]) }}</span>@endif
                                @if ($isOut)<span class="a-type-card-out"><x-lucide-circle-slash aria-hidden="true" />{{ trans_choice('inventory.type_out', count($out), ['parts' => $partsOf($out)]) }}</span>@endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @error($errorKey)<p class="a-error">{{ $message }}</p>@enderror
            </fieldset>
        @elseif (count($options) <= 8)
            <fieldset class="a-fieldset a-field a-span-all">
                <legend class="a-label">{{ $text($field['label']) }}@if ($required) <span class="a-label-hint">· {{ __('common.required') }}</span>@endif @if ($help)<x-ui.tip :text="$help" />@endif</legend>
                <div class="a-chips">
                    @foreach ($options as $option)
                        <label class="a-chip-toggle">
                            <input type="radio" name="{{ $name }}" value="{{ $option['value'] }}" @checked((string) $current === (string) $option['value']) @required($required)>
                            <span>{{ $text($option['label']) }}</span>
                        </label>
                    @endforeach
                    @unless ($required)
                        <label class="a-chip-toggle a-chip-none">
                            <input type="radio" name="{{ $name }}" value="" @checked($current === null || $current === '')>
                            <span>{{ __('common.not_set') }}</span>
                        </label>
                    @endunless
                </div>
                @error($errorKey)<p class="a-error">{{ $message }}</p>@enderror
            </fieldset>
        @else
            <div class="a-field">
                <label class="a-label" for="{{ $id }}">{{ $text($field['label']) }} @if ($help)<x-ui.tip :text="$help" />@endif</label>
                <select id="{{ $id }}" name="{{ $name }}" class="a-select" @required($required) @error($errorKey) aria-invalid="true" @enderror>
                    <option value=""></option>
                    @foreach ($options as $option)
                        <option value="{{ $option['value'] }}" @selected((string) $current === (string) $option['value'])>{{ $text($option['label']) }}</option>
                    @endforeach
                </select>
                @error($errorKey)<p class="a-error">{{ $message }}</p>@enderror
            </div>
        @endif
        @break
    @case('multiselect')
        <fieldset class="a-fieldset a-field a-span-all">
            <legend class="a-label">{{ $text($field['label']) }} <span class="a-label-hint">· {{ __('inventory.several') }}</span> @if ($help)<x-ui.tip :text="$help" />@endif</legend>
            <div class="a-chips">
                @foreach ($field['options'] ?? [] as $option)
                    <label class="a-chip-toggle">
                        <input type="checkbox" name="{{ $name }}[]" value="{{ $option['value'] }}" @checked(in_array($option['value'], (array) $current, true))>
                        <span>{{ $text($option['label']) }}</span>
                    </label>
                @endforeach
            </div>
            @error($errorKey)<p class="a-error">{{ $message }}</p>@enderror
        </fieldset>
        @break
    @case('textarea')
        <div class="a-field a-span-all">
            <label class="a-label" for="{{ $id }}">{{ $text($field['label']) }} @if ($help)<x-ui.tip :text="$help" />@endif</label>
            <textarea id="{{ $id }}" name="{{ $name }}" class="a-textarea a-textarea-sm" rows="2" maxlength="10000" @required($required)>{{ $current }}</textarea>
            @error($errorKey)<p class="a-error">{{ $message }}</p>@enderror
        </div>
        @break
    @default
        <div class="a-field">
            <label class="a-label" for="{{ $id }}">{{ $text($field['label']) }} @if ($help)<x-ui.tip :text="$help" />@endif</label>
            <input id="{{ $id }}" name="{{ $name }}" class="a-input" maxlength="500" value="{{ $current }}" @required($required)>
            @error($errorKey)<p class="a-error">{{ $message }}</p>@enderror
        </div>
@endswitch
