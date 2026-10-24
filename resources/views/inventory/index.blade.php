<x-layouts.app :title="__('workflow.steps.inventory')">
    <x-slot:subnav><x-version-nav :version="$version" active="inventory" /></x-slot:subnav>
    @php
        $editable = $access->canEdit($version);
        $text = fn ($v) => \App\Content\Text::of($v);
        $singular = $text($list['singular'] ?? $list['label']);
        $listUrl = route('versions.inventory', [$version, $list['key']]);
        $fields = collect($list['fields'] ?? []);
        // The facet: the first required choice (the type), else the first choice.
        $facet = $fields->first(fn ($f) => $f['type'] === 'select' && ! empty($f['required'])) ?? $fields->firstWhere('type', 'select');
        $details = $fields->where('type', 'select')->reject(fn ($f) => $f === $facet)->take(2);
        $flags = $fields->where('type', 'boolean');
        $optionLabel = fn (array $field, $value) => $text(collect($field['options'] ?? [])->firstWhere('value', $value)['label'] ?? $value);
        $pickUrl = route('versions.inventory.pick', [$version, $list['key']]);
        $newIds = array_flip($newIds);
        $label = $text($list['label']);
        $isSbom = \App\Sbom\SbomFeature::isComponentList($list['key']);
        $isVex = \App\Sbom\SbomFeature::isVulnerabilityList($list['key']);
        // Objects whose required type is not given yet (catalogue entries, imports): "Type to decide".
        $typeRequired = $facet && ! empty($facet['required']);
        $untyped = $typeRequired ? $items->filter(fn ($i) => in_array($i->getAttribute('attributes')[$facet['key']] ?? null, [null, ''], true))->count() : 0;
        $itemsLabel = fn (int $n) => $n.' '.mb_strtolower($n === 1 ? $singular : $label);
        // The list's mappings, a secondary button of the bar ("Map to interfaces…"): its first relation.
        $firstRelation = array_key_first($relations);
        $mapLink = $items->isNotEmpty() && $firstRelation !== null ? [
            'label' => __('inventory.map_to', ['list' => mb_strtolower($text($relations[$firstRelation]['other']['label']))]),
            'href' => route('versions.inventory.mappings.relation', [$version, $list['key'], $firstRelation]),
        ] : null;
    @endphp
    <x-read-only-notice :access="$access" :version="$version" />

    <x-ui.page-header :title="__('workflow.steps.inventory')" :subtitle="__('inventory.intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
        @if ($editable && $view === 'list' && $items->isNotEmpty())
            <x-slot:actions>
                @if ($catalogueCount)
                    <a href="{{ $pickUrl }}" class="a-btn a-btn-outline"><x-lucide-library aria-hidden="true" />{{ __('inventory.from_catalogue') }}</a>
                @endif
                @includeWhen(\App\Sbom\SbomFeature::isComponentList($list['key']), 'sbom._inventory-actions')
                @if ($isVex)
                    <a href="{{ route('versions.sbom', $version) }}" class="a-btn a-btn-outline" title="{{ __('sbom.vex.button_help') }}"><x-lucide-shield-check aria-hidden="true" />{{ __('sbom.vex.button') }}</a>
                @endif
                <a href="{{ route('versions.inventory.new', [$version, $list['key']]) }}" class="a-btn a-btn-primary" data-dialog-open="item-add"><x-lucide-plus aria-hidden="true" />{{ __('inventory.add_item', ['item' => mb_strtolower($singular)]) }}</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    {{-- The guided sequence: one list after the other, then the mappings, then the next step. --}}
    <x-inventory.stepper :guide="$guide" :current="$view === 'mappings' ? \App\Assessment\InventoryGuide::MAPPINGS : $list['key']" :from="$list['key']" :editable="$editable" />

    @if ($view === 'mappings')
        @include('inventory._mappings')
    @else
        @if (! empty($list['help']))
            <p class="a-help a-guide-help">{{ $text($list['help']) }}</p>
        @endif

        @if ($items->isEmpty() && $none)
            <x-ui.empty icon="circle-check" class="a-guide-empty is-none" :title="__('inventory.none_title', ['list' => mb_strtolower($label)])" :text="__('inventory.none_text')">
                @if ($editable)
                    <form method="POST" action="{{ route('versions.inventory.none.undo', [$version, $list['key']]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="a-btn a-btn-outline"><x-lucide-undo-2 aria-hidden="true" />{{ __('inventory.none_undo') }}</button>
                    </form>
                @endif
            </x-ui.empty>
        @elseif ($items->isEmpty())
            <x-ui.empty icon="boxes" class="a-guide-empty" :title="$editable ? __('inventory.add_list', ['list' => mb_strtolower($label)]) : __('inventory.empty_list', ['list' => mb_strtolower($label)])"
                        :text="$catalogueCount ? __('inventory.empty_catalogue', ['count' => $catalogueCount]) : __('inventory.empty_help')">
                @if ($editable)
                    @if ($catalogueCount)
                        <a href="{{ $pickUrl }}" class="a-btn a-btn-primary"><x-lucide-library aria-hidden="true" />{{ __('inventory.from_catalogue') }}</a>
                    @endif
                    {{-- The same "Prefill everything" dialog as the stepper's, while some list still has proposals. --}}
                    @if ($guide->prefillPlan()['total'] > 0)
                        <a href="{{ route('versions.inventory.prefill.show', [$version, $list['key']]) }}" class="a-btn a-btn-outline" data-dialog-open="prefill-all" data-empty-prefill
                           title="{{ __('inventory.prefill_help') }}"><x-lucide-wand-sparkles aria-hidden="true" />{{ __('inventory.prefill_button') }}</a>
                    @endif
                    <a href="{{ route('versions.inventory.new', [$version, $list['key']]) }}" @class(['a-btn', 'a-btn-outline' => $catalogueCount, 'a-btn-primary' => ! $catalogueCount]) data-dialog-open="item-add"><x-lucide-plus aria-hidden="true" />{{ __('inventory.add_by_hand') }}</a>
                    @if ($isSbom)
                        <a href="{{ route('versions.sbom', $version) }}" class="a-btn a-btn-outline" title="{{ __('sbom.import_tooltip') }}"><x-lucide-file-up aria-hidden="true" />{{ __('sbom.import_button') }}</a>
                    @elseif ($isVex)
                        <a href="{{ route('versions.sbom', $version) }}" class="a-btn a-btn-outline" title="{{ __('sbom.vex.button_help') }}"><x-lucide-shield-check aria-hidden="true" />{{ __('sbom.vex.button') }}</a>
                    @endif
                    <form method="POST" action="{{ route('versions.inventory.none', [$version, $list['key']]) }}" class="a-guide-none">
                        @csrf
                        <button type="submit" class="a-btn a-btn-ghost" title="{{ __('inventory.none_action_help') }}"><x-lucide-circle-slash aria-hidden="true" />{{ __('inventory.none_action') }}</button>
                    </form>
                @endif
            </x-ui.empty>
        @else
            <div class="a-toolbar" data-filter-scope>
                <x-ui.search target="#item-rows" :placeholder="__('inventory.search', ['list' => mb_strtolower($text($list['label']))])" />
                @if ($facet)
                    <div class="a-seg" role="group" aria-label="{{ $text($facet['label']) }}" data-facet-target="#item-rows">
                        <button type="button" class="a-seg-btn" data-facet="" aria-pressed="true">{{ __('inventory.all') }} <span class="a-seg-count">{{ $items->count() }}</span></button>
                        @foreach ($facet['options'] ?? [] as $option)
                            @php $n = $items->filter(fn ($i) => ($i->getAttribute('attributes')[$facet['key']] ?? null) === $option['value'])->count(); @endphp
                            @if ($n > 0)
                                <button type="button" class="a-seg-btn" data-facet="{{ $option['value'] }}" aria-pressed="false">{{ $text($option['label']) }} <span class="a-seg-count">{{ $n }}</span></button>
                            @endif
                        @endforeach
                        @if ($untyped > 0)
                            <button type="button" class="a-seg-btn" data-facet="_none" aria-pressed="false">{{ __('inventory.type_to_decide') }} <span class="a-seg-count is-warning">{{ $untyped }}</span></button>
                        @endif
                    </div>
                @endif
                <span class="a-toolbar-count" data-filter-count data-template="{{ __('ui.shown') }}"></span>
            </div>

            {{-- Select rows, then Group: one object for the trees and E.Info (InventoryController::group). --}}
            @if ($editable && $items->count() > 1)
                <form method="POST" action="{{ route('versions.inventory.group', [$version, $list['key']]) }}" id="group-form" class="a-selectbar" data-select-bar="#item-rows">
                    @csrf
                    <span class="a-selectbar-count"><b data-select-count>0</b> {{ __('inventory.selected_bar') }}</span>
                    <label class="a-sr-only" for="group-form-name">{{ __('inventory.group_name') }}</label>
                    <input id="group-form-name" name="name" class="a-input a-input-sm" maxlength="200" autocomplete="off" placeholder="{{ __('inventory.group_name') }}">
                    <button type="submit" class="a-btn a-btn-outline a-btn-sm" data-select-needs="2" title="{{ __('inventory.group_selected_help') }}"><x-lucide-group aria-hidden="true" />{{ __('inventory.group_selected') }}</button>
                    <span class="a-help a-selectbar-help">{{ __('inventory.group_selected_help') }}</span>
                </form>
            @endif

            <div class="a-table-wrap a-table-scroll">
                <table class="a-table">
                    <thead>
                        <tr>
                            @if ($editable && $items->count() > 1)
                                <th scope="col" class="is-shrink a-select-cell">
                                    <button type="button" class="a-select-all" data-select-all="#item-rows" title="{{ __('inventory.select_all_shown') }}" aria-label="{{ __('inventory.select_all_shown') }}"><x-lucide-list-checks aria-hidden="true" /></button>
                                </th>
                            @endif
                            <th scope="col" class="is-shrink">{{ __('inventory.code') }}</th>
                            <th scope="col">{{ __('inventory.name') }}</th>
                            @if ($facet)<th scope="col">{{ $text($facet['label']) }}</th>@endif
                            @if ($details->isNotEmpty())<th scope="col">{{ __('inventory.details') }}</th>@endif
                            @if ($flags->isNotEmpty())<th scope="col" class="max-[100rem]:hidden">{{ __('inventory.properties') }}</th>@endif
                            @if ($relations)<th scope="col">{{ __('inventory.links') }}</th>@endif
                            <th scope="col" class="is-shrink"><span class="a-sr-only">{{ __('common.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody id="item-rows">
                        @foreach ($items as $item)
                            @php
                                $values = $item->getAttribute('attributes') ?? [];
                                $on = $flags->filter(fn ($f) => ($values[$f['key']] ?? false) === true)->map(fn ($f) => $text($f['label']))->values();
                                $memberNames = $item->memberNames();
                            @endphp
                            <tr id="item-{{ $item->id }}" data-filter-item data-search="{{ $item->code }} {{ $item->name }} {{ $item->description }} {{ implode(' ', $memberNames) }}"
                                data-facet-value="{{ $facet ? (($values[$facet['key']] ?? '') ?: '_none') : '' }}" @class(['is-current' => $editing?->id === $item->id, 'is-new' => isset($newIds[$item->id]), 'is-group' => $memberNames !== []])>
                                @if ($editable && $items->count() > 1)
                                    <td class="is-shrink a-select-cell">
                                        <input type="checkbox" name="items[]" value="{{ $item->id }}" form="group-form" class="a-select-box" data-select-item aria-label="{{ __('inventory.select_item', ['code' => $item->code]) }}">
                                    </td>
                                @endif
                                <td class="is-shrink">
                                    <a href="{{ route('versions.inventory.edit', [$version, $list['key'], $item->id]) }}" class="a-row-link a-code" data-row-link data-dialog-fetch="item-drawer">{{ $item->code }}</a>
                                </td>
                                <td class="min-w-48">
                                    <span class="font-semibold">{{ $item->name }}</span>@includeWhen(\App\Sbom\SbomFeature::isComponentList($list['key']), 'sbom._row-status', ['item' => $item])@includeWhen($isVex, 'sbom._vex-status', ['item' => $item])
                                    @if ($memberNames !== [])
                                        <details class="a-members" data-no-row-click>
                                            <summary title="{{ implode(' · ', $memberNames) }}"><span class="a-badge a-badge-group"><x-lucide-group aria-hidden="true" />{{ __('inventory.group_of', ['count' => count($memberNames)]) }}</span><span class="a-members-preview">{{ implode(' · ', $memberNames) }}</span></summary>
                                            <ul>
                                                @foreach ($item->memberList() as $member)
                                                    <li><b>{{ $member['name'] }}</b>@if (! empty($member['code']) && $member['code'] !== $item->code) <span class="a-code a-muted">{{ $member['code'] }}</span>@endif @if (! empty($member['description']))<span class="a-muted"> · {{ $member['description'] }}</span>@endif</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @elseif ($item->description)
                                        @php $rowLine = ($isVex ? \App\Sbom\Vex\VexStatus::detailLine($item) : null) ?? $item->description; @endphp
                                        <span class="a-row-sub line-clamp-1" title="{{ $rowLine }}">{{ $rowLine }}</span>
                                    @endif
                                </td>
                                @if ($facet)
                                    <td class="is-nowrap">
                                        @if ($values[$facet['key']] ?? null)
                                            <span class="a-badge a-badge-neutral">{{ $optionLabel($facet, $values[$facet['key']]) }}</span>
                                        @elseif ($typeRequired)
                                            <span class="a-badge a-badge-warning" title="{{ __('inventory.type_to_decide_help') }}"><x-lucide-circle-help aria-hidden="true" />{{ __('inventory.type_to_decide') }}</span>
                                        @else
                                            <span class="a-muted">—</span>
                                        @endif
                                    </td>
                                @endif
                                @if ($details->isNotEmpty())
                                    <td class="a-muted text-label is-nowrap">{{ $details->map(fn ($f) => ($values[$f['key']] ?? null) ? $optionLabel($f, $values[$f['key']]) : null)->filter()->implode(' · ') ?: '—' }}</td>
                                @endif
                                @if ($flags->isNotEmpty())
                                    <td class="max-[100rem]:hidden">
                                        @if ($on->isNotEmpty())
                                            <span class="flex items-center gap-1" title="{{ $on->implode(' · ') }}">
                                                <span class="a-chip a-chip-sm max-w-40"><span class="truncate">{{ $on->first() }}</span></span>
                                                @if ($on->count() > 1)<span class="a-chip a-chip-sm">+{{ $on->count() - 1 }}</span>@endif
                                            </span>
                                        @else
                                            <span class="a-muted">—</span>
                                        @endif
                                    </td>
                                @endif
                                @if ($relations)
                                    <td class="is-nowrap text-label">
                                        @foreach ($relations as $key => $relation)
                                            @php $n = $relation['counts'][$item->id] ?? 0; @endphp
                                            <a href="{{ route('versions.inventory.mappings.relation', [$version, $list['key'], $key]) }}" @class(['a-chip a-chip-sm mr-0.5 no-underline', 'opacity-50' => $n === 0])
                                               title="{{ $text($relation['definition']['label']) }} · {{ $n }} {{ mb_strtolower($text($relation['other']['label'])) }}">
                                                {{ rtrim($relation['other']['code_prefix'] ?? mb_substr($text($relation['other']['label']), 0, 3), '-') }} <b>{{ $n }}</b>
                                            </a>
                                        @endforeach
                                    </td>
                                @endif
                                <td class="is-shrink">
                                    <span class="a-row-actions">
                                        <a href="{{ route('versions.inventory.edit', [$version, $list['key'], $item->id]) }}" class="a-btn a-btn-ghost a-btn-icon a-btn-sm" data-dialog-fetch="item-drawer"
                                           aria-label="{{ __('common.edit') }} {{ $item->code }}" title="{{ __('common.edit') }}"><x-lucide-pencil aria-hidden="true" /></a>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="a-help px-4 py-3" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</p>
            </div>
        @endif

        <x-inventory.guide-bar :guide="$guide" :current="$list['key']" :primary="$items->isNotEmpty() || $none" :map="$mapLink"
            :summary="$none ? __('inventory.none_short') : $itemsLabel($items->count())"
            :warning="$untyped > 0 ? trans_choice('inventory.untyped', $untyped, ['count' => $untyped, 'item' => mb_strtolower($singular), 'items' => mb_strtolower($label)]) : null" />

        {{-- Add: a modal with the blank form; the catalogue has its own page (pick). --}}
        @if ($editable)
            <x-ui.drawer id="item-add" :modal="true" :wide="true" :open="$adding" :close-url="$listUrl" :title="__('inventory.add_item', ['item' => mb_strtolower($singular)])">
                @if ($catalogueCount)
                    <a href="{{ $pickUrl }}" class="a-alert no-underline hover:border-brand-blue">
                        <x-lucide-library aria-hidden="true" />
                        <span><b>{{ __('inventory.from_catalogue') }}</b> · {{ __('inventory.pick_hint', ['count' => $catalogueCount]) }}</span>
                        <x-lucide-arrow-right class="ml-auto size-4 shrink-0" aria-hidden="true" />
                    </a>
                @endif
                @include('inventory._item-form', ['item' => null, 'formId' => 'item-add-form', 'action' => route('versions.inventory.store', [$version, $list['key']])])
                <x-slot:footer>
                    <span class="a-spacer"></span>
                    <a href="{{ $listUrl }}" class="a-btn a-btn-ghost" data-dialog-close>{{ __('common.cancel') }}</a>
                    <button type="submit" form="item-add-form" class="a-btn a-btn-primary"><x-lucide-plus aria-hidden="true" />{{ __('inventory.add_item', ['item' => mb_strtolower($singular)]) }}</button>
                </x-slot:footer>
            </x-ui.drawer>
        @endif

        {{-- Edit: the side panel of one object (loaded in place by data-dialog-fetch, or /{item} without script). --}}
        <x-ui.drawer id="item-drawer" wide :open="$editing !== null" :close-url="$listUrl">
            @if ($editing)
                <x-slot:eyebrow>{{ $singular }}</x-slot:eyebrow>
                <x-slot:heading><span class="a-code">{{ $editing->code }}</span> {{ $editing->name }}</x-slot:heading>
                @include('inventory._item-form', ['item' => $editing, 'formId' => 'item-edit-form', 'action' => route('versions.inventory.update', [$version, $list['key'], $editing])])

                @if ($relations)
                    <section class="mt-5 border-t border-line pt-4" aria-labelledby="item-links-title">
                        <h3 class="a-micro mb-2" id="item-links-title">{{ __('inventory.links') }}</h3>
                        <div class="a-stack gap-3!">
                            @foreach ($relations as $key => $relation)
                                @php
                                    $others = $version->inventoryItems()->where('list', $relation['other']['key'])->get();
                                    $linked = $version->inventoryLinks()->where('relation', $key)
                                        ->where($relation['reverse'] ? 'to_item_id' : 'from_item_id', $editing->id)
                                        ->pluck($relation['reverse'] ? 'from_item_id' : 'to_item_id')->flip();
                                @endphp
                                <form method="POST" action="{{ route('versions.inventory.links', [$version, $key]) }}" data-autosave class="a-field">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="side" value="{{ $relation['reverse'] ? 'to' : 'from' }}">
                                    <input type="hidden" name="rows[]" value="{{ $editing->id }}">
                                    <p class="a-label">{{ $text($relation['definition']['label']) }} <span class="a-label-hint">· {{ $text($relation['other']['label']) }}</span>
                                        <span class="a-autosave" data-autosave-status data-saving="{{ __('inventory.saving') }}" data-saved="{{ __('inventory.saved') }}" data-failed="{{ __('inventory.save_failed') }}"></span></p>
                                    @if ($others->isEmpty())
                                        <p class="a-help">{{ __('inventory.no_other', ['list' => mb_strtolower($text($relation['other']['label']))]) }}</p>
                                    @else
                                        <fieldset @disabled(! $editable) class="a-fieldset a-chips">
                                            @foreach ($others as $other)
                                                <label class="a-chip-toggle" title="{{ $other->name }}">
                                                    <input type="checkbox" name="links[{{ $editing->id }}][]" value="{{ $other->id }}" @checked(isset($linked[$other->id]))>
                                                    <span><b>{{ $other->code }}</b> {{ \Illuminate\Support\Str::limit((string) $other->name, 28) }}</span>
                                                </label>
                                            @endforeach
                                        </fieldset>
                                        @if ($editable)
                                            <noscript><button type="submit" class="a-btn a-btn-outline a-btn-sm mt-1"><x-lucide-check aria-hidden="true" />{{ __('inventory.save_links') }}</button></noscript>
                                        @endif
                                    @endif
                                </form>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($editable)
                    <form method="POST" action="{{ route('versions.inventory.destroy', [$version, $list['key'], $editing]) }}" id="item-delete-form"
                          x-data="confirmSubmit" data-confirm="{{ __('inventory.delete_confirm') }}" @submit="check">
                        @csrf
                        @method('DELETE')
                    </form>
                    @if ($editing->isGroup())
                        <form method="POST" action="{{ route('versions.inventory.ungroup', [$version, $list['key'], $editing]) }}" id="item-ungroup-form"
                              x-data="confirmSubmit" data-confirm="{{ __('inventory.ungroup_confirm') }}" @submit="check">
                            @csrf
                        </form>
                    @endif
                    <x-slot:footer>
                        <button type="submit" form="item-delete-form" class="a-btn a-btn-danger"><x-lucide-trash-2 aria-hidden="true" />{{ __('common.delete') }}</button>
                        @if ($editing->isGroup())
                            <button type="submit" form="item-ungroup-form" class="a-btn a-btn-outline" title="{{ __('inventory.ungroup_help') }}"><x-lucide-ungroup aria-hidden="true" />{{ __('inventory.ungroup') }}</button>
                        @endif
                        <span class="a-spacer"></span>
                        <a href="{{ $listUrl }}" class="a-btn a-btn-ghost" data-dialog-close>{{ __('common.cancel') }}</a>
                        <button type="submit" form="item-edit-form" class="a-btn a-btn-primary"><x-lucide-check aria-hidden="true" />{{ __('common.save') }}</button>
                    </x-slot:footer>
                @endif
            @endif
        </x-ui.drawer>
    @endif
</x-layouts.app>
