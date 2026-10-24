{{--
    Mappings: one relation of the pack as a tick grid (rows = its "from" list, columns = its "to"
    list), like the Mapping_* sheets of the Excel files but readable at 30 x 15 and more: pinned
    first column and header, counts per row and column, a click on a header ticks or clears it,
    autosave a moment after each change (one Save button without script).
--}}
@php
    $text = fn ($v) => \App\Content\Text::of($v);
    $m = $matrix;
    $rowFacet = collect($m['from']['fields'] ?? [])->first(fn ($f) => $f['type'] === 'select' && ! empty($f['required']));
    $mappingsStep = \App\Assessment\InventoryGuide::MAPPINGS;
    $linkSummary = trans_choice('inventory.links_count', $linkTotal, ['count' => $linkTotal]);
@endphp

<div class="a-toolbar">
    <nav class="a-seg" aria-label="{{ __('inventory.relations') }}">
        @foreach ($allRelations as $key => $relation)
            <a href="{{ route('versions.inventory.mappings.relation', [$version, $list['key'], $key]) }}" class="a-seg-btn" @if ($key === $m['key']) aria-current="page" @endif>
                {{ $text($lists[$relation['from']]['label']) }} × {{ $text($lists[$relation['to']]['label']) }}
                <span class="a-seg-count">{{ $m['counts'][$key] ?? 0 }}</span>
            </a>
        @endforeach
    </nav>
</div>

<div class="a-section-head">
    <h2 class="a-h2">{{ $text($m['definition']['label']) }}
        <span class="a-muted font-semibold">· {{ $text($m['from']['label']) }} × {{ $text($m['to']['label']) }}</span></h2>
</div>

@if ($m['rows']->isEmpty() || $m['columns']->isEmpty())
    @php($missing = $m['rows']->isEmpty() ? $m['from'] : $m['to'])
    <x-ui.empty icon="network" :title="__('inventory.no_other', ['list' => mb_strtolower($text($missing['label']))])">
        <a href="{{ route('versions.inventory.new', [$version, $missing['key']]) }}" class="a-btn a-btn-primary"><x-lucide-plus aria-hidden="true" />{{ __('inventory.add_item', ['item' => mb_strtolower($text($missing['singular'] ?? $missing['label']))]) }}</a>
    </x-ui.empty>
    <x-inventory.guide-bar :guide="$guide" :current="$mappingsStep" :back="$list['key']" :summary="$linkSummary" />
@else
    <div class="a-toolbar" data-filter-scope>
        <x-ui.search target="#matrix-rows" :placeholder="__('inventory.search', ['list' => mb_strtolower($text($m['from']['label']))])" />
        @if ($rowFacet)
            <div class="a-seg" role="group" aria-label="{{ $text($rowFacet['label']) }}" data-facet-target="#matrix-rows">
                <button type="button" class="a-seg-btn" data-facet="" aria-pressed="true">{{ __('inventory.all') }}</button>
                @foreach ($rowFacet['options'] ?? [] as $option)
                    @if ($m['rows']->contains(fn ($i) => ($i->getAttribute('attributes')[$rowFacet['key']] ?? null) === $option['value']))
                        <button type="button" class="a-seg-btn" data-facet="{{ $option['value'] }}" aria-pressed="false">{{ $text($option['label']) }}</button>
                    @endif
                @endforeach
            </div>
        @endif
        <span class="a-toolbar-count" data-filter-count data-template="{{ __('ui.shown') }}"></span>
        <span class="a-toolbar-end a-help">{{ __('inventory.matrix_help') }}</span>
    </div>

    <form method="POST" action="{{ route('versions.inventory.links', [$version, $m['key']]) }}" data-autosave>
        @csrf
        @method('PUT')
        <input type="hidden" name="side" value="from">
        <fieldset @disabled(! $editable) class="a-fieldset">
            <div class="a-table-wrap a-table-scroll">
                <table class="a-table a-table-matrix a-matrix" data-tick-grid>
                    <caption class="a-sr-only">{{ $text($m['definition']['label']) }}</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="a-matrix-corner">
                                <span class="block">{{ $text($m['from']['label']) }}</span>
                                <span class="block font-semibold normal-case tracking-normal">↓ × {{ $text($m['to']['label']) }} →</span>
                            </th>
                            @foreach ($m['columns'] as $column)
                                <th scope="col" class="a-matrix-col">
                                    <button type="button" data-check-col="{{ $loop->iteration }}" title="{{ $column->name }} · {{ __('inventory.toggle_column') }}">
                                        <span class="a-code">{{ $column->code }}</span>
                                        <span class="a-matrix-name">{{ $column->name }}</span>
                                    </button>
                                    <span class="a-matrix-count" data-col-count="{{ $loop->iteration }}">0</span>
                                </th>
                            @endforeach
                            <th scope="col" class="is-num">{{ __('inventory.total') }}</th>
                        </tr>
                    </thead>
                    <tbody id="matrix-rows">
                        @foreach ($m['rows'] as $row)
                            <tr data-filter-item data-search="{{ $row->code }} {{ $row->name }}" data-facet-value="{{ $rowFacet ? ($row->getAttribute('attributes')[$rowFacet['key']] ?? '') : '' }}">
                                <th scope="row" class="a-matrix-row">
                                    <input type="hidden" name="rows[]" value="{{ $row->id }}">
                                    <button type="button" data-check-row title="{{ __('inventory.toggle_row') }}">
                                        <span class="a-code">{{ $row->code }}</span>
                                        <span class="a-matrix-row-name">{{ $row->name }}</span>
                                    </button>
                                </th>
                                @foreach ($m['columns'] as $column)
                                    <td class="a-matrix-cell">
                                        <label>
                                            <input type="checkbox" name="links[{{ $row->id }}][]" value="{{ $column->id }}" @checked(isset($m['links'][$row->id][$column->id]))>
                                            <span class="a-sr-only">{{ $row->code }} · {{ $column->code }}</span>
                                        </label>
                                    </td>
                                @endforeach
                                <td class="is-num font-bold" data-row-count>{{ count($m['links'][$row->id] ?? []) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="a-help px-4 py-3" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</p>
            </div>
        </fieldset>
        {{-- The guided bar doubles as the save bar: autosave state, Save (no script), next step. --}}
        <x-inventory.guide-bar :guide="$guide" :current="$mappingsStep" :back="$list['key']" :summary="$linkSummary">
            @if ($editable)
                <span class="a-autosave" data-autosave-status data-saving="{{ __('inventory.saving') }}" data-saved="{{ __('inventory.saved') }}" data-failed="{{ __('inventory.save_failed') }}">{{ __('inventory.autosave') }}</span>
                <button type="submit" class="a-btn a-btn-outline"><x-lucide-check aria-hidden="true" />{{ __('inventory.save_links') }}</button>
            @endif
        </x-inventory.guide-bar>
    </form>
@endif
