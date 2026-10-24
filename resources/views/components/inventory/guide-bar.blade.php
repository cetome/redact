{{--
    Sticky bottom bar of a list of "Assets & interfaces": previous (p), what is there, the list's
    mappings ($map: label + href, a secondary button), next (n). After the last list, "Next step" is
    the workflow's next step (InventoryGuide::afterStep()). On the mappings page (current = MAPPINGS)
    the left button goes back to the list $back. The slot goes before the Next button (the
    mappings' autosave state and Save button).

    <x-inventory.guide-bar :guide="$guide" current="assets" :summary="'12 assets'" :map="['label' => …, 'href' => …]" />
--}}
@props(['guide', 'current', 'summary' => '', 'warning' => null, 'primary' => true, 'map' => null, 'back' => null])
@php
    $onMappings = $current === \App\Assessment\InventoryGuide::MAPPINGS;
    $previous = $onMappings ? ($back ? $guide->step($back) : null) : $guide->previous($current);
    $next = $onMappings ? null : $guide->next($current);
    $target = $next ? ['label' => $next['label'], 'href' => $next['href']] : $guide->afterStep();
    $nextLabel = $next ? __('inventory.next', ['step' => $target['label']]) : __('inventory.next_step', ['step' => $target['label']]);
@endphp
<div {{ $attributes->class(['a-savebar a-guide-bar']) }}>
    @if ($previous)
        <a href="{{ $previous['href'] }}" class="a-btn a-btn-ghost a-guide-prev" data-shortcut="p"
           data-shortcut-label="{{ $onMappings ? __('inventory.shortcut_back') : __('inventory.shortcut_previous') }}">
            <x-lucide-arrow-left aria-hidden="true" /><span class="a-guide-prev-label">{{ $onMappings ? __('inventory.back_to', ['list' => $previous['label']]) : $previous['label'] }}</span>
        </a>
    @endif
    <span class="a-guide-summary">
        @if ($summary !== '')<span>{{ $summary }}</span>@endif
        @if ($warning)<span class="a-guide-warn"><x-lucide-triangle-alert aria-hidden="true" />{{ $warning }}</span>@endif
    </span>
    <span class="a-spacer"></span>
    {{ $slot }}
    @if ($map)
        <a href="{{ $map['href'] }}" class="a-btn a-btn-outline a-guide-maplink" aria-label="{{ $map['label'] }}"><x-lucide-network aria-hidden="true" /><span>{{ $map['label'] }}</span></a>
    @endif
    <a href="{{ $target['href'] }}" @class(['a-btn a-guide-next', 'a-btn-primary' => $primary, 'a-btn-outline' => ! $primary])
       data-shortcut="n" data-shortcut-label="{{ __('inventory.shortcut_next') }}">
        <span class="a-guide-next-label">{{ $primary ? $nextLabel : __('inventory.skip_to', ['step' => $target['label']]) }}</span><x-lucide-arrow-right aria-hidden="true" />
        <kbd class="a-kbd a-kbd-hint">N</kbd>
    </a>
</div>
