{{--
    Product picture, or its initials when it has none.
    <x-ui.thumb :product="$product" size="sm|lg" />
--}}
@props(['product', 'size' => null])
@php
    $initials = collect(preg_split('/\s+/', trim((string) $product->name)) ?: [])->filter()->take(2)
        ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp
<span {{ $attributes->class(['a-thumb', 'a-thumb-'.$size => $size]) }} aria-hidden="true">
    @if ($product->image_path)
        <img src="{{ route('products.image', $product) }}" alt="" loading="lazy">
    @else
        {{ $initials }}
    @endif
</span>
