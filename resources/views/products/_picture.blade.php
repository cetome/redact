{{--
    Product picture of the product card (CRAted's ProductProfileCard): a fixed square on the left.
    Owner and org admins upload or replace it by clicking the picture itself (product-card.js sends
    the form once a file is chosen; without script a Save button shows) and remove it with the
    small round button at its corner.
    @include('products._picture', ['product' => $product])
--}}
@php $canManage = Gate::allows('manage', $product); @endphp
<div class="a-pcard-pic">
    <div class="a-pcard-pic-frame">
        @if ($canManage)
            <form method="POST" action="{{ route('products.image.store', $product) }}" enctype="multipart/form-data" class="a-pcard-pic-form">
                @csrf
                <label @class(['a-pcard-pic-box', 'is-editable', 'is-empty' => ! $product->image_path])
                       title="{{ $product->image_path ? __('products.card.picture_tooltip_change') : __('products.card.picture_tooltip_add') }}">
                    @if ($product->image_path)
                        <img src="{{ route('products.image', $product) }}" alt="{{ __('identification.picture_alt', ['product' => $product->name]) }}">
                        <span class="a-pcard-pic-change" aria-hidden="true"><x-lucide-pencil aria-hidden="true" />{{ __('products.card.change_picture') }}</span>
                    @else
                        <x-lucide-image class="size-7" aria-hidden="true" />
                        <span class="a-pcard-pic-add">{{ __('products.card.add_picture') }}</span>
                    @endif
                    <input type="file" name="image" accept="image/png,image/jpeg,image/webp" required class="a-sr-only" data-autosubmit
                           aria-label="{{ $product->image_path ? __('identification.replace') : __('identification.upload') }}"
                           @error('image') aria-invalid="true" @enderror>
                </label>
                <button type="submit" class="a-btn a-btn-outline a-btn-sm mt-2" data-autosubmit-fallback><x-lucide-upload aria-hidden="true" />{{ __('common.save') }}</button>
            </form>
            @if ($product->image_path)
                <form method="POST" action="{{ route('products.image.destroy', $product) }}" class="a-pcard-pic-remove-form"
                      x-data="confirmSubmit" data-confirm="{{ __('products.card.remove_picture_confirm') }}" @submit="check">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="a-pcard-pic-remove" title="{{ __('identification.remove_picture') }}">
                        <x-lucide-x aria-hidden="true" /><span class="a-sr-only">{{ __('identification.remove_picture') }}</span>
                    </button>
                </form>
            @endif
        @else
            <div @class(['a-pcard-pic-box', 'is-empty' => ! $product->image_path])>
                @if ($product->image_path)
                    <img src="{{ route('products.image', $product) }}" alt="{{ __('identification.picture_alt', ['product' => $product->name]) }}">
                @else
                    <x-lucide-image class="size-7" aria-hidden="true" />
                    <span class="a-pcard-pic-add">{{ __('identification.no_picture') }}</span>
                @endif
            </div>
        @endif
    </div>
    @if ($canManage)
        <p class="a-pcard-pic-help">{{ __('products.card.picture_limits', ['mb' => (int) (\App\Assessment\ProductImage::MAX_KB / 1024)]) }}</p>
        @error('image') <p class="a-error">{{ $message }}</p> @enderror
    @endif
</div>
