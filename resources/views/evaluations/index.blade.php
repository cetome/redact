<x-layouts.app :title="__('evaluations.title')">
    <x-slot:subnav><x-version-nav :version="$version" active="evaluate" /></x-slot:subnav>
    @php
        $text = fn ($v) => \App\Content\Text::of($v);
        $rows = collect($families)->flatten(1);
        $todo = $rows->filter(fn ($r) => $r['counts']['total'] > $r['counts']['done'] || $r['counts']['unlinked'] > 0)->count();
    @endphp
    <x-read-only-notice :access="$access" :version="$version" />

    <x-ui.page-header :title="__('evaluations.title')" :subtitle="__('evaluations.intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" :standard="$standard" /></x-slot:eyebrow>
        <x-slot:actions>
            <a href="{{ route('versions.evaluate.trees', [$version, $standard]) }}" class="a-btn a-btn-ghost"><x-lucide-git-fork aria-hidden="true" />{{ __('evaluations.view_trees') }}</a>
        </x-slot:actions>
    </x-ui.page-header>

    <nav class="a-seg mb-3" aria-label="{{ __('workflow.standards_in_scope') }}">
        @foreach (array_keys($pack->standards()) as $code)
            <a href="{{ route('versions.evaluate', [$version, $code]) }}" class="a-seg-btn" @if ($code === $standard) aria-current="page" @endif>
                {{ $pack->standardName($code) }}
                @unless (in_array($code, $standards, true))<span class="a-seg-count">{{ __('versions.not_in_scope') }}</span>@endunless
            </a>
        @endforeach
    </nav>

    @unless ($inScope)
        <div class="a-alert a-alert-warning"><x-lucide-info aria-hidden="true" /><span>{{ __('evaluations.not_in_scope') }}</span></div>
    @endunless

    <div class="a-stats">
        <x-ui.stat :label="__('evaluations.progress')" :value="$progress['done']" :total="$progress['total']" bar />
        <x-ui.stat :label="__('verdicts.PASS')" :value="$progress['pass']" tone="ok" />
        <x-ui.stat :label="__('verdicts.FAIL')" :value="$progress['fail']" :tone="$progress['fail'] ? 'bad' : null" />
        @if ($progress['follows'])
            {{-- FAIL verdicts that follow from another walk (e.g. SCM-2 where SCM-1 ends FAIL): not walked, counted once here. --}}
            <x-ui.stat :label="__('evaluations.follows.stat')" :value="$progress['follows']" tone="bad" :title="__('evaluations.follows.stat_help')" />
        @endif
        @if ($progress['unlinked'])
            <x-ui.stat :label="__('evaluations.follows.unlinked')" :value="$progress['unlinked']" tone="bad" :title="__('evaluations.follows.unlinked_title')" />
        @endif
        <x-ui.stat :label="__('verdicts.NOT_APPLICABLE')" :value="$progress['na']" />
        <x-ui.stat :label="__('evaluations.status.incomplete')" :value="$progress['incomplete'] + $progress['invalid']" :tone="$progress['invalid'] ? 'bad' : null" />
        <x-ui.stat :label="__('evaluations.status.unjustified')" :value="$progress['unjustified']" :tone="$progress['unjustified'] ? 'bad' : null" />
        @if ($progress['to_check'])
            <x-ui.stat :label="__('assessment.to_check')" :value="$progress['to_check']" tone="bad" />
        @endif
        @if ($progress['inconclusive'])
            <x-ui.stat :label="__('verdicts.INCONCLUSIVE')" :value="$progress['inconclusive']" tone="bad" />
        @endif
    </div>

    <div class="a-toolbar" data-filter-scope>
        <x-ui.search target="#requirement-rows" :placeholder="__('evaluations.search')" />
        <div class="a-seg" role="group" aria-label="{{ __('evaluations.show_only') }}" data-facet-target="#requirement-rows">
            <button type="button" class="a-seg-btn" data-facet="" aria-pressed="true">{{ __('inventory.all') }} <span class="a-seg-count">{{ $rows->count() }}</span></button>
            <button type="button" class="a-seg-btn" data-facet="todo" aria-pressed="false">{{ __('workflow.to_do') }} <span class="a-seg-count">{{ $todo }}</span></button>
            <button type="button" class="a-seg-btn" data-facet="fail" aria-pressed="false">{{ __('verdicts.FAIL') }} <span class="a-seg-count">{{ $rows->filter(fn ($r) => $r['counts']['fail'] > 0 || $r['counts']['follows'] > 0)->count() }}</span></button>
            <button type="button" class="a-seg-btn" data-facet="unjustified" aria-pressed="false">{{ __('evaluations.status.unjustified') }} <span @class(['a-seg-count', 'is-warning' => $progress['unjustified']])>{{ $rows->filter(fn ($r) => $r['counts']['unjustified'] > 0)->count() }}</span></button>
            <button type="button" class="a-seg-btn" data-facet="inconclusive" aria-pressed="false">{{ __('verdicts.INCONCLUSIVE') }} <span @class(['a-seg-count', 'is-warning' => $progress['inconclusive']])>{{ $rows->filter(fn ($r) => $r['counts']['inconclusive'] > 0)->count() }}</span></button>
            <button type="button" class="a-seg-btn" data-facet="done" aria-pressed="false">{{ __('workflow.done') }}</button>
        </div>
        <span class="a-toolbar-count" data-filter-count data-template="{{ __('ui.shown') }}"></span>
    </div>

    <div class="a-table-wrap">
        <table class="a-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('evaluations.requirement') }}</th>
                    <th scope="col" class="max-md:hidden">{{ __('evaluations.applies_to') }}</th>
                    <th scope="col" class="w-[20%]">{{ __('evaluations.progress') }}</th>
                    <th scope="col" class="max-md:hidden">{{ __('evaluations.verdicts') }}</th>
                    <th scope="col" class="is-shrink"><span class="a-sr-only">{{ __('common.actions') }}</span></th>
                </tr>
            </thead>
            <tbody id="requirement-rows">
                @foreach ($families as $family => $requirements)
                    <tr class="a-table-group" data-filter-group>
                        <th colspan="5" scope="colgroup">{{ $family }} · {{ $text($pack->families()[$family]['name'] ?? '') }}</th>
                    </tr>
                    @foreach ($requirements as $row)
                        @php
                            $c = $row['counts'];
                            $state = $c['unlinked'] > 0 ? 'todo' : ($c['total'] === 0 ? ($c['follows'] ? 'done' : 'none') : ($c['done'] === $c['total'] ? 'done' : 'todo'));
                            $source = implode(', ', $row['sources']);
                        @endphp
                        <tr data-filter-item data-search="{{ $row['code'] }} {{ $row['title'] }} {{ $family }}" data-facet-value="{{ trim($state.($c['fail'] || $c['follows'] ? ' fail' : '').($c['unjustified'] ? ' unjustified' : '').($c['inconclusive'] ? ' inconclusive' : '')) }}" @class(['is-muted' => $c['total'] === 0 && ! $c['follows'] && ! $c['unlinked']])>
                            <th scope="row" class="font-normal!">
                                <a class="a-row-link" data-row-link href="{{ route('versions.evaluate.requirement', [$version, $standard, $row['code']]) }}"><span class="a-code">{{ $row['code'] }}</span></a>
                                <span class="ml-1">{{ $row['title'] }}</span>
                                @if ($row['shared'] !== [])
                                    @php $sharedNames = implode(', ', array_map(fn ($s) => $pack->standardName($s), $row['shared'])); @endphp
                                    <span class="a-shared-badge" title="{{ __('evaluations.shared.badge_help', ['parts' => $sharedNames]) }}"><x-lucide-link aria-hidden="true" /><span class="a-sr-only">{{ __('evaluations.shared.badge') }}: {{ __('evaluations.shared.badge_help', ['parts' => $sharedNames]) }}</span></span>
                                @endif
                            </th>
                            <td class="is-nowrap text-label max-md:hidden">{{ $c['total'] ? $row['targets'] : '—' }}</td>
                            <td>
                                @if ($c['total'] === 0 && $c['unlinked'])
                                    <span class="a-help">{{ __('evaluations.follows.unlinked_title') }}</span>
                                @elseif ($c['total'] === 0 && $c['follows'])
                                    <span class="a-help">{{ __('evaluations.follows.only', ['code' => $source]) }}</span>
                                @elseif ($c['total'] === 0)
                                    <span class="a-help">{{ __('evaluations.nothing') }}</span>
                                @else
                                    <x-ui.progress :value="$c['done']" :max="$c['total']" line />
                                @endif
                            </td>
                            <td class="max-md:hidden">
                                <span class="a-chips">
                                    @if ($c['pass'])<x-verdict verdict="PASS" /><b class="text-label">{{ $c['pass'] }}</b>@endif
                                    @if ($c['fail'])<x-verdict verdict="FAIL" /><b class="text-label">{{ $c['fail'] }}</b>@endif
                                    @if ($c['follows'])<x-follows-badge :code="$source" /><b class="text-label">{{ $c['follows'] }}</b>@endif
                                    @if ($c['unlinked'])<x-follows-badge :code="$source" state="unlinked" short /><b class="text-label">{{ $c['unlinked'] }}</b>@endif
                                    @if ($c['na'])<x-verdict verdict="NOT_APPLICABLE" /><b class="text-label">{{ $c['na'] }}</b>@endif
                                    @if ($c['invalid'])<x-eval-status status="invalid" />@endif
                                    @if ($c['inconclusive'])<x-verdict verdict="INCONCLUSIVE" /><b class="text-label">{{ $c['inconclusive'] }}</b>@endif
                                    @if ($c['unjustified'])<x-eval-status status="unjustified" /><b class="text-label">{{ $c['unjustified'] }}</b>@endif
                                </span>
                            </td>
                            <td class="is-shrink">
                                @if ($state === 'todo' && $access->canEdit($version))
                                    <a href="{{ route('versions.evaluate.requirement', [$version, $standard, $row['code']]) }}" class="a-btn a-btn-outline a-btn-sm">{{ __('evaluations.evaluate') }}<x-lucide-arrow-right aria-hidden="true" /></a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
        <p class="a-help px-4 py-3" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</p>
    </div>
</x-layouts.app>
