{{--
    Compact app page header: eyebrow (optional), title with the cetome square, subtitle, actions.
    <x-ui.page-header :title="__('risks.title')" :subtitle="__('risks.intro')">
        <x-slot:eyebrow>EN 18031-1</x-slot:eyebrow>
        <x-slot:actions><a class="a-btn a-btn-primary" …>Add</a></x-slot:actions>
    </x-ui.page-header>
--}}
@props(['title', 'subtitle' => null, 'muted' => null])
<header {{ $attributes->class(['a-page-head']) }}>
    <div class="a-page-head-main">
        @isset($eyebrow)
            <p class="a-eyebrow">{{ $eyebrow }}</p>
        @endisset
        <h1 class="a-title">{{ $title }}@if ($muted) <span class="a-title-muted">{{ $muted }}</span>@endif</h1>
        @if ($subtitle)
            <p class="a-subtitle">{{ $subtitle }}</p>
        @endif
        {{ $slot }}
    </div>
    @isset($actions)
        <div class="a-page-actions">{{ $actions }}</div>
    @endisset
</header>
