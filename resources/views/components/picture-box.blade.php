{{--
    A picture changed by clicking it (the product picture's box, products/_picture): an organisation's
    logo, a person's picture. Chosen files are sent at once (product-card.js, data-autosubmit); the
    small round button at the corner removes it. The same box for a logo and a person's picture.
    <x-picture-box :src="$url" :store="route(...)" :destroy="route(...)" field="logo" :add="__('...')" :alt="..." :confirm="__('...')" :help="..." />
--}}
@props(['src' => null, 'store', 'destroy', 'field', 'add', 'alt', 'confirm', 'help' => null, 'editable' => true, 'accept' => 'image/png,image/jpeg,image/webp'])
<div class="a-pcard-pic">
    <div class="a-pcard-pic-frame">
        @if ($editable)
            <form method="POST" action="{{ $store }}" enctype="multipart/form-data" class="a-pcard-pic-form">
                @csrf
                <label @class(['a-pcard-pic-box', 'is-editable', 'is-empty' => ! $src]) title="{{ $add }}">
                    @if ($src)
                        <img src="{{ $src }}" alt="{{ $alt }}">
                        <span class="a-pcard-pic-change" aria-hidden="true"><x-lucide-pencil aria-hidden="true" />{{ __('products.card.change_picture') }}</span>
                    @else
                        <x-lucide-image class="size-7" aria-hidden="true" />
                        <span class="a-pcard-pic-add">{{ $add }}</span>
                    @endif
                    <input type="file" id="picture-{{ $field }}" name="{{ $field }}" accept="{{ $accept }}" required class="a-sr-only" data-autosubmit
                           aria-label="{{ $add }}" @error($field) aria-invalid="true" @enderror>
                </label>
                <button type="submit" class="a-btn a-btn-outline a-btn-sm mt-2" data-autosubmit-fallback><x-lucide-upload aria-hidden="true" />{{ __('common.save') }}</button>
            </form>
            @if ($src)
                <form method="POST" action="{{ $destroy }}" class="a-pcard-pic-remove-form" x-data="confirmSubmit" data-confirm="{{ $confirm }}" @submit="check">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="a-pcard-pic-remove" title="{{ __('identification.remove_picture') }}">
                        <x-lucide-x aria-hidden="true" /><span class="a-sr-only">{{ __('identification.remove_picture') }}</span>
                    </button>
                </form>
            @endif
        @else
            <div @class(['a-pcard-pic-box', 'is-empty' => ! $src])>
                @if ($src)
                    <img src="{{ $src }}" alt="{{ $alt }}">
                @else
                    <x-lucide-image class="size-7" aria-hidden="true" />
                @endif
            </div>
        @endif
    </div>
    <div class="a-pcard-pic-side">
        @if ($help)
            <p class="a-pcard-pic-help">{{ $help }}</p>
        @endif
        @error($field) <p class="a-error">{{ $message }}</p> @enderror
    </div>
</div>
