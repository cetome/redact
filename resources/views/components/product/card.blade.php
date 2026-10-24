{{--
    The product card (CRAted's ProductProfileCard), the same block on the version Overview, Product
    settings and the Product step: the picture on the left (upload/replace on the picture itself),
    then name, reference, description, Models (read only) and Product version, then the Documents of
    the version. The models are edited in the Product identification of the Product step (owner,
    2026-10-23: one place only); `models` false hides them (the Product step, which lists them below).
    A container query lays it out: three columns on a wide card, the documents under the first two on
    a medium one, everything stacked on a narrow one.

    Product level: name, reference, description, models, picture. Version level: product version
    (hardware, software/firmware) and documents (`version`, the current or the latest version).

    View:  <x-product.card :product="$product" :version="$version" />
    Form:  <x-product.card :product :version :action="route(…)" method="PUT" form-id="product-form"
                           :edit-name="true" :editable="true" :version-editable="…" :ai="false">
               <x-slot:extra>… more fields in the same form …</x-slot:extra>
               <x-slot:actions>… Save …</x-slot:actions>
           </x-product.card>
--}}
@props([
    'product',
    'version' => null,
    'action' => null,
    'method' => 'PUT',
    'formId' => 'product-form',
    'editName' => false,
    'editable' => false,
    'versionEditable' => false,
    'ai' => false,
    'heading' => true,
    'versionLabel' => false,
    'descriptionMax' => 5000,
    'models' => true,
])
@php
    $isForm = $action !== null;
    $productModels = $models ? $product->models : collect();
    $cardId = 'pcard-'.$product->id;
@endphp
<section {{ $attributes->class(['a-panel a-pcard']) }} aria-label="{{ __('products.card.label') }}">
    <div class="a-pcard-grid">
        @include('products._picture', ['product' => $product])

        <div class="a-pcard-main">
            @if ($isForm)
                <form id="{{ $formId }}" method="POST" action="{{ $action }}" class="a-pcard-form">
                    @csrf
                    @method($method)
            @endif

            {{-- Name and reference --}}
            @if ($isForm && $editName && $editable)
                <div class="a-form-grid">
                    <div class="a-field">
                        <label class="a-label" for="{{ $cardId }}-name">{{ __('products.name') }}</label>
                        <input id="{{ $cardId }}-name" name="name" class="a-input" value="{{ old('name', $product->name) }}" required maxlength="160" @error('name') aria-invalid="true" @enderror>
                        @error('name')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="a-field">
                        <label class="a-label" for="{{ $cardId }}-reference">{{ __('products.reference') }}</label>
                        <input id="{{ $cardId }}-reference" name="reference" class="a-input" value="{{ old('reference', $product->reference) }}" maxlength="80" @error('reference') aria-invalid="true" @enderror>
                        @error('reference')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            @elseif ($heading)
                <div class="a-pcard-head">
                    <h2 class="a-pcard-name">{{ $product->name }}</h2>
                    @if ($product->reference)
                        <span class="a-chip a-chip-sm a-chip-code" title="{{ __('products.reference') }}">{{ $product->reference }}</span>
                    @endif
                </div>
            @endif

            {{-- Description --}}
            @if ($isForm && $editable)
                <div class="a-field">
                    <label class="a-label" for="{{ $cardId }}-description">{{ __('products.description') }}</label>
                    <textarea id="{{ $cardId }}-description" name="description" class="a-textarea" rows="5" maxlength="{{ $descriptionMax }}" @error('description') aria-invalid="true" @enderror>{{ old('description', $product->description) }}</textarea>
                    @error('description')<p class="a-error">{{ $message }}</p>@enderror
                    @if ($ai && $version)
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ai.origin type="product" :key="$product->id" field="description" :value="$product->description" />
                            <x-ai.assist :version="$version" type="product" :key="$product->id" field="description" />
                        </div>
                    @endif
                </div>
            @else
                <div class="a-pcard-block">
                    @unless ($heading)
                        <span class="a-micro">{{ __('products.description') }}</span>
                    @endunless
                    <p @class(['a-pcard-text', 'a-muted' => ! $product->description])>{{ $product->description ?: __('products.no_description') }}</p>
                    @if ($ai && $version && $product->description)
                        <x-ai.origin type="product" :key="$product->id" field="description" :value="$product->description" />
                    @endif
                </div>
            @endif

            {{-- Models (product level, read only: edited in the Product identification) --}}
            @if ($productModels->isNotEmpty())
                <div class="a-pcard-block">
                    <div class="a-pcard-label">
                        <span class="a-micro">{{ __('products.card.models') }}</span>
                        <x-ui.tip :text="__('products.card.models_tip')" />
                    </div>
                    <ul class="a-pcard-models">
                        @foreach ($productModels as $model)
                            <li><x-lucide-box aria-hidden="true" /><span class="font-semibold">{{ $model->value }}</span>@if ($model->notes)<span class="a-muted">{{ $model->notes }}</span>@endif</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Product version (version level) --}}
            @if ($version)
                <div class="a-pcard-block">
                    <div class="a-pcard-label">
                        <span class="a-micro">{{ __('products.card.product_version') }}</span>
                        <x-ui.tip :text="__('products.card.product_version_tip')" />
                        @if ($versionLabel)
                            <span class="a-badge a-badge-neutral">{{ __('products.card.version_label', ['label' => $version->label]) }}</span>
                        @endif
                    </div>
                    @if ($isForm && $versionEditable)
                        <div class="a-pcard-numbers">
                            <div class="a-field">
                                <label class="a-label" for="{{ $cardId }}-hw">{{ __('products.card.hardware_version') }}</label>
                                <input id="{{ $cardId }}-hw" name="hardware_version" class="a-input a-input-sm" maxlength="80" value="{{ old('hardware_version', $version->hardware_version) }}" @error('hardware_version') aria-invalid="true" @enderror>
                                @error('hardware_version')<p class="a-error">{{ $message }}</p>@enderror
                            </div>
                            <div class="a-field">
                                <label class="a-label" for="{{ $cardId }}-sw">{{ __('products.card.software_version') }}</label>
                                <input id="{{ $cardId }}-sw" name="software_version" class="a-input a-input-sm" maxlength="80" value="{{ old('software_version', $version->software_version) }}" @error('software_version') aria-invalid="true" @enderror>
                                @error('software_version')<p class="a-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    @else
                        <dl class="a-pcard-dl">
                            <div><dt>{{ __('products.card.hardware_version') }}</dt><dd @class(['a-muted' => blank($version->hardware_version)])>{{ $version->hardware_version ?: __('products.card.not_set') }}</dd></div>
                            <div><dt>{{ __('products.card.software_version') }}</dt><dd @class(['a-muted' => blank($version->software_version)])>{{ $version->software_version ?: __('products.card.not_set') }}</dd></div>
                        </dl>
                    @endif
                </div>
            @endif

            {{ $extra ?? '' }}

            @if ($isForm)
                    @isset($actions)
                        <div class="a-pcard-foot">{{ $actions }}</div>
                    @endisset
                </form>
            @endif
        </div>

        {{-- Documents of the version --}}
        <div class="a-pcard-docs">
            <div class="a-pcard-label">
                <span class="a-micro">{{ __('products.card.documents') }}</span>
                <x-ui.tip :text="__('products.card.documents_tip')" />
            </div>
            @if ($version)
                @include('documents._panel', ['version' => $version])
            @else
                <p class="a-help">{{ __('products.card.no_version') }}</p>
            @endif
        </div>
    </div>
</section>
