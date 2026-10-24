<x-layouts.app :title="__('risks.title')">
    <x-slot:subnav><x-version-nav :version="$version" active="risks" /></x-slot:subnav>
    @php
        $editable = $access->canEdit($version);
        $text = fn ($v) => \App\Content\Text::of($v);
        $base = route('versions.risks', $version);
        $levelOf = fn ($impact, $likelihood) => $impact && $likelihood ? ($cells[$impact.'-'.$likelihood] ?? null) : null;
        $rows = $entries->map(function ($entry) use ($levelOf) {
            $initial = $levelOf($entry->impact, $entry->likelihood);

            return ['entry' => $entry, 'initial' => $initial, 'worse' => $initial && $entry->residual_rank && $entry->residual_rank < $initial];
        });
        $high = fn ($rank) => $rank !== null && $rank <= 2; // ranks 1 and 2 are the two highest levels
        $libraryLeft = collect($library)->where('added', false)->count();
        $suggestedThreats = collect($library)->where('strength', 'answer');
        $libraryGroups = [
            'answer' => collect($library)->where('strength', 'answer'),
            'standard' => collect($library)->where('strength', 'standard'),
            'other' => collect($library)->filter(fn ($r) => $r['strength'] === null && ! $r['added']),
            'added' => collect($library)->where('added', true),
        ];
        $mechNames = collect($mechanisms)->flatMap(fn ($f) => [$f['code'] => $f['name'], ...array_map(fn ($r) => $r['title'], $f['requirements'])])->all();
    @endphp

    {{-- Level colours are user-defined: one nonce-tagged stylesheet instead of style attributes (CSP). --}}
    <style nonce="{{ app('csp-nonce') }}">
        @foreach ($levels as $rank => $level)
            @if (preg_match('/^#[0-9A-Fa-f]{6}$/', $level->color))
                @php
                    // Black or white text, whichever reads better on the chosen colour (WCAG luminance).
                    [$r, $g, $b] = array_map(fn ($h) => (($c = hexdec($h) / 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4), str_split(substr($level->color, 1), 2));
                    $ink = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) > 0.18 ? '#0f172a' : '#fff';
                @endphp
                .risk-level-{{ (int) $rank }} { background-color: {{ $level->color }}; color: {{ $ink }}; }
                .risk-dot-{{ (int) $rank }} { color: {{ $level->color }}; }
            @endif
        @endforeach
    </style>

    <x-read-only-notice :access="$access" :version="$version" />

    <x-ui.page-header :title="__('risks.title')" :subtitle="__('risks.intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
        <x-slot:actions>
            <a href="{{ route('versions.risks.matrix.show', $version) }}" class="a-btn a-btn-ghost" data-dialog-open="risk-matrix"><x-lucide-grid-3x3 aria-hidden="true" />{{ __('risks.matrix_settings') }}</a>
            @if ($editable)
                @if ($library)
                    <a href="{{ route('versions.risks.library.show', $version) }}" class="a-btn a-btn-outline" data-dialog-open="risk-library"><x-lucide-library aria-hidden="true" />{{ __('risks.library') }}@if ($suggestedThreats->isNotEmpty())<span class="a-badge ml-1" title="{{ __('risks.suggested_help') }}">{{ $suggestedThreats->count() }}</span>@endif</a>
                @endif
                <a href="{{ route('versions.risks.new', $version) }}" class="a-btn a-btn-primary" data-dialog-open="risk-new"><x-lucide-plus aria-hidden="true" />{{ __('risks.add') }}</a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($entries->isEmpty())
        <x-ui.empty icon="shield-alert" :title="__('risks.empty_title')" :text="__('risks.empty')">
            @if ($editable)
                @if ($suggestedThreats->isNotEmpty())
                    <form method="POST" action="{{ route('versions.risks.library', $version) }}">
                        @csrf
                        @foreach ($suggestedThreats as $row)
                            <input type="hidden" name="threats[]" value="{{ $row['index'] }}">
                        @endforeach
                        <button type="submit" class="a-btn a-btn-primary"><x-lucide-wand-sparkles aria-hidden="true" />{{ trans_choice('risks.add_suggested', $suggestedThreats->count(), ['count' => $suggestedThreats->count()]) }}</button>
                    </form>
                @endif
                @if ($library)
                    <a href="{{ route('versions.risks.library.show', $version) }}" @class(['a-btn', 'a-btn-primary' => $suggestedThreats->isEmpty(), 'a-btn-outline' => $suggestedThreats->isNotEmpty()]) data-dialog-open="risk-library"><x-lucide-library aria-hidden="true" />{{ __('risks.start_library') }}</a>
                @endif
                <a href="{{ route('versions.risks.new', $version) }}" class="a-btn a-btn-outline" data-dialog-open="risk-new"><x-lucide-plus aria-hidden="true" />{{ __('risks.add') }}</a>
            @endif
        </x-ui.empty>
    @else
        <div class="a-stats">
            <x-ui.stat :label="__('risks.stat_risks')" :value="$entries->count()" />
            <x-ui.stat :label="__('risks.stat_initial_high')" :value="$rows->filter(fn ($r) => $high($r['initial']))->count()" />
            <x-ui.stat :label="__('risks.stat_residual_high')" :value="$rows->filter(fn ($r) => $high($r['entry']->residual_rank))->count()" :tone="$rows->contains(fn ($r) => $high($r['entry']->residual_rank)) ? 'bad' : 'ok'" />
            <x-ui.stat :label="__('risks.stat_worse')" :value="$rows->where('worse', true)->count()" :tone="$rows->contains('worse', true) ? 'bad' : null" />
            <x-ui.stat :label="__('risks.stat_unrated')" :value="$rows->filter(fn ($r) => ! $r['initial'] || ! $r['entry']->residual_rank)->count()" />
        </div>

        <div class="a-toolbar" data-filter-scope>
            <x-ui.search target="#risk-rows" :placeholder="__('risks.search')" />
            <div class="a-seg" role="group" aria-label="{{ __('evaluations.show_only') }}" data-facet-target="#risk-rows">
                <button type="button" class="a-seg-btn" data-facet="" aria-pressed="true">{{ __('inventory.all') }} <span class="a-seg-count">{{ $entries->count() }}</span></button>
                <button type="button" class="a-seg-btn" data-facet="high" aria-pressed="false">{{ __('risks.filter_high') }}</button>
                <button type="button" class="a-seg-btn" data-facet="worse" aria-pressed="false">{{ __('risks.filter_worse') }}</button>
                <button type="button" class="a-seg-btn" data-facet="unrated" aria-pressed="false">{{ __('risks.filter_unrated') }}</button>
            </div>
            <span class="a-toolbar-count" data-filter-count data-template="{{ __('ui.shown') }}"></span>
        </div>

        <div class="a-table-wrap a-table-scroll">
            <table class="a-table">
                <thead>
                    <tr>
                        <th scope="col" class="is-shrink">#</th>
                        <th scope="col">{{ __('risks.threat') }}</th>
                        <th scope="col" class="is-shrink">{{ __('risks.stride') }}</th>
                        <th scope="col" class="is-shrink">{{ __('articles.col_article') }}</th>
                        <th scope="col" class="is-shrink">{{ __('risks.initial') }}</th>
                        <th scope="col" class="is-shrink">{{ __('risks.residual_short') }}</th>
                        <th scope="col">{{ __('risks.requirements') }}</th>
                        <th scope="col" class="is-shrink"><span class="a-sr-only">{{ __('common.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody id="risk-rows">
                    @foreach ($rows as $row)
                        @php
                            $entry = $row['entry'];
                            $mech = collect($entry->requirements ?? []);
                            $facets = collect([
                                $high($row['initial']) || $high($entry->residual_rank) ? 'high' : null,
                                $row['worse'] ? 'worse' : null,
                                ! $row['initial'] || ! $entry->residual_rank ? 'unrated' : null,
                            ])->filter()->implode(' ');
                        @endphp
                        <tr id="risk-{{ $entry->id }}" data-filter-item data-search="{{ $entry->threat }} {{ $entry->description }} {{ $mech->implode(' ') }}" data-facet-value="{{ $facets }}"
                            @class(['is-current' => $editing?->id === $entry->id])>
                            <td class="is-shrink a-muted">{{ $loop->iteration }}</td>
                            <td class="min-w-60">
                                <a href="{{ route('versions.risks.edit', [$version, $entry->id]) }}" class="a-row-link" data-row-link data-dialog-fetch="risk-edit">{{ $entry->threat }}</a>
                                @if ($entry->description)<span class="a-row-sub line-clamp-1" title="{{ $entry->description }}">{{ $entry->description }}</span>@endif
                            </td>
                            <td class="is-shrink">
                                <span class="a-letters" aria-label="{{ __('risks.stride') }}: {{ implode(', ', $entry->stride ?? []) ?: '—' }}">
                                    @foreach (\App\Models\RiskEntry::STRIDE as $letter)
                                        <span @class(['a-letter', 'is-on' => in_array($letter, $entry->stride ?? [], true)]) title="{{ $text($stride[$letter] ?? $letter) }}" aria-hidden="true">{{ $letter }}</span>
                                    @endforeach
                                </span>
                            </td>
                            <td class="is-shrink">
                                <span class="a-chips a-art-codes">
                                    @forelse ($entry->articles ?? [] as $code)
                                        <span @class(['a-chip a-chip-sm a-chip-code', 'a-chip-out' => $scopedArticles !== null && ! in_array($code, $scopedArticles, true)]) title="{{ $articles[$code] ?? $code }}@if ($scopedArticles !== null && ! in_array($code, $scopedArticles, true)) · {{ __('risks.not_in_scope') }}@endif">{{ $code }}</span>
                                    @empty
                                        <span class="a-muted">—</span>
                                    @endforelse
                                </span>
                            </td>
                            <td class="is-shrink">
                                @if ($row['initial'])
                                    <span class="a-level risk-level-{{ $row['initial'] }}" title="{{ __('risks.impact') }} {{ $entry->impact }} × {{ __('risks.likelihood') }} {{ $entry->likelihood }}">{{ $levels[$row['initial']]->name ?? '' }}</span>
                                    <span class="ml-1 text-micro text-ink-subtle">{{ $entry->impact }}×{{ $entry->likelihood }}</span>
                                @else
                                    <span class="a-level a-level-empty">{{ __('risks.not_rated') }}</span>
                                @endif
                            </td>
                            <td class="is-shrink">
                                <span class="inline-flex items-center gap-1.5">
                                    @if ($entry->residual_rank)
                                        <span class="a-level risk-level-{{ $entry->residual_rank }}">{{ $levels[$entry->residual_rank]->name ?? '' }}</span>
                                    @else
                                        <span class="a-level a-level-empty">{{ __('risks.not_rated') }}</span>
                                    @endif
                                    @if ($row['worse'])
                                        <x-lucide-triangle-alert class="size-4 text-amber-600 dark:text-amber-400" aria-label="{{ __('risks.residual_worse') }}" role="img" />
                                        <span class="a-sr-only">{{ __('risks.residual_worse') }}</span>
                                    @endif
                                </span>
                            </td>
                            <td>
                                <span class="a-chips">
                                    @foreach ($mech->take(4) as $code)
                                        <span class="a-chip a-chip-sm a-chip-code" title="{{ $mechNames[$code] ?? '' }}">{{ $code }}</span>
                                    @endforeach
                                    @if ($mech->count() > 4)<span class="a-chip a-chip-sm" title="{{ $mech->slice(4)->implode(', ') }}">+{{ $mech->count() - 4 }}</span>@endif
                                    @if ($mech->isEmpty())<span class="a-muted">—</span>@endif
                                </span>
                            </td>
                            <td class="is-shrink">
                                <span class="a-row-actions">
                                    <a href="{{ route('versions.risks.edit', [$version, $entry->id]) }}" class="a-btn a-btn-ghost a-btn-icon a-btn-sm" data-dialog-fetch="risk-edit" aria-label="{{ __('common.edit') }}: {{ $entry->threat }}" title="{{ __('common.edit') }}"><x-lucide-pencil aria-hidden="true" /></a>
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="a-help px-4 py-3" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</p>
        </div>

        {{-- The risks against the essential requirements in scope (previous step) and the EN 18031 requirements they name. --}}
        @include('risks._coverage', ['articles' => $cover['articles'], 'coverage' => $cover['coverage'], 'outside' => $cover['outside'], 'editable' => $editable])
    @endif

    {{-- Next: Assets & interfaces. --}}
    <x-ui.next-step :version="$version" after="risks" class="mt-4" />

    {{-- Edit one risk: side panel (fetched in place, or /risks/{risk} without script). --}}
    <x-ui.drawer id="risk-edit" wide :open="$editing !== null" :close-url="$base">
        @if ($editing)
            <x-slot:eyebrow>{{ __('risks.risk_n', ['n' => $entries->search(fn ($e) => $e->id === $editing->id) + 1]) }}</x-slot:eyebrow>
            <x-slot:heading>{{ $editing->threat }}</x-slot:heading>
            @include('risks._form', ['entry' => $editing, 'formId' => 'risk-edit-form', 'action' => route('versions.risks.update', [$version, $editing]), 'method' => 'PUT'])
            @if ($editable)
                <form method="POST" action="{{ route('versions.risks.destroy', [$version, $editing]) }}" id="risk-delete-form" x-data="confirmSubmit" data-confirm="{{ __('common.confirm_delete') }}" @submit="check">
                    @csrf
                    @method('DELETE')
                </form>
                <x-slot:footer>
                    <button type="submit" form="risk-delete-form" class="a-btn a-btn-danger"><x-lucide-trash-2 aria-hidden="true" />{{ __('common.delete') }}</button>
                    <span class="a-spacer"></span>
                    <a href="{{ $base }}" class="a-btn a-btn-ghost" data-dialog-close>{{ __('common.cancel') }}</a>
                    <button type="submit" form="risk-edit-form" class="a-btn a-btn-primary"><x-lucide-check aria-hidden="true" />{{ __('common.save') }}</button>
                </x-slot:footer>
            @endif
        @endif
    </x-ui.drawer>

    @if ($editable)
        {{-- Add a risk: the same form, blank. --}}
        <x-ui.drawer id="risk-new" wide :open="$adding" :close-url="$base" :title="__('risks.add')">
            @include('risks._form', ['entry' => null, 'formId' => 'risk-new-form', 'action' => route('versions.risks.store', $version), 'method' => 'POST'])
            <x-slot:footer>
                <span class="a-spacer"></span>
                <a href="{{ $base }}" class="a-btn a-btn-ghost" data-dialog-close>{{ __('common.cancel') }}</a>
                <button type="submit" form="risk-new-form" class="a-btn a-btn-primary"><x-lucide-plus aria-hidden="true" />{{ __('risks.add') }}</button>
            </x-slot:footer>
        </x-ui.drawer>

        {{-- The threat library: one click per threat, or several at once. --}}
        @if ($library)
            <x-ui.drawer id="risk-library" wide :open="$panel === 'library'" :close-url="$base" :title="__('risks.library')">
                <p class="a-help mb-3">{{ __('risks.library_help') }}</p>
                <form method="POST" action="{{ route('versions.risks.library', $version) }}" id="library-form">@csrf</form>
                <form method="POST" action="{{ route('versions.risks.library', $version) }}" id="library-one">@csrf</form>
                <div class="a-toolbar" data-filter-scope>
                    <label class="a-search max-w-none">
                        <span class="a-sr-only">{{ __('common.search') }}</span>
                        <x-lucide-search aria-hidden="true" />
                        <input type="search" class="a-input" data-filter="#library-rows" placeholder="{{ __('risks.search_library', ['count' => count($library)]) }}" autocomplete="off" data-autofocus>
                    </label>
                    <button type="button" class="a-btn a-btn-ghost a-btn-sm" data-check-visible="#library-rows"><x-lucide-list-checks aria-hidden="true" />{{ __('inventory.select_shown') }}</button>
                </div>
                <ul class="a-library" id="library-rows">
                    @foreach ($libraryGroups as $groupKey => $groupRows)
                        @continue($groupRows->isEmpty())
                        <li class="a-library-head" data-filter-group-head><span class="a-micro">{{ __('risks.library_group_'.$groupKey) }}</span><span class="a-micro">{{ $groupRows->count() }}</span></li>
                    @foreach ($groupRows as $row)
                        @php
                            $i = $row['index'];
                            $threat = $row['threat'] + ['added' => $row['added']];
                        @endphp
                        <li data-filter-item data-search="{{ $text($threat['threat']) }} {{ $text($threat['description'] ?? '') }} {{ implode(' ', $threat['requirements'] ?? []) }}">
                            <label class="a-library-row" for="lib-{{ $i }}">
                                <input type="checkbox" id="lib-{{ $i }}" name="threats[]" value="{{ $i }}" form="library-form" @checked($row['strength'] === 'answer') @disabled($threat['added'])>
                                <span class="min-w-0">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <b>{{ $text($threat['threat']) }}</b>
                                        @if ($threat['added'])<span class="a-badge a-badge-success"><x-lucide-check aria-hidden="true" />{{ __('inventory.already_added') }}</span>@endif
                                    </span>
                                    @if (! empty($threat['description']))<span class="a-row-sub">{{ $text($threat['description']) }}</span>@endif
                                    @if (! empty($threat['examples']))
                                        <span class="a-seen">
                                            <span class="a-micro">{{ __('risks.seen_in_the_wild') }}</span>
                                            @foreach (array_slice($threat['examples'], 0, 2) as $example)
                                                <a href="{{ $example['url'] }}" target="_blank" rel="noopener noreferrer" class="a-seen-link" data-no-row-click>{{ $text($example['title']) }} ({{ $example['year'] }})<x-lucide-external-link aria-hidden="true" /></a>
                                            @endforeach
                                            @if (count($threat['examples']) > 2)<span class="a-help">{{ trans_choice('risks.more_examples', count($threat['examples']) - 2, ['count' => count($threat['examples']) - 2]) }}</span>@endif
                                        </span>
                                    @endif
                                    @if ($row['reasons'] || ($row['strength'] === 'answer' && ! empty($threat['suggest_note'])))
                                        <span class="a-chips mt-1.5">
                                            @foreach ($row['reasons'] as $reason)
                                                <span class="a-chip a-chip-sm a-chip-reason"><x-lucide-wand-sparkles aria-hidden="true" />{{ $reason }}</span>
                                            @endforeach
                                            @if (! empty($threat['suggest_note']))<span class="a-help">{{ $text($threat['suggest_note']) }}</span>@endif
                                        </span>
                                    @endif
                                    <span class="mt-1.5 flex flex-wrap items-center gap-2">
                                        <span class="a-letters" aria-hidden="true">
                                            @foreach (\App\Models\RiskEntry::STRIDE as $letter)
                                                <span @class(['a-letter', 'is-on' => in_array($letter, $threat['stride'] ?? [], true)])>{{ $letter }}</span>
                                            @endforeach
                                        </span>
                                        @foreach (array_slice($threat['requirements'] ?? [], 0, 6) as $code)
                                            <span class="a-chip a-chip-sm a-chip-code">{{ $code }}</span>
                                        @endforeach
                                    </span>
                                </span>
                            </label>
                            @unless ($threat['added'])
                                <button type="submit" form="library-one" name="threats[]" value="{{ $i }}" class="a-btn a-btn-outline a-btn-sm" aria-label="{{ __('common.add') }}: {{ $text($threat['threat']) }}"><x-lucide-plus aria-hidden="true" />{{ __('common.add') }}</button>
                            @endunless
                        </li>
                    @endforeach
                    @endforeach
                    <li class="a-help py-3" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</li>
                </ul>
                <x-slot:footer>
                    <span class="text-sm"><b data-checked-count="#library-rows">0</b> {{ __('inventory.selected_count') }} · {{ __('risks.library_left', ['count' => $libraryLeft]) }}</span>
                    <span class="a-spacer"></span>
                    <a href="{{ $base }}" class="a-btn a-btn-ghost" data-dialog-close>{{ __('common.close') }}</a>
                    <button type="submit" form="library-form" class="a-btn a-btn-primary" data-needs-checked="#library-rows"><x-lucide-list-plus aria-hidden="true" />{{ __('risks.add_selected') }}</button>
                </x-slot:footer>
            </x-ui.drawer>
        @endif
    @endif

    {{-- Matrix settings: the 5 x 5 matrix and the level names and colours. --}}
    <x-ui.drawer id="risk-matrix" :modal="true" :wide="true" :open="$panel === 'matrix'" :close-url="$base" :title="__('risks.matrix_settings')">
        <form method="POST" action="{{ route('versions.risks.matrix', $version) }}" id="matrix-form" data-risk-matrix>
            @csrf
            @method('PUT')
            <fieldset @disabled(! $editable) class="a-fieldset a-stack">
                <p class="a-help">{{ __('risks.matrix_help') }}</p>
                <div class="overflow-x-auto">
                    <table class="a-matrix-edit">
                        <caption class="a-sr-only">{{ __('risks.matrix') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="text-left"><span class="a-micro">{{ __('risks.impact') }} ↓ · {{ __('risks.likelihood') }} →</span></th>
                                @foreach ([1, 2, 3, 4, 5] as $l)
                                    <th scope="col"><span class="block font-black">{{ $l }}</span><span class="block text-label font-normal text-ink-muted">{{ __('risks.likelihood_scale')[$l] }}</span></th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ([5, 4, 3, 2, 1] as $i)
                                <tr>
                                    <th scope="row" class="text-left"><span class="block font-black">{{ $i }}</span><span class="block text-label font-normal text-ink-muted">{{ __('risks.impact_scale')[$i] }}</span></th>
                                    @foreach ([1, 2, 3, 4, 5] as $l)
                                        @php $rank = $cells[$i.'-'.$l] ?? 3; @endphp
                                        <td>
                                            <select name="cells[{{ $i }}-{{ $l }}]" class="risk-level-{{ $rank }}" aria-label="{{ __('risks.impact') }} {{ $i }}, {{ __('risks.likelihood') }} {{ $l }}">
                                                @foreach ($levels as $r => $level)
                                                    <option value="{{ $r }}" @selected($rank === $r)>{{ $level->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>
                    <p class="a-micro mb-2">{{ __('risks.levels') }}</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($levels as $rank => $level)
                            <div class="flex items-center gap-2">
                                <span class="a-step-no shrink-0" title="{{ __('risks.level_name', ['rank' => $rank]) }}">{{ $rank }}</span>
                                <input type="color" name="levels[{{ $rank }}][color]" value="{{ $level->color }}" class="a-color" aria-label="{{ __('risks.colour') }} {{ $rank }}">
                                <input name="levels[{{ $rank }}][name]" value="{{ $level->name }}" class="a-input" maxlength="40" aria-label="{{ __('risks.level_name', ['rank' => $rank]) }}">
                            </div>
                        @endforeach
                    </div>
                    <p class="a-help mt-2">{{ __('risks.levels_help') }}</p>
                </div>
            </fieldset>
        </form>
        <x-slot:footer>
            <span class="a-spacer"></span>
            <a href="{{ $base }}" class="a-btn a-btn-ghost" data-dialog-close>{{ __('common.close') }}</a>
            @if ($editable)
                <button type="submit" form="matrix-form" class="a-btn a-btn-primary"><x-lucide-check aria-hidden="true" />{{ __('common.save') }}</button>
            @endif
        </x-slot:footer>
    </x-ui.drawer>
</x-layouts.app>
