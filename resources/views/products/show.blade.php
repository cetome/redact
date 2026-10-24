{{-- A product without a version (ProductController::show redirects to the latest version's Overview otherwise). --}}
<x-layouts.app :title="$product->name" :product="$product">
    <x-read-only-notice :access="$access" />

    <x-ui.page-header :title="$product->name">
        <x-slot:eyebrow>{{ $product->organisation->name }}@if ($product->reference) · {{ $product->reference }}@endif</x-slot:eyebrow>
        @if ($access->canManage() || ($access->isReadOnlyProduct() && $access->canDelete()))
            <x-slot:actions>
                <a href="{{ route('products.edit', $product) }}" class="a-btn a-btn-outline"><x-lucide-settings aria-hidden="true" />{{ __('products.settings') }}</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="a-stack">
        <x-product.card :product="$product" :heading="false" />

        <section class="a-panel" aria-labelledby="start-version-title">
            <div class="a-panel-head"><h2 class="a-h3" id="start-version-title">{{ __('products.start_version') }}</h2></div>
            <div class="a-panel-body">
                @if ($access->canManage())
                    @include('versions._new-version', ['product' => $product, 'formId' => 'new-version-form'])
                @else
                    <p class="a-help">{{ __('products.no_versions_help') }}</p>
                @endif
            </div>
            @if ($access->canManage())
                <div class="a-panel-foot">
                    <button class="a-btn a-btn-primary" type="submit" form="new-version-form"><x-lucide-plus aria-hidden="true" />{{ __('versions.new') }}</button>
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>
