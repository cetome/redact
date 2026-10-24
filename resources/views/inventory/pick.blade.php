{{--
    Catalogue picker of one inventory list: typical objects from the content pack (EN 18031 examples,
    cetome REDux workbook), best first (App\Assessment\CatalogueSuggestions: linked to the scoping
    answers, then usual for the parts in scope, then the rest; inside each, the pack's "presence").
    Pre-ticked: the ones linked to an answer. Categories on the left (the list's main choice), search,
    tick all shown, suggested groups (one click each, own hidden forms), and a sticky bar to add the
    selection one by one or as one group (store() with catalogue[], as_group, group_name).
    Guided sequence: "Add and continue to <next list>" (continue=1) adds the ticked ones and goes on to
    the next list (its picker while still empty); with nothing ticked it reads "Skip to <next list>".
--}}
<x-layouts.app :title="__('inventory.pick_title', ['list' => \App\Content\Text::of($list['label'])])">
    <x-slot:subnav><x-version-nav :version="$version" active="inventory" /></x-slot:subnav>
    @php
        $text = fn ($v) => \App\Content\Text::of($v);
        $listUrl = route('versions.inventory', [$version, $list['key']]);
        $storeUrl = route('versions.inventory.store', [$version, $list['key']]);
        $fields = collect($list['fields'] ?? []);
        $facet = $fields->first(fn ($f) => $f['type'] === 'select' && ! empty($f['required'])) ?? $fields->firstWhere('type', 'select');
        $optionLabel = fn (array $field, $value) => $text(collect($field['options'] ?? [])->firstWhere('value', $value)['label'] ?? $value);
        $rows = collect($rows)->map(function (array $row) use ($facet) {
            $row['category'] = $facet ? ($row['item']['attributes'][$facet['key']] ?? '_none') : '_none';

            return $row;
        });
        // Blocks follow the ranking: "because you said …" (pre-ticked), "usual for EN 18031-x", the rest.
        $said = $rows->where('tier', 'answer');
        $usual = $rows->where('tier', 'standard');
        $groups = array_filter([
            'said' => $said,
            'usual' => $usual,
            'others' => $rows->where('tier', 'other'),
        ], fn ($g) => $g->isNotEmpty());
        $preticked = $rows->where('strength', 'answer')->count();
        $singular = mb_strtolower($text($list['singular'] ?? $list['label']));
        // Inside the step ("Add and continue to Interfaces") or out of it ("Add and go to Decision trees").
        $nextIsList = $guide->next($list['key']) !== null;
    @endphp

    <x-ui.page-header :title="__('inventory.pick_title', ['list' => mb_strtolower($text($list['label']))])" :subtitle="__('inventory.pick_intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
        <x-slot:actions>
            <a href="{{ $listUrl }}" class="a-btn a-btn-ghost"><x-lucide-arrow-left aria-hidden="true" />{{ __('inventory.back_to_list') }}</a>
        </x-slot:actions>
    </x-ui.page-header>

    <x-inventory.stepper :guide="$guide" :current="$list['key']" :from="$list['key']" :editable="true" />

    @if ($rows->isEmpty())
        <x-ui.empty icon="library" :title="__('inventory.no_catalogue')">
            <a href="{{ route('versions.inventory.new', [$version, $list['key']]) }}" class="a-btn a-btn-primary"><x-lucide-plus aria-hidden="true" />{{ __('inventory.add_item', ['item' => $singular]) }}</a>
        </x-ui.empty>
    @else
        <form method="POST" action="{{ $storeUrl }}" class="a-pick" data-filter-scope>
            @csrf
            <input type="hidden" name="picker" value="1">
            <nav class="a-pick-rail" aria-label="{{ __('inventory.categories') }}" data-facet-target="#pick-rows">
                <button type="button" class="a-pick-cat" data-facet="" aria-pressed="true">{{ __('inventory.all') }} <span>{{ $rows->count() }}</span></button>
                @if ($said->isNotEmpty())
                    <button type="button" class="a-pick-cat" data-facet="said" aria-pressed="false"><x-lucide-wand-sparkles class="size-4" aria-hidden="true" />{{ __('inventory.group_said_short') }} <span>{{ $said->count() }}</span></button>
                @endif
                @if ($usual->isNotEmpty())
                    <button type="button" class="a-pick-cat" data-facet="usual" aria-pressed="false"><x-lucide-badge-check class="size-4" aria-hidden="true" />{{ __('inventory.group_usual_short') }} <span>{{ $usual->count() }}</span></button>
                @endif
                @if ($facet)
                    <p class="a-sidebar-label">{{ $text($facet['label']) }}</p>
                    @foreach ($facet['options'] ?? [] as $option)
                        @php $n = $rows->where('category', $option['value'])->count(); @endphp
                        @if ($n)
                            <button type="button" class="a-pick-cat" data-facet="{{ $option['value'] }}" aria-pressed="false">{{ $text($option['label']) }} <span>{{ $n }}</span></button>
                        @endif
                    @endforeach
                    @if ($rows->where('category', '_none')->isNotEmpty())
                        <button type="button" class="a-pick-cat" data-facet="_none" aria-pressed="false">{{ __('inventory.uncategorised') }} <span>{{ $rows->where('category', '_none')->count() }}</span></button>
                    @endif
                @endif
            </nav>

            <div class="min-w-0">
                <div class="a-toolbar">
                    <label class="a-search max-w-none flex-[1_1_20rem]">
                        <span class="a-sr-only">{{ __('common.search') }}</span>
                        <x-lucide-search aria-hidden="true" />
                        <input type="search" class="a-input" data-filter="#pick-rows" placeholder="{{ __('inventory.search_catalogue', ['count' => $rows->count()]) }}" autocomplete="off" autofocus>
                    </label>
                    <button type="button" class="a-btn a-btn-outline" data-check-visible="#pick-rows"><x-lucide-list-checks aria-hidden="true" />{{ __('inventory.select_shown') }}</button>
                    <span class="a-toolbar-count" data-filter-count data-template="{{ __('ui.shown') }}"></span>
                </div>
                <p class="a-help a-pick-order"><x-lucide-arrow-down-wide-narrow aria-hidden="true" />{{ __('inventory.order_help') }}</p>

                @if ($proposals !== [])
                    <section class="a-proposals" aria-labelledby="pick-proposals">
                        <div class="a-proposals-head">
                            <h2 class="a-micro" id="pick-proposals"><x-lucide-group aria-hidden="true" />{{ __('inventory.proposals_title') }}</h2>
                            <p class="a-help">{{ __('inventory.proposals_help') }}</p>
                        </div>
                        <ul class="a-proposal-list">
                            @foreach ($proposals as $i => $proposal)
                                <li class="a-proposal">
                                    <span class="min-w-0">
                                        <span class="a-proposal-title">{{ __('inventory.proposal_title', ['count' => count($proposal['indexes']), 'name' => $proposal['name']]) }}</span>
                                        <span class="a-proposal-members" title="{{ implode(' · ', $proposal['names']) }}">{{ implode(' · ', $proposal['names']) }}</span>
                                    </span>
                                    <button type="submit" form="proposal-{{ $i }}" class="a-btn a-btn-outline a-btn-sm"><x-lucide-group aria-hidden="true" />{{ __('inventory.proposal_accept') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <div id="pick-rows" class="a-stack gap-4!">
                    @foreach ($groups as $group => $entries)
                        <section data-filter-group aria-labelledby="pick-{{ $group }}">
                            <div class="mb-2 flex flex-wrap items-center gap-2">
                                <h2 class="a-micro flex items-center gap-2" id="pick-{{ $group }}">
                                    @if ($group === 'said')<x-lucide-wand-sparkles class="size-3.5 text-brand-blue" aria-hidden="true" />{{ __('inventory.group_said') }}
                                    @elseif ($group === 'usual')<x-lucide-badge-check class="size-3.5 text-brand-blue" aria-hidden="true" />{{ __('inventory.group_usual') }}
                                    @else{{ __('inventory.others_title') }}@endif
                                    <span class="a-seg-count">{{ $entries->count() }}</span>
                                </h2>
                                @if ($group !== 'said')
                                    <button type="button" class="a-link-btn ml-auto text-label" data-check-visible="#pick-group-{{ $group }}">{{ $group === 'usual' ? __('inventory.select_usual') : __('inventory.select_shown') }}</button>
                                @endif
                            </div>
                            <ul class="a-pick-list" id="pick-group-{{ $group }}">
                                @foreach ($entries as $row)
                                    @php
                                        $item = $row['item'];
                                        $attributes = $item['attributes'] ?? [];
                                        $chips = [];
                                        foreach ($fields as $f) {
                                            $v = $attributes[$f['key']] ?? null;
                                            // Free text (a source, a URL, a note) is not a tag: it repeats the name or
                                            // the description (owner, 2026-10-23: no duplicated information).
                                            if ($v === null || $v === '' || $v === [] || $v === false || $f === $facet
                                                || ! in_array($f['type'], ['select', 'multiselect', 'boolean', 'yesno'], true)) {
                                                continue;
                                            }
                                            $chips[] = match ($f['type']) {
                                                'select' => $optionLabel($f, $v),
                                                'multiselect' => collect((array) $v)->map(fn ($x) => $optionLabel($f, $x))->implode(', '),
                                                'boolean' => $text($f['label']),
                                                'yesno' => $text($f['label']).': '.__('inventory.'.$v),
                                                default => \Illuminate\Support\Str::limit((string) $v, 40),
                                            };
                                        }
                                        $description = $text($item['description'] ?? '');
                                        // "In scope: EN 18031-1" ×3 reads as one chip listing the parts.
                                        $prefix = __('inventory.reason_standard', ['standard' => '']);
                                        [$byStandard, $byAnswer] = collect($row['reasons'])->partition(fn ($r) => str_starts_with($r, $prefix));
                                        $row['reasons'] = $byStandard->isEmpty() ? $byAnswer->all()
                                            : [__('inventory.reason_standard', ['standard' => $byStandard->map(fn ($r) => mb_substr($r, mb_strlen($prefix)))->implode(', ')]), ...$byAnswer->all()];
                                        $facets = trim($row['category'].' '.$group);
                                    @endphp
                                    <li data-filter-item data-search="{{ $row['name'] }} {{ $description }}" data-facet-value="{{ $facets }}">
                                        <label @class(['a-pick-row', 'is-added' => $row['added']])>
                                            <input type="checkbox" name="catalogue[]" value="{{ $row['index'] }}" @checked($row['strength'] === 'answer') @disabled($row['added'])
                                                   data-group-key="{{ $row['group'] }}" data-group-label="{{ $row['group_name'] }}">
                                            <span class="min-w-0">
                                                <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <span class="a-pick-name">{{ $row['name'] }}</span>
                                                    @if ($row['presence'])
                                                        <span class="a-badge a-presence is-{{ $row['presence'] }}" title="{{ __('inventory.presence_'.$row['presence'].'_help') }}">@if ($row['presence'] === 'typical')<x-lucide-star aria-hidden="true" />@endif{{ __('inventory.presence_'.$row['presence']) }}</span>
                                                    @endif
                                                    @if ($facet && $row['category'] !== '_none')<span class="a-badge a-badge-neutral">{{ $optionLabel($facet, $row['category']) }}</span>@endif
                                                    @if ($row['added'])<span class="a-badge a-badge-success"><x-lucide-check aria-hidden="true" />{{ __('inventory.already_added') }}</span>
                                                    @elseif (! $row['complete'])<span class="a-badge a-badge-warning" title="{{ __('inventory.needs_details_help') }}">{{ __('inventory.needs_details') }}</span>@endif
                                                </span>
                                                @if ($description)<span class="a-pick-desc">{{ $description }}</span>@endif
                                                @if ($chips || $row['reasons'])
                                                    <span class="a-chips mt-1.5">
                                                        @foreach ($row['reasons'] as $reason)
                                                            <span class="a-chip a-chip-sm a-chip-reason"><x-lucide-wand-sparkles aria-hidden="true" />{{ $reason }}</span>
                                                        @endforeach
                                                        @foreach (array_slice($chips, 0, 6) as $chip)
                                                            <span class="a-chip a-chip-sm">{{ $chip }}</span>
                                                        @endforeach
                                                        @if (count($chips) > 6)<span class="a-chip a-chip-sm">+{{ count($chips) - 6 }}</span>@endif
                                                    </span>
                                                @endif
                                                @if (! empty($item['source']))
                                                    <span class="a-pick-source" title="{{ $item['source'] }}"><x-lucide-book-open aria-hidden="true" />{{ $item['source'] }}</span>
                                                @endif
                                            </span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                    <x-ui.empty icon="search" :title="__('ui.nothing_matches')" data-filter-empty hidden />
                </div>

                <div @class(['a-savebar a-pick-bar a-continue-bar', 'is-empty' => $preticked === 0]) data-group-bar="#pick-rows" data-continue-bar="#pick-rows">
                    {{-- DOM order keeps "Add selected" the default button (Enter); CSS puts the group part first. --}}
                    <span class="text-sm"><b data-checked-count="#pick-rows">{{ $preticked }}</b> {{ __('inventory.selected_count') }}</span>
                    <span class="a-pick-bar-actions">
                        <button type="submit" class="a-btn a-btn-outline" data-needs-checked="#pick-rows"><x-lucide-list-plus aria-hidden="true" />{{ __('inventory.add_selected') }}</button>
                        {{-- Without script it always adds what is ticked, then goes on (nothing ticked: it skips). --}}
                        <button type="submit" name="continue" value="1" class="a-btn a-btn-primary a-continue-btn" data-shortcut="n" data-shortcut-label="{{ __('inventory.shortcut_continue') }}">
                            <span class="a-continue-add">{{ __($nextIsList ? 'inventory.add_continue' : 'inventory.add_continue_step', ['step' => $continue['label']]) }}</span>
                            <span class="a-continue-skip">{{ __('inventory.skip_to', ['step' => $continue['label']]) }}</span>
                            <x-lucide-arrow-right aria-hidden="true" />
                        </button>
                    </span>
                    <span class="a-pick-bar-group">
                        <label class="a-sr-only" for="pick-group-name">{{ __('inventory.group_name') }}</label>
                        <input id="pick-group-name" name="group_name" class="a-input a-input-sm" maxlength="200" autocomplete="off"
                               placeholder="{{ __('inventory.group_name_hint') }}" title="{{ __('inventory.group_name') }}" data-group-name data-template="{{ $groupTemplate }}">
                        <button type="submit" name="as_group" value="1" class="a-btn a-btn-outline" data-group-submit title="{{ __('inventory.group_selected_help') }}"><x-lucide-group aria-hidden="true" />{{ __('inventory.add_as_group') }}</button>
                    </span>
                </div>
            </div>
        </form>

        {{-- One small form per suggested group (its button sits in the picker above, form="…"). --}}
        @foreach ($proposals as $i => $proposal)
            <form method="POST" action="{{ $storeUrl }}" id="proposal-{{ $i }}" hidden>
                @csrf
                <input type="hidden" name="as_group" value="1">
                <input type="hidden" name="return" value="pick">
                <input type="hidden" name="group_name" value="{{ $proposal['name'] }}">
                @foreach ($proposal['indexes'] as $index)
                    <input type="hidden" name="catalogue[]" value="{{ $index }}">
                @endforeach
            </form>
        @endforeach
    @endif
</x-layouts.app>
