{{--
    Inline help: an (i) that shows its text on hover and keyboard focus (CSS only).
    <x-ui.tip :text="__('risks.matrix_help')" />
--}}
@props(['text', 'label' => null])
@php($tipId = 'tip-'.bin2hex(random_bytes(4)))
<span {{ $attributes->class(['a-tip']) }}>
    <button type="button" class="a-tip-btn" aria-describedby="{{ $tipId }}" aria-label="{{ $label ?? __('ui.more_info') }}"><x-lucide-info aria-hidden="true" /></button>
    <span role="tooltip" id="{{ $tipId }}" class="a-tip-text">{{ $text }}</span>
</span>
