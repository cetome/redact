{{--
    Empty state with its primary action.
    <x-ui.empty icon="shield-alert" :title="__('risks.empty')" :text="__('risks.empty_help')">
        <a class="a-btn a-btn-primary" …>Start from the library</a>
    </x-ui.empty>
--}}
@props(['icon' => 'inbox', 'title', 'text' => null])
<div {{ $attributes->class(['a-empty']) }}>
    <span class="a-empty-icon"><x-dynamic-component :component="'lucide-'.$icon" aria-hidden="true" /></span>
    <p class="a-empty-title">{{ $title }}</p>
    @if ($text)
        <p class="a-empty-text">{{ $text }}</p>
    @endif
    @if (trim((string) $slot) !== '')
        <div class="a-empty-actions">{{ $slot }}</div>
    @endif
</div>
