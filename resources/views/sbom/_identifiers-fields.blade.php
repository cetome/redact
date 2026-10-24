{{--
    Optional purl and CPE of a component, in the component form (inventory/_item-form). Saved by
    App\Vulnerabilities\ComponentObserver (identifiers[purl], identifiers[cpe]); used for an exact look-up.
    Params: $item (null when adding), $prefix, $fromOld.
--}}
@php
    $idValue = fn (string $key) => $fromOld ? old('identifiers.'.$key) : ($item ? \App\Sbom\Identifiers::get($item, $key) : null);
@endphp
<fieldset class="a-fieldset">
    <legend class="a-micro mb-2">{{ __('sbom.identifiers.title') }}</legend>
    <div class="a-form-grid">
        <div class="a-field">
            <label class="a-label" for="{{ $prefix }}-purl">{{ __('sbom.identifiers.purl') }} <x-ui.tip :text="__('sbom.identifiers.purl_help')" /></label>
            <input id="{{ $prefix }}-purl" name="identifiers[purl]" class="a-input a-code" maxlength="500" autocomplete="off" spellcheck="false"
                   placeholder="pkg:npm/lodash@4.17.21" value="{{ $idValue('purl') }}" @error('identifiers.purl') aria-invalid="true" @enderror>
            @error('identifiers.purl')<p class="a-error">{{ $message }}</p>@enderror
        </div>
        <div class="a-field">
            <label class="a-label" for="{{ $prefix }}-cpe">{{ __('sbom.identifiers.cpe') }} <x-ui.tip :text="__('sbom.identifiers.cpe_help')" /></label>
            <input id="{{ $prefix }}-cpe" name="identifiers[cpe]" class="a-input a-code" maxlength="300" autocomplete="off" spellcheck="false"
                   placeholder="cpe:2.3:a:vendor:product:version" value="{{ $idValue('cpe') }}" @error('identifiers.cpe') aria-invalid="true" @enderror>
            @error('identifiers.cpe')<p class="a-error">{{ $message }}</p>@enderror
        </div>
    </div>
    <p class="a-help mt-1">{{ __('sbom.identifiers.optional') }}</p>
</fieldset>
