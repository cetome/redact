{{--
    Search box that filters the [data-filter-item] elements inside :target as you type (ui.js).
    The / key focuses the first search box of the page (keyboard shortcuts), hence the hint.
    <x-ui.search target="#risk-rows" :placeholder="__('risks.search')" />
--}}
@props(['target', 'placeholder' => null, 'label' => null])
<label {{ $attributes->class(['a-search']) }}>
    <span class="a-sr-only">{{ $label ?? __('common.search') }}</span>
    <x-lucide-search aria-hidden="true" />
    <input type="search" class="a-input a-input-sm" data-filter="{{ $target }}" placeholder="{{ $placeholder ?? __('common.search') }}" autocomplete="off">
    <kbd class="a-kbd a-kbd-hint a-search-kbd" aria-hidden="true">/</kbd>
</label>
