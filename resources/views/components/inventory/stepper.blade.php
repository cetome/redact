{{--
    The guided sequence of "Assets & interfaces" (App\Assessment\InventoryGuide): one numbered chip
    per list (name and count, none shown for an empty list still to do; done = has objects or
    "none"). Plain links: linkable, no script.
    Below 40rem only the current chip keeps its name.
    At the right end of the row, outside the sequence: "Mappings" (count of links, aria-current on
    that view) and, for editors, "Prefill everything" with its confirmation modal (/prefill
    without script). $from: the list the mappings page goes back to.

    <x-inventory.stepper :guide="$guide" current="assets" from="assets" :editable="true" />
--}}
@props(['guide', 'current', 'from' => null, 'editable' => false])
@php
    $mappings = $guide->mappings($from);
    $onMappings = $current === \App\Assessment\InventoryGuide::MAPPINGS;
    $plan = $editable ? $guide->prefillPlan() : null;
    // The modal's fallback opens it over the list; closing it comes back to the page it was opened from.
    $prefillOpen = request()->routeIs('versions.inventory.prefill.show');
    $prefillUrl = route('versions.inventory.prefill.show', [request()->route('version'), request()->route('list')]);
    $here = $prefillOpen ? route('versions.inventory', [request()->route('version'), request()->route('list')]) : url()->current();
@endphp
<div class="a-guide-row">
    <nav class="a-guide" aria-label="{{ __('inventory.guide_label') }}">
        <ol class="a-guide-steps">
            @foreach ($guide->steps() as $step)
                <li>
                    <a href="{{ $step['href'] }}" @class(['a-guide-step', 'is-done' => $step['done'], 'is-current' => $step['key'] === $current])
                       @if ($step['key'] === $current) aria-current="step" @endif title="{{ $step['label'] }}">
                        <span class="a-guide-no" aria-hidden="true">@if ($step['done'] && $step['key'] !== $current)<x-lucide-check />@else{{ $loop->iteration }}@endif</span>
                        <span class="a-guide-label">{{ $step['label'] }}</span>
                        @if ($step['none'])
                            <span class="a-guide-count is-none">{{ __('inventory.none_short') }}</span>
                        @elseif ($step['count'] > 0 || $step['key'] === $current)
                            <span class="a-guide-count">{{ $step['count'] }}</span>
                        @endif
                        @if ($step['done'])<span class="a-sr-only">· {{ __('inventory.step_done') }}</span>@endif
                    </a>
                </li>
            @endforeach
        </ol>
    </nav>

    @if ($mappings || $plan)
        <div class="a-guide-tools">
            @if ($plan)
                @if ($plan['total'] > 0)
                    <a href="{{ $prefillUrl }}" class="a-btn a-btn-outline a-btn-sm" data-dialog-open="prefill-all" title="{{ __('inventory.prefill_help') }}">
                        <x-lucide-wand-sparkles aria-hidden="true" />{{ __('inventory.prefill_button') }}<span class="a-guide-tool-count">{{ $plan['total'] }}</span>
                    </a>
                @else
                    <span class="a-guide-tool-off" title="{{ __('inventory.prefill_nothing') }}">
                        <button type="button" class="a-btn a-btn-outline a-btn-sm" disabled aria-describedby="prefill-nothing"><x-lucide-wand-sparkles aria-hidden="true" />{{ __('inventory.prefill_button') }}</button>
                        <span class="a-sr-only" id="prefill-nothing">{{ __('inventory.prefill_nothing') }}</span>
                    </span>
                @endif
            @endif
            @if ($mappings)
                <a href="{{ $mappings['href'] }}" @class(['a-btn a-btn-sm a-guide-map', 'a-btn-primary' => $onMappings, 'a-btn-outline' => ! $onMappings])
                   @if ($onMappings) aria-current="page" @endif title="{{ __('inventory.mappings_help') }}">
                    <x-lucide-network aria-hidden="true" />{{ __('inventory.links') }}<span class="a-guide-tool-count">{{ $mappings['count'] }}</span>
                </a>
            @endif
        </div>
    @endif
</div>

{{-- "Prefill everything": what will be added, per list, each item a chip chosen by default; only the
     chosen ones are posted (the server recomputes the proposals and adds nothing else). inventory.js:
     [data-prefill] keeps "n of N", Select all / None and the button's count up to date. --}}
@if ($plan && $plan['total'] > 0)
    <x-ui.drawer id="prefill-all" :modal="true" :open="$prefillOpen" :close-url="$here" :title="__('inventory.prefill_title')">
        <p class="a-help">{{ __('inventory.prefill_intro') }}</p>
        <form method="POST" action="{{ route('versions.inventory.prefill', $guide->version()) }}" id="prefill-form" data-prefill>
            @csrf
            <ul class="a-prefill-lists">
                @foreach ($plan['lists'] as $listKey => $entry)
                    <li>
                        <details class="a-prefill-list" data-prefill-list>
                            <summary>
                                <x-lucide-chevron-right class="a-prefill-chevron" aria-hidden="true" /><b>{{ $entry['label'] }}</b>
                                <span class="a-spacer"></span>
                                <span class="a-prefill-count" data-prefill-count data-template="{{ __('inventory.prefill_count') }}">{{ __('inventory.prefill_count', ['n' => count($entry['rows']), 'total' => count($entry['rows'])]) }}</span>
                                <button type="button" class="a-link-btn a-prefill-toggle" data-prefill-toggle data-all="{{ __('inventory.prefill_all') }}" data-none="{{ __('inventory.prefill_none') }}" hidden>{{ __('inventory.prefill_none') }}</button>
                            </summary>
                            <div class="a-chips a-prefill-chips">
                                @foreach ($entry['rows'] as $row)
                                    <label class="a-chip-toggle a-prefill-chip">
                                        <input type="checkbox" name="items[]" value="{{ $listKey }}:{{ $row['index'] }}" checked>
                                        <x-lucide-check aria-hidden="true" /><span>{{ $row['name'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </details>
                    </li>
                @endforeach
            </ul>
        </form>
        @if ($plan['skipped'] !== [])
            <p class="a-help">{{ __('inventory.prefill_skipped', ['lists' => mb_strtolower(implode(', ', $plan['skipped']))]) }}</p>
        @endif
        <p class="a-alert"><x-lucide-info aria-hidden="true" /><span>{{ __('inventory.prefill_note') }}</span></p>
        <x-slot:footer>
            <span class="a-spacer"></span>
            <a href="{{ $here }}" class="a-btn a-btn-ghost" data-dialog-close>{{ __('common.cancel') }}</a>
            <button type="submit" form="prefill-form" class="a-btn a-btn-primary" data-prefill-submit
                    data-one="{{ trans_choice('inventory.prefill_submit', 1, ['count' => 1]) }}" data-many="{{ trans_choice('inventory.prefill_submit', 2, ['count' => ':count']) }}"><x-lucide-wand-sparkles aria-hidden="true" /><span>{{ trans_choice('inventory.prefill_submit', $plan['total'], ['count' => $plan['total']]) }}</span></button>
        </x-slot:footer>
    </x-ui.drawer>
@endif
