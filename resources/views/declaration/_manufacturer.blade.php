{{-- The manufacturer (Annex VI point 2), kept on the organisation: edited by its administrators. --}}
@php
    $org = $data->organisation();
@endphp
<section class="a-panel" id="manufacturer" aria-labelledby="manufacturer-title">
    <div class="a-panel-head">
        <div class="min-w-0">
            <h2 class="a-h3 flex items-center gap-2" id="manufacturer-title"><x-lucide-building-2 class="size-4 text-brand-blue" aria-hidden="true" />{{ __('declaration.manufacturer.title') }}</h2>
            <p class="a-help mt-0.5">{{ $data->isDemo() ? __('declaration.demo.manufacturer') : __('declaration.manufacturer.lead', ['name' => $org->name]) }}</p>
        </div>
        @if ($data->hasManufacturer())
            <span class="a-badge a-badge-success shrink-0"><x-lucide-check aria-hidden="true" />{{ __('declaration.manufacturer.complete') }}</span>
        @else
            <span class="a-badge a-badge-warning shrink-0">{{ __('declaration.manufacturer.incomplete') }}</span>
        @endif
    </div>
    @if ($orgAdmin && ! $data->isDemo())
        <details class="a-decl-org" @if (! $data->hasManufacturer() || $errors->hasAny(['legal_name', 'address', 'country', 'authorised_rep_name', 'authorised_rep_address'])) open @endif>
            <summary class="a-decl-org-summary">
                <span class="min-w-0 truncate">{{ $data->hasManufacturer() ? $data->manufacturerName().' · '.str_replace("\n", ', ', $data->manufacturerAddress()) : __('declaration.manufacturer.fill') }}</span>
                <span class="a-link-btn shrink-0">{{ __('common.edit') }}</span>
            </summary>
            <form method="POST" action="{{ route('organisations.legal.update', $org) }}" class="a-panel-body a-stack gap-3!">
                @csrf
                @method('PUT')
                <input type="hidden" name="back" value="{{ route('versions.declaration', $version) }}">
                <div class="a-form-grid">
                    <div class="a-field">
                        <label class="a-label" for="org-legal-name">{{ __('declaration.manufacturer.legal_name') }}</label>
                        <input id="org-legal-name" name="legal_name" class="a-input a-input-sm" maxlength="200" autocomplete="organization" value="{{ old('legal_name', $org->legal_name) }}" placeholder="{{ $org->name }}">
                        @error('legal_name')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="a-field">
                        <label class="a-label" for="org-country">{{ __('declaration.manufacturer.country') }}</label>
                        <input id="org-country" name="country" class="a-input a-input-sm" maxlength="80" autocomplete="country-name" value="{{ old('country', $org->country) }}">
                    </div>
                    <div class="a-field a-span-all">
                        <label class="a-label" for="org-address">{{ __('declaration.manufacturer.address') }}</label>
                        <textarea id="org-address" name="address" class="a-textarea a-textarea-sm" rows="2" maxlength="1000" autocomplete="street-address">{{ old('address', $org->address) }}</textarea>
                    </div>
                    <div class="a-field">
                        <label class="a-label" for="org-rep-name">{{ __('declaration.manufacturer.rep_name') }} <span class="a-label-hint">{{ __('common.optional') }}</span></label>
                        <input id="org-rep-name" name="authorised_rep_name" class="a-input a-input-sm" maxlength="200" value="{{ old('authorised_rep_name', $org->authorised_rep_name) }}">
                    </div>
                    <div class="a-field">
                        <label class="a-label" for="org-rep-address">{{ __('declaration.manufacturer.rep_address') }}</label>
                        <textarea id="org-rep-address" name="authorised_rep_address" class="a-textarea a-textarea-sm" rows="2" maxlength="1000">{{ old('authorised_rep_address', $org->authorised_rep_address) }}</textarea>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="a-btn a-btn-outline a-btn-sm"><x-lucide-save aria-hidden="true" />{{ __('declaration.manufacturer.save') }}</button>
                    <p class="a-help">{{ __('declaration.manufacturer.shared') }}</p>
                </div>
            </form>
        </details>
    @else
        <div class="a-panel-body">
            @if ($data->hasManufacturer())
                <p class="a-decl-address">{{ $data->manufacturerName() }}<br>{!! nl2br(e($data->manufacturerAddress())) !!}</p>
            @endif
            @unless ($data->isDemo())
                <p class="a-help">{{ __('declaration.manufacturer.admin_only', ['name' => $org->name]) }}</p>
            @endunless
        </div>
    @endif
</section>
