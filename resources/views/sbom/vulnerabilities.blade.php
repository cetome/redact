<x-layouts.app :title="__('sbom.results.title')">
    <x-slot:subnav><x-version-nav :version="$version" active="inventory" /></x-slot:subnav>
    @php
        $text = fn ($v) => \App\Content\Text::of($v);
        $componentsUrl = route('versions.inventory', [$version, $componentList['key']]);
        $vulnListUrl = route('versions.inventory', [$version, $vulnerabilityList['key']]);
        $severityBadge = ['critical' => 'a-sev-critical', 'high' => 'a-sev-high', 'medium' => 'a-sev-medium', 'low' => 'a-sev-low', 'none' => 'a-sev-none'];
        $sourceStates = $latest?->sources ?? [];
        $failedSources = $latest ? collect($latest->failedSources())->map(fn ($s) => __('sbom.sources.'.$s.'.title'))->implode(', ') : '';
        $canDecide = $editable && $components->isNotEmpty();
        $newIds = $weekly ? $weekly['new']->pluck('id')->flip()->all() : [];
        // Shown at once: known exploited and CVSS 7 or more; the rest (possible matches below High too) folds.
        $isTop = fn ($f) => $f->status !== 'dismissed' && ($f->exploited || ($f->score() ?? 0) >= 7);
    @endphp
    <x-read-only-notice :access="$access" :version="$version" />

    <x-ui.page-header :title="__('sbom.results.title')" :subtitle="__('sbom.results.intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
        <x-slot:actions>
            <a href="{{ $componentsUrl }}" class="a-btn a-btn-ghost"><x-lucide-arrow-left aria-hidden="true" />{{ $text($componentList['label']) }}</a>
            @if ($editable)
                <a href="{{ route('versions.sbom', $version) }}" class="a-btn a-btn-outline" title="{{ __('sbom.vex.button_help') }}"><x-lucide-shield-check aria-hidden="true" />{{ __('sbom.vex.button') }}</a>
                <form method="POST" action="{{ route('versions.vulnerabilities.check', $version) }}">
                    @csrf
                    <button type="submit" class="a-btn a-btn-primary" @disabled($refusal !== null || $running || $components->isEmpty()) title="{{ $refusal ?? __('sbom.vulnerabilities_tooltip') }}">
                        <x-lucide-radar aria-hidden="true" />{{ $lastChecked ? __('sbom.check.again') : __('sbom.check.button') }}
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- State of the last check: progress while it runs (polled), what each source answered. --}}
    <section class="a-panel a-vuln-run mb-3" x-data="vulnCheckStatus" data-url="{{ route('versions.vulnerabilities.status', $version) }}"
             data-running="{{ $running ? '1' : '0' }}" data-template="{{ __('sbom.check.running') }}" data-queued="{{ __('sbom.check.queued') }}" data-finished="{{ __('sbom.check.finished') }}">
        <div class="a-panel-body a-vuln-run-body">
            <div class="a-vuln-run-main">
                @if ($running)
                    <p class="a-vuln-run-title" role="status" aria-live="polite">
                        <x-lucide-loader class="animate-spin" aria-hidden="true" />
                        <span x-text="label">{{ $running->state === 'queued' ? __('sbom.check.queued') : __('sbom.check.running', ['done' => $running->done, 'total' => $running->total]) }}</span>
                    </p>
                    <progress class="a-progress" max="{{ max(1, $running->total) }}" value="{{ $running->done }}" x-bind:max="max" x-bind:value="done"></progress>
                @else
                    <p class="a-vuln-run-title">
                        <x-lucide-shield-check aria-hidden="true" />
                        <span>{{ $lastChecked ? __('sbom.check.last', ['date' => $lastChecked->isoFormat('D MMMM YYYY, HH:mm')]) : __('sbom.check.never') }}</span>
                    </p>
                @endif
                @if ($refusal !== null && $editable)
                    <p class="a-help">{{ $refusal }}</p>
                @elseif (! $running)
                    <p class="a-help">{{ __('sbom.check.auto') }} @if (! $nvdKey){{ __('sbom.check.nvd_slow') }}@endif</p>
                @endif
            </div>
            @if ($sourceStates)
                <ul class="a-vuln-sources" aria-label="{{ __('sbom.attribution.title') }}">
                    @foreach (['euvd', 'nvd', 'osv', 'kev'] as $source)
                        @php $state = $sourceStates[$source]['state'] ?? 'skipped'; @endphp
                        <li class="a-vuln-source is-{{ $state }}" title="{{ __('sbom.sources.'.$source.'.title') }} · {{ __('sbom.sources.state.'.$state) }}">
                            @if ($state === 'ok')<x-lucide-circle-check aria-hidden="true" />@elseif ($state === 'error')<x-lucide-cloud-off aria-hidden="true" />@elseif ($state === 'deferred')<x-lucide-loader aria-hidden="true" />@else<x-lucide-minus aria-hidden="true" />@endif
                            <span>{{ __('sbom.sources.'.$source.'.name') }}</span>
                            <span class="a-sr-only">{{ __('sbom.sources.state.'.$state) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        @if ($latest && ! $running && $latest->state === 'failed')
            <p class="a-alert a-alert-danger m-3 mt-0!" role="alert"><x-lucide-circle-x aria-hidden="true" /><span>{{ __('sbom.check.failed') }}</span></p>
        @elseif ($latest && ! $running && $failedSources !== '')
            <p class="a-alert a-alert-warning m-3 mt-0!" role="note"><x-lucide-cloud-off aria-hidden="true" /><span>{{ __('sbom.check.partial', ['sources' => $failedSources]) }}</span></p>
        @endif
    </section>

    @if ($components->isEmpty())
        <x-ui.empty icon="package" :title="__('sbom.results.no_components')" :text="__('sbom.results.no_components_help')">
            @if ($editable)
                <a href="{{ route('versions.sbom', $version) }}" class="a-btn a-btn-primary"><x-lucide-file-up aria-hidden="true" />{{ __('sbom.import_button') }}</a>
            @endif
        </x-ui.empty>
    @else
        @if ($weekly)
            @php
                $fresh = $weekly['new']->where('status', '!=', 'dismissed');
                $freshExploited = $fresh->where('exploited', true)->count();
                $weeklyDate = $weekly['check']->finished_at->isoFormat('D MMMM YYYY');
            @endphp
            <div @class(['a-alert mb-3', 'a-alert-warning' => $freshExploited > 0]) role="status">
                <x-lucide-calendar-check aria-hidden="true" />
                <span>
                    <b>{{ __('sbom.weekly.title') }}</b>
                    @if ($fresh->isEmpty())
                        {{ __('sbom.weekly.none', ['date' => $weeklyDate]) }}
                    @else
                        {{ trans_choice('sbom.weekly.new', $fresh->count(), ['count' => $fresh->count(), 'date' => $weeklyDate]) }}
                        @if ($freshExploited) {{ trans_choice('sbom.weekly.exploited', $freshExploited, ['count' => $freshExploited]) }}@endif
                        <span class="a-code">{{ $fresh->sortByDesc(fn ($f) => [$f->exploited, $f->score() ?? 0])->take(6)->pluck('key')->implode(', ') }}@if ($fresh->count() > 6) …@endif</span>
                    @endif
                </span>
            </div>
        @endif

        <div class="a-stats mb-3">
            <x-ui.stat :label="__('sbom.results.open')" :value="$totals['open']" tone="info" />
            <x-ui.stat :label="__('sbom.results.exploited')" :value="$totals['exploited']" :tone="$totals['exploited'] ? 'bad' : 'ok'" />
            <x-ui.stat :label="__('sbom.results.possible')" :value="$totals['possible']" />
            <x-ui.stat :label="__('sbom.results.added')" :value="$totals['added']" tone="ok" :href="$vulnListUrl" />
        </div>

        <div class="a-toolbar" data-filter-scope>
            <x-ui.search target="#vuln-rows" :placeholder="__('sbom.results.search')" />
            <div class="a-seg" role="group" aria-label="{{ __('sbom.results.state') }}" data-facet-target="#vuln-rows">
                <button type="button" class="a-seg-btn" data-facet="" aria-pressed="true">{{ __('sbom.results.all') }}</button>
                <button type="button" class="a-seg-btn" data-facet="open" aria-pressed="false">{{ __('sbom.results.open') }} <span class="a-seg-count">{{ $totals['open'] }}</span></button>
                <button type="button" class="a-seg-btn" data-facet="exploited" aria-pressed="false">{{ __('sbom.results.exploited') }} <span class="a-seg-count">{{ $totals['exploited'] }}</span></button>
                <button type="button" class="a-seg-btn" data-facet="possible" aria-pressed="false">{{ __('sbom.results.possible') }} <span class="a-seg-count">{{ $totals['possible'] }}</span></button>
                <button type="button" class="a-seg-btn" data-facet="added" aria-pressed="false">{{ __('sbom.results.added') }} <span class="a-seg-count">{{ $totals['added'] }}</span></button>
                @if ($totals['dismissed'])
                    <button type="button" class="a-seg-btn" data-facet="dismissed" aria-pressed="false">{{ __('sbom.results.dismissed') }} <span class="a-seg-count">{{ $totals['dismissed'] }}</span></button>
                @endif
            </div>
            <span class="a-toolbar-count" data-filter-count data-template="{{ __('ui.shown') }}"></span>
        </div>

        @if ($canDecide)
            <form method="POST" action="{{ route('versions.vulnerabilities.decide', $version) }}" id="vuln-decide">@csrf</form>
        @endif

        <div class="a-table-wrap">
            <table class="a-table a-table-compact a-vuln-table" id="vuln-rows">
                <thead>
                    <tr>
                        <th scope="col" class="is-shrink a-select-cell">
                            @if ($canDecide)
                                <button type="button" class="a-select-all" data-check-visible="#vuln-rows" title="{{ __('sbom.results.select_shown') }}" aria-label="{{ __('sbom.results.select_shown') }}"><x-lucide-list-checks aria-hidden="true" /></button>
                            @endif
                        </th>
                        <th scope="col">{{ __('sbom.results.vulnerability') }}</th>
                        <th scope="col">{{ __('sbom.results.severity') }}</th>
                        <th scope="col">{{ __('sbom.results.fixed') }}</th>
                        <th scope="col">{{ __('sbom.results.sources') }}</th>
                        <th scope="col">{{ __('sbom.results.state') }}</th>
                    </tr>
                </thead>
                @foreach ($components as $component)
                    @php
                        $list = $findings[$component->id] ?? collect();
                        $scan = $scans[$component->id] ?? null;
                        $kind = $matchKinds[$component->id];
                        $purl = \App\Sbom\Identifiers::get($component, 'purl');
                        $cpe = \App\Sbom\Identifiers::get($component, 'cpe');
                        $componentSearch = $component->code.' '.$component->name.' '.$component->attr('version');
                        $top = $list->filter($isTop)->values();
                        $folded = $list->reject($isTop)->values();
                        $foldedPossible = $folded->where('confidence', 'possible')->count();
                        $rows = $top->map(fn ($f) => [$f, false])->concat($folded->map(fn ($f) => [$f, true]));
                    @endphp
                    <tbody data-filter-group id="component-{{ $component->id }}">
                        <tr class="a-table-group a-vuln-component">
                            <th scope="rowgroup" colspan="6">
                                <div class="a-vuln-component-row">
                                    <span class="a-vuln-component-name">
                                        <span class="a-code">{{ $component->code }}</span>
                                        <b>{{ $component->name }}</b>
                                        @if ($component->attr('version'))<span class="a-code a-muted">{{ $component->attr('version') }}</span>@endif
                                        <span class="a-badge a-badge-outline" title="{{ __('sbom.match.'.$kind.'_help') }}">{{ __('sbom.match.'.$kind) }}</span>
                                    </span>
                                    <span class="a-vuln-component-meta">
                                        @if ($scan?->checked_at)
                                            <span class="a-muted">{{ __('sbom.results.checked', ['date' => $scan->checked_at->isoFormat('D MMM YYYY')]) }}</span>
                                        @endif
                                        @if ($editable)
                                            <details class="a-vuln-ids">
                                                <summary class="a-link-btn">{{ __('sbom.results.identifiers') }}</summary>
                                                <form method="POST" action="{{ route('versions.vulnerabilities.identifiers', [$version, $component]) }}" class="a-vuln-ids-form">
                                                    @csrf
                                                    @method('PUT')
                                                    <label class="a-label" for="purl-{{ $component->id }}">{{ __('sbom.identifiers.purl') }}</label>
                                                    <input id="purl-{{ $component->id }}" name="purl" class="a-input a-input-sm a-code" maxlength="500" value="{{ $purl }}" placeholder="pkg:npm/lodash@4.17.21" spellcheck="false">
                                                    <label class="a-label" for="cpe-{{ $component->id }}">{{ __('sbom.identifiers.cpe') }}</label>
                                                    <input id="cpe-{{ $component->id }}" name="cpe" class="a-input a-input-sm a-code" maxlength="300" value="{{ $cpe }}" placeholder="cpe:2.3:a:vendor:product:version" spellcheck="false">
                                                    <p class="a-help">{{ __('sbom.identifiers.optional') }}</p>
                                                    <button type="submit" class="a-btn a-btn-outline a-btn-sm"><x-lucide-check aria-hidden="true" />{{ __('common.save') }}</button>
                                                </form>
                                            </details>
                                            @if ($refusal === null && $kind !== 'none')
                                                <form method="POST" action="{{ route('versions.vulnerabilities.check', $version) }}">
                                                    @csrf
                                                    <input type="hidden" name="item" value="{{ $component->id }}">
                                                    <button type="submit" class="a-btn a-btn-ghost a-btn-sm a-btn-icon" title="{{ __('sbom.check.one') }}" aria-label="{{ __('sbom.check.one') }} {{ $component->code }}"><x-lucide-refresh-cw aria-hidden="true" /></button>
                                                </form>
                                            @endif
                                        @endif
                                    </span>
                                </div>
                            </th>
                        </tr>
                        @forelse ($rows as [$finding, $isFolded])
                            @if ($isFolded && $loop->index === $top->count())
                                <tr class="a-vuln-more">
                                    <td></td>
                                    <td colspan="5">
                                        <details class="a-vuln-fold" data-vuln-fold>
                                            <summary>
                                                <span class="a-vuln-fold-show"><x-lucide-chevron-down aria-hidden="true" />{{ trans_choice('sbom.results.show_more', $folded->count(), ['count' => $folded->count()]) }}@if ($foldedPossible) <span class="a-muted">· {{ trans_choice('sbom.results.folded_possible', $foldedPossible, ['count' => $foldedPossible]) }}</span>@endif</span>
                                                <span class="a-vuln-fold-hide"><x-lucide-chevron-up aria-hidden="true" />{{ __('sbom.results.show_less') }}</span>
                                            </summary>
                                        </details>
                                    </td>
                                </tr>
                            @endif
                            @php
                                $facets = [$finding->status === 'dismissed' ? 'dismissed' : ($finding->status === 'added' ? 'added' : 'open')];
                                if ($finding->exploited && $finding->status !== 'dismissed') { $facets[] = 'exploited'; }
                                if ($finding->confidence === 'possible' && $finding->status === 'open') { $facets[] = 'possible'; }
                                $vulnItem = $finding->vulnerability_item_id ? ($vulnerabilityItems[$finding->vulnerability_item_id] ?? null) : null;
                                $others = array_values(array_diff($finding->ids(), [$finding->key]));
                                $vex = \App\Sbom\Vex\VexStatus::forFinding($finding);
                            @endphp
                            <tr data-filter-item data-facet-value="{{ implode(' ', $facets) }}" data-search="{{ $componentSearch }} {{ implode(' ', $finding->ids()) }} {{ $finding->title }}"
                                @class(['a-vuln-row', 'is-folded' => $isFolded, 'is-exploited' => $finding->exploited && $finding->status !== 'dismissed', 'is-muted' => $finding->status === 'dismissed'])>
                                <td class="is-shrink a-select-cell">
                                    @if ($canDecide && $finding->status !== 'added')
                                        <input type="checkbox" name="findings[]" value="{{ $finding->id }}" form="vuln-decide" class="a-select-box" aria-label="{{ __('sbom.results.tick', ['id' => $finding->key]) }}">
                                    @endif
                                </td>
                                <td class="a-vuln-main">
                                    <span class="a-vuln-id">
                                        <a href="{{ $finding->urls['euvd'] ?? $finding->urls['nvd'] ?? $finding->urls['osv'] ?? '#' }}" class="a-code font-semibold" target="_blank" rel="noopener noreferrer">{{ $finding->key }}</a>
                                        @if ($finding->exploited)
                                            <span class="a-badge a-badge-danger" title="{{ __('sbom.results.exploited_help', ['sources' => collect($finding->exploited_by ?? [])->map(fn ($s) => __('sbom.exploited_by.'.$s))->implode(', ')]) }}"><x-lucide-flame aria-hidden="true" />{{ __('sbom.results.exploited_badge') }}</span>
                                        @endif
                                        @if ($finding->confidence === 'possible')
                                            <span class="a-badge a-badge-neutral" title="{{ __('sbom.match.possible_help') }}">{{ __('sbom.results.possible_badge') }}</span>
                                        @endif
                                        @if (isset($newIds[$finding->id]))
                                            <span class="a-badge" title="{{ __('sbom.weekly.new_help') }}">{{ __('sbom.weekly.new_badge') }}</span>
                                        @endif
                                        @if ($vex)
                                            @include('sbom._vex-badge', ['statement' => $vex])
                                        @endif
                                    </span>
                                    @if ($finding->title)
                                        <span class="a-vuln-title">{{ $finding->title }}</span>
                                    @endif
                                    @if ($finding->description)
                                        <span class="a-row-sub line-clamp-2" title="{{ \Illuminate\Support\Str::limit($finding->description, 600) }}">{{ $finding->description }}</span>
                                    @endif
                                    <span class="a-vuln-meta">
                                        @if ($others)<span>{{ __('sbom.results.more_ids', ['ids' => implode(', ', array_slice($others, 0, 4))]) }}</span>@endif
                                        @if ($finding->matched_on)<span title="{{ $finding->matched_on }}">{{ __('sbom.results.matched_on', ['what' => \Illuminate\Support\Str::limit($finding->matched_on, 70)]) }}</span>@endif
                                        @if ($finding->published_on)<span>{{ __('sbom.results.published', ['date' => $finding->published_on->isoFormat('D MMM YYYY')]) }}</span>@endif
                                    </span>
                                    @if (count($finding->history ?? []) > 1)
                                        <details class="a-vuln-history">
                                            <summary class="a-link-btn">{{ trans_choice('sbom.results.history', count($finding->history), ['count' => count($finding->history)]) }}</summary>
                                            <ol>
                                                @foreach (array_reverse($finding->history) as $entry)
                                                    <li><b>{{ __('sbom.results.decision.'.$entry['status']) }}</b> · {{ $entry['by_name'] ?? '—' }} · {{ \Illuminate\Support\Carbon::parse($entry['at'])->isoFormat('D MMM YYYY, HH:mm') }}@if (! empty($entry['reason'])) <q>{{ $entry['reason'] }}</q>@endif</li>
                                                @endforeach
                                            </ol>
                                        </details>
                                    @endif
                                </td>
                                <td class="is-nowrap">
                                    @if ($finding->severity || $finding->score() !== null)
                                        <span class="a-sev {{ $severityBadge[$finding->severity ?? 'none'] ?? 'a-sev-none' }}">
                                            @if ($finding->score() !== null)<b>{{ number_format($finding->score(), 1) }}</b>@endif
                                            {{ __('sbom.severity.'.($finding->severity ?? 'unknown')) }}
                                        </span>
                                    @else
                                        <span class="a-muted">{{ __('sbom.severity.unknown') }}</span>
                                    @endif
                                </td>
                                <td class="a-code is-nowrap">{{ $finding->fixed ? implode(', ', array_slice($finding->fixed, 0, 3)) : '—' }}</td>
                                <td class="is-nowrap">
                                    <span class="a-vuln-chips">
                                        @foreach (['euvd', 'nvd', 'osv', 'kev'] as $source)
                                            @if (in_array($source, $finding->sources, true))
                                                @if (isset($finding->urls[$source]))
                                                    <a href="{{ $finding->urls[$source] }}" class="a-vuln-chip is-{{ $source }}" target="_blank" rel="noopener noreferrer" title="{{ __('sbom.sources.'.$source.'.title') }}">{{ __('sbom.sources.'.$source.'.name') }}</a>
                                                @else
                                                    <span class="a-vuln-chip is-{{ $source }}" title="{{ __('sbom.sources.'.$source.'.title') }}">{{ __('sbom.sources.'.$source.'.name') }}</span>
                                                @endif
                                            @endif
                                        @endforeach
                                    </span>
                                </td>
                                <td class="is-nowrap">
                                    @if ($finding->status === 'added')
                                        <a href="{{ route('versions.inventory.edit', [$version, $vulnerabilityList['key'], $finding->vulnerability_item_id]) }}" class="a-badge a-badge-success no-underline">
                                            <x-lucide-check aria-hidden="true" />{{ $vulnItem ? __('sbom.results.status.added', ['code' => $vulnItem->code]) : __('sbom.results.status.added_plain') }}
                                        </a>
                                    @elseif ($finding->status === 'dismissed')
                                        <span class="a-badge a-badge-neutral">{{ __('sbom.results.status.dismissed') }}</span>
                                        @if ($finding->reason)
                                            <span class="a-vuln-reason" title="{{ $finding->reason }}">{{ $finding->reason }}</span>
                                        @endif
                                        @if ($finding->decided_at)
                                            <span class="a-vuln-reason-by">{{ __('sbom.results.decided', ['name' => collect($finding->history ?? [])->last()['by_name'] ?? '—', 'date' => $finding->decided_at->isoFormat('D MMM YYYY')]) }}</span>
                                        @endif
                                    @else
                                        <span class="a-badge a-badge-warning">{{ __('sbom.results.status.open') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr data-filter-item data-facet-value="clean" data-search="{{ $componentSearch }}" class="a-vuln-empty">
                                <td></td>
                                <td colspan="5" class="a-muted">
                                    @if ($kind === 'none')
                                        <x-lucide-circle-help aria-hidden="true" />{{ __('sbom.results.cannot_check') }}
                                    @elseif ($scan?->checked_at)
                                        <x-lucide-shield-check aria-hidden="true" />{{ __('sbom.results.no_findings') }}
                                    @else
                                        <x-lucide-minus aria-hidden="true" />{{ __('sbom.results.not_checked') }}
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                @endforeach
            </table>
            <p class="a-help px-4 py-3" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</p>
        </div>

        @if ($canDecide)
            <div class="a-savebar a-vuln-savebar">
                <span class="a-selectbar-count"><b data-checked-count="#vuln-rows">0</b> {{ __('sbom.results.ticked') }}</span>
                <label class="a-sr-only" for="vuln-reason">{{ __('sbom.results.reason') }}</label>
                <input id="vuln-reason" name="reason" form="vuln-decide" class="a-input a-input-sm a-vuln-reason-input" maxlength="2000" autocomplete="off"
                       placeholder="{{ __('sbom.results.reason_placeholder') }}" value="{{ old('reason') }}" @error('reason') aria-invalid="true" @enderror>
                <span class="a-spacer"></span>
                <button type="submit" form="vuln-decide" name="action" value="reopen" class="a-btn a-btn-ghost" data-needs-checked="#vuln-rows"><x-lucide-rotate-ccw aria-hidden="true" />{{ __('sbom.results.reopen') }}</button>
                <button type="submit" form="vuln-decide" name="action" value="dismiss" class="a-btn a-btn-outline" data-needs-checked="#vuln-rows" title="{{ __('sbom.results.dismiss_help') }}"><x-lucide-eye-off aria-hidden="true" />{{ __('sbom.results.dismiss') }}</button>
                <button type="submit" form="vuln-decide" name="action" value="add" class="a-btn a-btn-primary" data-needs-checked="#vuln-rows" title="{{ __('sbom.results.add_help') }}"><x-lucide-plus aria-hidden="true" />{{ __('sbom.results.add') }}</button>
            </div>
        @endif
    @endif

    @if ($vexOther->isNotEmpty())
        <section class="a-section mt-4" aria-labelledby="vex-other">
            <div class="a-section-head">
                <h2 class="a-h2" id="vex-other">{{ __('sbom.vex.other') }}</h2>
                <p class="a-help">{{ __('sbom.vex.other_help') }}</p>
            </div>
            <div class="a-table-wrap">
                <table class="a-table a-table-compact">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('sbom.vex.vulnerability') }}</th>
                            <th scope="col">{{ __('sbom.vex.component') }}</th>
                            <th scope="col">{{ __('sbom.vex.analysis') }}</th>
                            <th scope="col">{{ __('sbom.vex.detail') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($vexOther as $statement)
                            @php $vulnItem = $statement->vulnerability_item_id ? ($vulnerabilityItems[$statement->vulnerability_item_id] ?? null) : null; @endphp
                            <tr>
                                <td class="is-nowrap">
                                    <span class="a-code font-semibold">{{ $statement->vulnerability_key }}</span>
                                    @if ($vulnItem)
                                        <a href="{{ route('versions.inventory.edit', [$version, $vulnerabilityList['key'], $vulnItem->id]) }}" class="a-code a-muted">{{ $vulnItem->code }}</a>
                                    @endif
                                </td>
                                <td>
                                    @if ($statement->component)
                                        <span class="a-code">{{ $statement->component->code }}</span> {{ $statement->component->name }}
                                    @else
                                        <span class="a-muted">{{ __('sbom.vex.product') }}</span>
                                    @endif
                                </td>
                                <td class="is-nowrap">@include('sbom._vex-badge', ['statement' => $statement])</td>
                                <td class="a-muted max-w-96"><span class="line-clamp-2" title="{{ \Illuminate\Support\Str::limit($statement->explanation(), 800) }}">{{ $statement->explanation() ?: '—' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <footer class="a-vuln-attribution">
        <p class="a-micro">{{ __('sbom.attribution.title') }}</p>
        <p class="a-vuln-credits">{{ __('sbom.attribution.data') }}
            <a href="https://euvd.enisa.europa.eu" target="_blank" rel="noopener noreferrer">{{ __('sbom.attribution.euvd_name') }}</a> ·
            <a href="https://nvd.nist.gov" target="_blank" rel="noopener noreferrer">{{ __('sbom.attribution.nvd_name') }}</a> ·
            <a href="https://osv.dev" target="_blank" rel="noopener noreferrer">{{ __('sbom.attribution.osv_name') }}</a> ·
            <a href="https://www.cisa.gov/known-exploited-vulnerabilities-catalog" target="_blank" rel="noopener noreferrer">{{ __('sbom.attribution.kev_name') }}</a></p>
        <p>{{ __('sbom.attribution.nvd') }} {{ __('sbom.attribution.osv') }} {{ __('sbom.attribution.kev') }}</p>
        <p>{{ __('sbom.attribution.disclaimer') }}</p>
    </footer>
</x-layouts.app>
