<x-layouts.app :title="__('einfo.title')">
    <x-slot:subnav><x-version-nav :version="$version" active="einfo" /></x-slot:subnav>
    @php
        $editable = $access->canEdit($version);
        // Links keep the object and the filter unless told otherwise (path segments; the search lives in einfo.js).
        $state = ['object' => $selected['key'] ?? null, 'missing' => $show === 'missing'];
        $url = function (array $change = []) use ($version, $standard, $state): string {
            $to = array_merge($state, $change);

            return \App\Support\Links::einfo($version, $standard, $to['object'], (bool) $to['missing']);
        };
        $selectedKey = $selected['key'] ?? null;
        $incomplete = count(array_filter($objects, fn (array $o) => $o['done'] < $o['total']));
        $missing = $progress['total'] - $progress['done'];
        $groups = [];
        foreach ($objects as $object) {
            $groups[$object['kind']]['label'] = $object['group'];
            $groups[$object['kind']]['objects'][] = $object;
        }
        $visible = array_filter($objects, fn (array $o) => $o['visible']);
        $matchCount = array_sum(array_map(fn (array $o) => $o['visible'] ? (int) $o['matches'] : 0, $objects));
        $hiddenDone = $selected && $show === 'missing' ? $selected['done'] : 0;
        $hits = $selected ? count(array_filter($selected['fields'], fn (array $f) => $f['hit'])) : 0;
        $stateIcons = ['complete' => 'circle-check', 'partial' => 'contrast', 'empty' => 'circle-dashed'];
        $prefix = 'E.Info.';
    @endphp
    <x-read-only-notice :access="$access" :version="$version" />

    <x-ui.page-header :title="__('einfo.title')" :subtitle="__('einfo.intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" :standard="$standard" /></x-slot:eyebrow>
    </x-ui.page-header>

    <nav class="a-seg mb-4" aria-label="{{ __('einfo.standards') }}">
        @foreach ($standards as $code)
            <a href="{{ \App\Support\Links::einfo($version, $code, null, $show === 'missing') }}" class="a-seg-btn" @if ($code === $standard) aria-current="page" @endif>{{ $pack->standardName($code) }}</a>
        @endforeach
    </nav>

    <div class="a-stats">
        <x-ui.stat :label="__('einfo.documented')" :value="$progress['done']" :total="$progress['total']" bar />
        <x-ui.stat :label="__('einfo.missing')" :value="$missing" :tone="$missing ? 'bad' : 'ok'" />
        <x-ui.stat :label="__('einfo.objects_complete')" :value="count($objects) - $incomplete" :total="count($objects)" />
    </div>

    @if ($objects === [])
        <x-ui.empty icon="file-text" :title="__('einfo.nothing')">
            <a href="{{ route('versions.inventory', [$version, array_key_first($pack->lists())]) }}" class="a-btn a-btn-primary"><x-lucide-boxes aria-hidden="true" />{{ __('workflow.steps.inventory') }}</a>
        </x-ui.empty>
    @else
        <div class="a-toolbar">
            {{-- The search runs in einfo.js (shown by it), across every object. --}}
            <div role="search" class="a-einfo-search" data-einfo-search-box hidden>
                <label class="a-search">
                    <span class="a-sr-only">{{ __('einfo.search_label') }}</span>
                    <x-lucide-search aria-hidden="true" />
                    <input type="search" name="q" value="{{ $q }}" class="a-input a-input-sm" placeholder="{{ __('einfo.search') }}" autocomplete="off" maxlength="100"
                           data-einfo-search data-shortcut="/" data-shortcut-label="{{ __('einfo.search_label') }}">
                </label>
            </div>
            <nav class="a-seg" aria-label="{{ __('einfo.show') }}">
                <a href="{{ $url(['missing' => false]) }}" class="a-seg-btn" data-einfo-link @if ($show === 'all') aria-current="true" @endif>{{ __('einfo.show_all') }} <span class="a-seg-count">{{ count($objects) }}</span></a>
                <a href="{{ $url(['missing' => true]) }}" class="a-seg-btn" data-einfo-link @if ($show === 'missing') aria-current="true" @endif>{{ __('einfo.show_missing') }} <span class="a-seg-count">{{ $incomplete }}</span></a>
            </nav>
            <span class="a-toolbar-count" data-einfo-count data-template="{{ __('einfo.matches') }}">@if ($q !== ''){{ __('einfo.matches', ['fields' => $matchCount, 'objects' => count($visible)]) }}@endif</span>
            <button type="button" class="a-link-btn text-label" data-einfo-clear hidden>{{ __('einfo.clear_search') }}</button>
        </div>

        <div class="a-eval a-einfo" data-einfo data-show="{{ $show }}" data-scope="{{ $version->id }}/{{ $standard }}">
            {{-- Below 64rem: the objects as a compact select (the list takes over on wide screens). --}}
            <form method="POST" action="{{ route('versions.einfo.pick', [$version, $standard]) }}" class="a-einfo-pick" data-einfo-pick>
                @csrf
                <input type="hidden" name="show" value="{{ $show }}">
                <label class="a-sr-only" for="einfo-object">{{ __('einfo.object') }}</label>
                <select id="einfo-object" name="object" class="a-select">
                    @foreach ($groups as $group)
                        <optgroup label="{{ $group['label'] }}">
                            @foreach ($group['objects'] as $object)
                                @if ($object['visible'] || $object['key'] === $selectedKey)
                                    <option value="{{ $object['key'] }}" data-href="{{ $url(['object' => $object['key']]) }}" @selected($object['key'] === $selectedKey)>{{ $object['state'] === 'complete' ? '✓' : '○' }} {{ $object['title'] }} · {{ $object['done'] }}/{{ $object['total'] }}</option>
                                @endif
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <button type="submit" class="a-btn a-btn-outline a-einfo-pick-go">{{ __('einfo.go') }}</button>
            </form>

            {{-- The objects: one at a time, grouped by kind. --}}
            <nav class="a-eval-objects a-einfo-objects" aria-label="{{ __('einfo.objects') }}" data-einfo-objects>
                <p class="a-micro flex items-center justify-between px-2 pb-1"><span>{{ __('einfo.objects') }}</span><span>{{ count($objects) - $incomplete }}/{{ count($objects) }}</span></p>
                @foreach ($groups as $group)
                    <div class="a-einfo-group" data-einfo-group @if (! array_filter($group['objects'], fn ($o) => $o['visible'] || $o['key'] === $selectedKey)) hidden @endif>
                        <p class="a-einfo-group-label">{{ $group['label'] }}</p>
                        <ul>
                            @foreach ($group['objects'] as $object)
                                @php $current = $object['key'] === $selectedKey; @endphp
                                <li data-einfo-item data-fields="{{ json_encode(array_column($object['fields'], 'search')) }}" data-complete="{{ $object['done'] === $object['total'] ? '1' : '0' }}"
                                    @if (! $object['visible'] && ! $current) hidden @endif>
                                    <a href="{{ $url(['object' => $object['key']]) }}" class="a-eval-object a-einfo-object" data-einfo-link title="{{ $object['title'] }}" @if ($current) aria-current="true" @endif>
                                        <span class="a-einfo-state is-{{ $object['state'] }}" title="{{ __('einfo.state.'.$object['state']) }}">
                                            <x-dynamic-component :component="'lucide-'.$stateIcons[$object['state']]" aria-hidden="true" />
                                            <span class="a-sr-only">{{ __('einfo.state.'.$object['state']) }}</span>
                                        </span>
                                        <span class="a-einfo-object-name">@if ($object['item'])<span class="a-code">{{ $object['item']->code }}</span> {{ $object['item']->name }}@else{{ $object['title'] }}@endif</span>
                                        <span class="a-einfo-object-count">{{ $object['done'] }}/{{ $object['total'] }}</span>
                                        <span class="a-einfo-object-hits" title="{{ __('einfo.match_count') }}" data-einfo-hits @if ($object['matches'] === null) hidden @endif>{{ $object['matches'] }}</span>
                                        <x-ui.progress :value="$object['done']" :max="$object['total']" class="a-einfo-object-bar" />
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
                <p class="a-help px-2 py-3" data-einfo-empty data-search-text="{{ __('ui.nothing_matches') }}" @if ($visible !== []) hidden @endif>{{ $q !== '' ? __('ui.nothing_matches') : __('einfo.nothing_missing') }}</p>
            </nav>

            {{-- The selected object: its E.Info fields as one form. --}}
            @php
                $item = $selected['item'];
                $doneHere = $selected['done'];
            @endphp
            <section class="a-panel a-einfo-card min-w-0" aria-labelledby="einfo-object-title" data-einfo-card>
                <header class="a-panel-head">
                    <div class="min-w-0">
                        <p class="a-micro">{{ $selected['singular'] }}</p>
                        <h2 class="a-h2 a-einfo-title" id="einfo-object-title">@if ($item)<span class="a-code">{{ $item->code }}</span> {{ $item->name }}@else{{ $selected['title'] }}@endif</h2>
                    </div>
                    <span class="flex items-center gap-3">
                        <span class="a-einfo-state is-{{ $selected['state'] }}">
                            <x-dynamic-component :component="'lucide-'.$stateIcons[$selected['state']]" aria-hidden="true" />
                            <span>{{ __('einfo.state.'.$selected['state']) }}</span>
                        </span>
                        <x-ui.progress :value="$doneHere" :max="$selected['total']" line class="w-28" />
                    </span>
                </header>

                <form method="POST" action="{{ route('versions.einfo.update', [$version, $standard]) }}" id="einfo-form" data-einfo-form>
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="object" value="{{ $selectedKey }}">
                    <input type="hidden" name="q" value="{{ $q }}" data-einfo-q>
                    <input type="hidden" name="show" value="{{ $show }}">

                    @if ($hiddenDone > 0)
                        <p class="a-einfo-note">
                            <x-lucide-eye-off aria-hidden="true" />
                            <span>{{ trans_choice('einfo.documented_hidden', $hiddenDone, ['count' => $hiddenDone]) }}</span>
                            <a href="{{ $url(['missing' => false]) }}" class="a-link-btn" data-einfo-reveal data-show-label="{{ __('einfo.show_documented') }}" data-hide-label="{{ __('einfo.hide_documented') }}">{{ __('einfo.show_documented') }}</a>
                        </p>
                    @endif
                    <p class="a-einfo-note" data-einfo-nomatch @if ($q === '' || $hits > 0) hidden @endif>
                        <x-lucide-search-x aria-hidden="true" />
                        <span>{{ __('einfo.no_match_here') }}</span>
                        <a class="a-link-btn" data-einfo-first data-template="{{ __('einfo.open_first') }}" hidden></a>
                        <button type="button" class="a-link-btn" data-einfo-clear>{{ __('einfo.clear_search') }}</button>
                    </p>

                    {{-- Texts filled in from the inventory (content pack, E.Info workbook autofill): shown, not saved. --}}
                    @if ($editable && ($selected['prefilled'] > 0 || $selected['outdated'] > 0))
                        <div class="a-einfo-prefill-bar">
                            <x-lucide-boxes aria-hidden="true" />
                            <p>
                                @if ($selected['prefilled'] > 0)<span>{{ trans_choice('einfo.prefill.pending', $selected['prefilled'], ['count' => $selected['prefilled']]) }}</span>@endif
                                @if ($selected['outdated'] > 0)<span>{{ trans_choice('einfo.prefill.outdated', $selected['outdated'], ['count' => $selected['outdated']]) }}</span>@endif
                                <span class="a-einfo-prefill-check">{{ __('einfo.prefill.check') }}</span>
                            </p>
                            <button type="submit" name="accept_prefill" value="1" class="a-btn a-btn-outline a-btn-sm"><x-lucide-check aria-hidden="true" />{{ __('einfo.prefill.accept') }}</button>
                        </div>
                    @endif

                    @php
                        // The fields by requirement (the decision tree of that requirement places them),
                        // then the item's other trees: each with its state and, to edit it, a modal.
                        $blocks = [];
                        foreach ($selected['fields'] as $field) {
                            $blocks[$field['requirement']][] = $field;
                        }
                        $otherTrees = array_diff_key($trees, $blocks);
                    @endphp
                    <fieldset @disabled(! $editable) class="a-fieldset a-einfo-fields">
                        @foreach ($blocks as $requirementCode => $blockFields)
                        <div class="a-einfo-req" data-einfo-req>
                        @if ($requirementCode !== '')
                            @include('einfo._tree-head', ['code' => (string) $requirementCode, 'tree' => $trees[$requirementCode] ?? null, 'itemKey' => $selectedKey,
                                'title' => $trees[$requirementCode]['title'] ?? \App\Content\Text::of($pack->requirement((string) $requirementCode)['title'] ?? '')])
                        @endif
                        @foreach ($blockFields as $field)
                            @php
                                $fieldId = 'e-'.md5($selectedKey.$field['code']);
                                $short = str_starts_with($field['code'], $prefix) ? substr($field['code'], strlen($prefix)) : $field['code'];
                                $optional = $field['conditional'] ?? false;
                                $prefilled = $editable && $field['prefill'] !== null;
                            @endphp
                            <div @class(['a-einfo-field', 'is-filled' => $field['done'], 'is-recheck' => $field['recheck'], 'is-conditional' => $optional, 'is-prefilled' => $prefilled]) data-einfo-field data-search="{{ $field['search'] }}"
                                 @if ($optional) data-optional @endif @if ($field['done']) data-state="done" @endif @if (! $field['hit']) hidden @endif>
                                <div class="a-einfo-field-meta">
                                    <label for="{{ $fieldId }}" class="a-code a-einfo-code">@if ($short !== $field['code'])<span class="a-einfo-code-prefix">{{ $prefix }}</span>@endif{{ $short }}</label>
                                    @if ($field['label'] !== '')
                                        <p class="a-help">{{ $field['label'] }}</p>
                                    @endif
                                    @if ($optional && filled($field['condition'] ?? null))
                                        <p class="a-help a-einfo-condition" title="{{ __('einfo.condition_title') }}">{{ ucfirst($field['condition']) }}</p>
                                    @endif
                                    <p class="a-einfo-field-state">
                                        @if ($optional)
                                            <span class="a-badge a-einfo-missing" title="{{ trans_choice('einfo.conditional_head', 1) }}">{{ __('einfo.conditional') }}</span>
                                        @else
                                            <span class="a-badge a-badge-warning a-einfo-missing">{{ __('einfo.missing') }}</span>
                                        @endif
                                        <span class="a-badge a-badge-success a-einfo-done"><x-lucide-check aria-hidden="true" />{{ __('einfo.documented') }}</span>
                                        @if ($field['recheck'])
                                            <span class="a-badge a-badge-warning a-einfo-recheck" title="{{ __('changes.recheck_help') }}"><x-lucide-refresh-ccw aria-hidden="true" />{{ __('evaluations.status.recheck') }}</span>
                                        @endif
                                    </p>
                                    @if ($field['recheck'] && $editable)
                                        <button type="submit" form="einfo-recheck-{{ $field['entry']->id }}" class="a-btn a-btn-outline a-btn-sm a-einfo-still-valid" title="{{ __('changes.still_valid_help') }}"><x-lucide-check aria-hidden="true" />{{ __('changes.still_valid') }}</button>
                                    @endif
                                </div>
                                <div class="a-einfo-field-input">
                                    <textarea id="{{ $fieldId }}" name="entries[{{ $selectedKey }}][{{ $field['code'] }}]" class="a-textarea" rows="3" maxlength="20000">{{ $field['entry']?->text ?? ($prefilled ? $field['prefill'] : '') }}</textarea>
                                    @if ($prefilled)
                                        <div class="a-einfo-ai">
                                            <x-ai.badge :origin="\App\Enums\Origin::FromInventory" :tooltip="__('ai.origin.from_inventory.tooltip_unsaved')" />
                                            <span class="a-einfo-prefill-hint">{{ __('einfo.prefill.unsaved') }}</span>
                                        </div>
                                    @endif
                                    @if ($editable && $field['refresh'] !== null)
                                        <details class="a-einfo-refresh">
                                            <summary><x-lucide-refresh-cw aria-hidden="true" />{{ __('einfo.prefill.changed') }}</summary>
                                            <p class="a-micro">{{ __('einfo.prefill.new_text') }}</p>
                                            <p class="a-einfo-refresh-text">{{ $field['refresh'] }}</p>
                                        </details>
                                    @endif
                                    @if ($field['entry'])
                                        <div class="a-einfo-ai">
                                            <x-ai.origin type="einfo_entry" :key="$field['entry']->id" field="text" :value="$field['entry']->text" />
                                            @if ($editable)
                                                <x-ai.assist :version="$version" type="einfo_entry" :key="$field['entry']->id" field="text" />
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        </div>
                        @endforeach
                        @if ($otherTrees !== [])
                            <div class="a-einfo-req is-other">
                                <p class="a-einfo-req-other">{{ __('einfo.trees.other') }}</p>
                                @foreach ($otherTrees as $requirementCode => $otherTree)
                                    @include('einfo._tree-head', ['code' => (string) $requirementCode, 'tree' => $otherTree, 'itemKey' => $selectedKey, 'title' => $otherTree['title']])
                                @endforeach
                            </div>
                        @endif
                    </fieldset>

                    {{-- Save bar: pinned to the bottom of the window while the card is on screen. --}}
                    <div class="a-savebar a-einfo-savebar">
                        <span class="inline-flex gap-1">
                            <a @if ($previous) href="{{ $url(['object' => $previous]) }}" @else aria-disabled="true" @endif class="a-btn a-btn-outline a-btn-icon" data-einfo-link data-einfo-prev
                               aria-label="{{ __('einfo.previous') }}" title="{{ __('einfo.previous') }} (K)" data-shortcut="k" data-shortcut-label="{{ __('einfo.previous') }}"><x-lucide-chevron-left aria-hidden="true" /></a>
                            <a @if ($next) href="{{ $url(['object' => $next]) }}" @else aria-disabled="true" @endif class="a-btn a-btn-outline a-btn-icon" data-einfo-link data-einfo-next
                               aria-label="{{ __('einfo.next') }}" title="{{ __('einfo.next') }} (J)" data-shortcut="j" data-shortcut-label="{{ __('einfo.next') }}"><x-lucide-chevron-right aria-hidden="true" /></a>
                        </span>
                        <span class="a-toolbar-count a-einfo-count" data-einfo-progress data-template="{{ __('einfo.object_progress') }}">{{ __('einfo.object_progress', ['done' => $doneHere, 'total' => $selected['total']]) }}</span>
                        @if ($editable)
                            <span class="a-spacer"></span>
                            <button type="submit" class="a-btn a-btn-outline" title="{{ __('einfo.save') }}" data-shortcut="mod+s" data-shortcut-label="{{ __('einfo.save') }}"><x-lucide-save aria-hidden="true" /><span class="a-einfo-save-label">{{ __('einfo.save') }}</span></button>
                            <button type="submit" name="next" value="1" class="a-btn a-btn-primary" data-shortcut="mod+enter" data-shortcut-label="{{ __('einfo.save_next') }}"><x-lucide-check-check aria-hidden="true" />{{ __('einfo.save_next') }}</button>
                        @endif
                    </div>
                </form>
                {{-- "Still valid" of the entries put back to re-check (SignificantChange): forms outside the E.Info form, buttons inside it. --}}
                @if ($editable)
                    @foreach ($selected['fields'] ?? [] as $field)
                        @if ($field['recheck'])
                            <form method="POST" action="{{ route('versions.changes.confirm.einfo', [$version, $field['entry']]) }}" id="einfo-recheck-{{ $field['entry']->id }}" hidden>
                                @csrf
                            </form>
                        @endif
                    @endforeach
                @endif
            </section>
        </div>

        {{-- "Edit the decision tree": the same evaluation as the decision-tree page, in a modal; saving
             comes back here on this item, with the E.Info fields the new outcome places. --}}
        @if ($editable && $selected)
            @foreach ($trees as $requirementCode => $tree)
                @php $formId = 'tree-'.$requirementCode.'-form'; @endphp
                <x-ui.drawer :id="'tree-'.$requirementCode" modal wide :open="$openTree === (string) $requirementCode" class="a-einfo-tree-modal">
                    <x-slot:eyebrow>{{ $selected['title'] }}</x-slot:eyebrow>
                    <x-slot:heading><span class="a-code">{{ $requirementCode }}</span> {{ $tree['title'] }}</x-slot:heading>
                    @include('evaluations._tree-form', [
                        'code' => (string) $requirementCode, 'tree' => $tree['tree'], 'evaluation' => $tree['evaluation'], 'shared' => $tree['shared'], 'check' => $tree['check'],
                        'targetKey' => $selectedKey, 'canEdit' => true, 'prefix' => 'tree-'.$requirementCode.'-', 'formId' => $formId, 'modal' => true,
                        'return' => $url(['object' => $selectedKey]),
                    ])
                    <x-slot:footer>
                        <button type="submit" form="{{ $formId }}" name="inconclusive" value="1" class="a-btn a-btn-ghost a-btn-sm" title="{{ __('assessment.inconclusive_note') }}"><x-lucide-circle-help aria-hidden="true" />{{ __('assessment.inconclusive_action') }}</button>
                        <span class="a-spacer"></span>
                        <button type="button" class="a-btn a-btn-outline" data-dialog-close>{{ __('common.cancel') }}</button>
                        <button type="submit" form="{{ $formId }}" class="a-btn a-btn-primary" data-shortcut="mod+s" data-shortcut-label="{{ __('evaluations.keys.save') }}"><x-lucide-check aria-hidden="true" />{{ __('evaluations.save') }}</button>
                    </x-slot:footer>
                </x-ui.drawer>
            @endforeach
        @endif
    @endif
</x-layouts.app>
