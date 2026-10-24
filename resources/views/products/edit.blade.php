<x-layouts.app :title="__('products.settings')" :product="$product">
    @php
        $locked = $access->isReadOnlyProduct();
        $demo = $access->isDemo();
        $canEditLatest = $latest && ! $locked && $access->canEdit($latest);
    @endphp
    <x-ui.page-header :title="__('products.settings')">
        <x-slot:eyebrow>{{ $product->name }}</x-slot:eyebrow>
        @if ($access->canSeeHistory() && ! auth()->user()->is_guest)
            <x-slot:actions>
                <a href="{{ route('products.history', $product) }}" class="a-btn a-btn-ghost"><x-lucide-history aria-hidden="true" />{{ __('history.link') }}</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="a-stack">
        @if ($locked)
            @if ($demo)
                <div class="a-alert" role="status"><x-lucide-lock aria-hidden="true" /><span>{{ __('products.demo.read_only') }}</span></div>
            @else
                <div class="a-alert a-alert-warning" role="status"><x-lucide-lock aria-hidden="true" /><span>{{ $product->plan_slot === \App\Billing\Plan::SLOT_FREE ? __('plans.locked.over_settings') : __('plans.locked.settings') }}</span></div>
            @endif
            <x-product.card :product="$product" :version="$latest" :version-label="true" />
        @else
            {{-- The product card (same block as the Overview and the Product step): product version and documents of the latest version. --}}
            <x-product.card :product="$product" :version="$latest" :action="route('products.update', $product)" form-id="product-form"
                            :edit-name="true" :editable="true" :version-editable="$canEditLatest" :version-label="true">
                <x-slot:actions>
                    <button type="submit" data-shortcut="mod+s" data-shortcut-label="{{ __('common.save') }}" class="a-btn a-btn-primary"><x-lucide-save aria-hidden="true" />{{ __('common.save') }}</button>
                    <a href="{{ $product->homeUrl() }}" class="a-btn a-btn-ghost">{{ __('common.cancel') }}</a>
                </x-slot:actions>
            </x-product.card>
        @endif

        @can('delete', $product)
            <section class="a-panel a-panel-danger" aria-labelledby="danger-title">
                <div class="a-panel-body flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="a-h3" id="danger-title">{{ $demo ? __('products.demo.remove') : __('products.delete') }}</h2>
                        <p class="a-help mt-0.5">{{ $demo ? __('products.demo.remove_confirm') : __('products.delete_confirm') }}</p>
                    </div>
                    <form method="POST" action="{{ route('products.destroy', $product) }}"
                          x-data="confirmSubmit" data-confirm="{{ $demo ? __('products.demo.remove_confirm') : __('products.delete_confirm') }}" @submit="check">
                        @csrf
                        @method('DELETE')
                        @if ($offerReduce)
                            {{-- A paid product: the admin may also take one unit off the subscription (never automatic). --}}
                            <label class="a-check mb-2">
                                <input type="checkbox" name="reduce_subscription" value="1" aria-describedby="reduce-help">
                                <span>{{ __('plans.billing.reduce', ['price' => \App\Billing\Plan::priceLabel()]) }}</span>
                            </label>
                            <p id="reduce-help" class="a-help mb-2">{{ __('plans.billing.reduce_help') }}</p>
                        @endif
                        <button type="submit" class="a-btn a-btn-danger"><x-lucide-trash-2 aria-hidden="true" />{{ $demo ? __('products.demo.remove') : __('products.delete') }}</button>
                    </form>
                </div>
            </section>
        @endcan
    </div>
</x-layouts.app>
