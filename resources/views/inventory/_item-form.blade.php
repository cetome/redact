{{--
    The form of one inventory object: identity, the choices, the yes/no properties, free text.
    Its buttons live in the dialog footer (form="{{ $formId }}").
    Params: $item (null to add), $formId, $action, $list, $editable, $nextCode, $version.
--}}
@php
    $formKey = $item?->id ?? 'add';
    $fromOld = old('_form') === $formKey;
    $values = $fromOld ? (array) old('attributes', []) : ($item?->getAttribute('attributes') ?? []);
    $value = fn (string $field, $default = null) => $fromOld ? old($field, $default) : ($item?->$field ?? $default);
    $fields = collect($list['fields'] ?? []);
    $choices = $fields->filter(fn ($f) => in_array($f['type'], ['select', 'yesno', 'multiselect'], true));
    $flags = $fields->where('type', 'boolean');
    $texts = $fields->filter(fn ($f) => in_array($f['type'], ['text', 'textarea'], true));
    $prefix = 'f'.substr(md5($formKey), 0, 6);
@endphp
<form method="POST" action="{{ $action }}" id="{{ $formId }}">
    @csrf
    @if ($item) @method('PUT') @endif
    <input type="hidden" name="_form" value="{{ $formKey }}">
    <fieldset @disabled(! $editable) class="a-fieldset a-stack">
        <div class="a-form-grid">
            <div class="a-field">
                <label class="a-label" for="{{ $prefix }}-name">{{ __('inventory.name') }}</label>
                <input id="{{ $prefix }}-name" name="name" class="a-input" maxlength="200" value="{{ $value('name') }}" @error('name') aria-invalid="true" @enderror data-autofocus>
                @error('name')<p class="a-error">{{ $message }}</p>@enderror
            </div>
            <div class="a-field">
                <label class="a-label" for="{{ $prefix }}-code">{{ __('inventory.code') }} <x-ui.tip :text="__('inventory.code_help')" /></label>
                <input id="{{ $prefix }}-code" name="code" class="a-input a-code" required maxlength="80" value="{{ $value('code', $nextCode) }}" @error('code') aria-invalid="true" @enderror>
                @error('code')<p class="a-error">{{ $message }}</p>@enderror
            </div>
            <div class="a-field a-span-all">
                <label class="a-label" for="{{ $prefix }}-description">{{ __('inventory.description') }}</label>
                <textarea id="{{ $prefix }}-description" name="description" class="a-textarea a-textarea-sm" rows="2" maxlength="10000">{{ $value('description') }}</textarea>
                @if ($item)
                    <div class="mt-1 flex flex-wrap gap-2">
                        <x-ai.origin type="inventory_item" :key="$item->id" field="description" :value="$item->description" />
                        @if ($editable)
                            <x-ai.assist :version="$version" type="inventory_item" :key="$item->id" field="description" />
                        @endif
                    </div>
                @endif
            </div>
            @if ($item?->isGroup())
                <div class="a-field a-span-all">
                    <label class="a-label" for="{{ $prefix }}-members">{{ __('inventory.members') }} <span class="a-badge a-badge-group"><x-lucide-group aria-hidden="true" />{{ __('inventory.group_of', ['count' => count($item->memberList())]) }}</span></label>
                    <textarea id="{{ $prefix }}-members" name="members_text" class="a-textarea a-textarea-sm" rows="{{ min(8, max(3, count($item->memberList()))) }}" maxlength="20000">{{ $fromOld ? old('members_text') : implode("\n", $item->memberNames()) }}</textarea>
                    <p class="a-help">{{ __('inventory.members_help') }}</p>
                </div>
            @endif
        </div>

        @if ($choices->isNotEmpty())
            <div class="a-form-grid">
                @foreach ($choices as $field)
                    @include('inventory._field', ['field' => $field, 'prefix' => $prefix, 'values' => $values])
                @endforeach
            </div>
        @endif

        @if ($flags->isNotEmpty())
            <fieldset class="a-fieldset">
                <legend class="a-micro mb-2">{{ __('inventory.properties') }}</legend>
                <div class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                    @foreach ($flags as $field)
                        @include('inventory._field', ['field' => $field, 'prefix' => $prefix, 'values' => $values])
                    @endforeach
                </div>
            </fieldset>
        @endif

        @if ($texts->isNotEmpty())
            <div class="a-form-grid">
                @foreach ($texts as $field)
                    @include('inventory._field', ['field' => $field, 'prefix' => $prefix, 'values' => $values])
                @endforeach
            </div>
        @endif
        @includeWhen(\App\Sbom\SbomFeature::isComponentList($list['key']), 'sbom._identifiers-fields', ['item' => $item, 'prefix' => $prefix, 'fromOld' => $fromOld])
    </fieldset>
</form>
