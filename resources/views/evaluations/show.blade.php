<x-layouts.app :title="$requirement['code']">
    <x-slot:subnav><x-version-nav :version="$version" active="evaluate" /></x-slot:subnav>
    @php
        $text = fn ($v) => \App\Content\Text::of($v);
        $code = $requirement['code'];
        $evaluation = $selected['evaluation'] ?? null;
        $done = collect($targets)->where('status', 'done')->count();
        // j / k: the entries around the one shown.
        $at = $selected ? collect($targets)->search(fn ($t) => $t['key'] === $selected['key']) : false;
        $nextKey = $at !== false ? ($targets[$at + 1]['key'] ?? null) : null;
        $previousKey = $at !== false && $at > 0 ? $targets[$at - 1]['key'] : null;
    @endphp
    <x-read-only-notice :access="$access" :version="$version" />

    <x-ui.page-header :title="$code" :muted="$tree?->title() ?: $text($requirement['title'] ?? '')">
        <x-slot:eyebrow>
            <a href="{{ route('versions.evaluate', [$version, $standard]) }}">{{ $pack->standardName($standard) }}</a>
            @if ($tree?->clause())<span>· {{ __('evaluations.clause', ['clause' => $tree->clause()]) }}</span>@endif
        </x-slot:eyebrow>
        <x-slot:actions>
            <span class="inline-flex gap-1">
                @if ($previous)
                    <a href="{{ route('versions.evaluate.requirement', [$version, $standard, $previous]) }}" class="a-btn a-btn-outline a-btn-icon" aria-label="{{ __('evaluations.previous', ['code' => $previous]) }}" title="{{ __('evaluations.previous', ['code' => $previous]) }}"><x-lucide-chevron-left aria-hidden="true" /></a>
                @endif
                @if ($next)
                    <a href="{{ route('versions.evaluate.requirement', [$version, $standard, $next]) }}" class="a-btn a-btn-outline" title="{{ __('evaluations.next', ['code' => $next]) }}">{{ $next }}<x-lucide-chevron-right aria-hidden="true" /></a>
                @endif
            </span>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($tree?->scope())
        <div class="a-alert"><x-lucide-repeat aria-hidden="true" /><span class="min-w-0 [overflow-wrap:anywhere]"><b>{{ __('evaluations.scope') }}:</b> {{ $tree->scope() }}</span></div>
    @endif

    @if ($criteria)
        @include('assessment._criteria', ['criteria' => $criteria, 'standardName' => $pack->standardName($standard), 'withCases' => true, 'open' => false])
    @endif

    @if ($targets === [] && $follows !== [])
        {{-- Nothing to walk: every entry follows from another walk (e.g. SCM-1 ends FAIL) or waits for a link. --}}
        @foreach (collect($follows)->groupBy('state') as $state => $group)
        <section class="a-panel a-follows-panel" aria-labelledby="follows-title-{{ $state }}">
            <div class="a-panel-head"><h2 class="a-h3" id="follows-title-{{ $state }}">{{ $state === 'fail' ? __('evaluations.follows.title', ['code' => $group->pluck('requirement')->unique()->implode(', ')]) : __('evaluations.follows.unlinked_title') }}</h2></div>
            @if ($state !== 'fail')<p class="a-help px-4 pb-2">{{ __('evaluations.follows.unlinked_help', ['code' => $group->pluck('requirement')->unique()->implode(', ')]) }}</p>@endif
            <ul class="a-follows-list">
                @foreach ($group as $f)
                    <li>
                        <span class="min-w-0 truncate">@if ($f['item'])<span class="a-code">{{ $f['item']->code }}</span> {{ $f['item']->name }}@else{{ __('evaluations.product') }}@endif</span>
                        <x-follows-badge :code="$f['requirement']" :state="$f['state']" />
                        <a href="{{ $f['href'] }}" class="a-btn a-btn-ghost a-btn-sm">@if ($f['state'] === 'fail'){{ __('evaluations.follows.open_source', ['code' => $f['requirement']]) }}@else{{ __('workflow.steps.inventory') }}@endif<x-lucide-arrow-right aria-hidden="true" /></a>
                    </li>
                @endforeach
            </ul>
        </section>
        @endforeach
    @elseif ($targets === [])
        <x-ui.empty icon="boxes" :title="__('evaluations.nothing')" :text="__('evaluations.nothing_help', ['list' => $listName])">
            <a href="{{ route('versions.inventory', [$version, $requirement['list'] ?? array_key_first($pack->lists())]) }}" class="a-btn a-btn-primary"><x-lucide-boxes aria-hidden="true" />{{ __('workflow.steps.inventory') }}</a>
        </x-ui.empty>
    @else
        <div class="a-eval">
            {{-- What this requirement is walked for (assets, interfaces, the product...): one at a time. --}}
            <nav class="a-eval-objects" aria-label="{{ $heading }}">
                <p class="a-micro flex items-center justify-between gap-2 px-2 pb-1"><span class="min-w-0 truncate" title="{{ $heading }}">{{ $heading }}</span><span>{{ $done }}/{{ count($targets) }}</span></p>
                <ul>
                    @foreach ($targets as $target)
                        @php $e = $target['evaluation']; @endphp
                        <li>
                            <a href="{{ \App\Support\Links::walk($version, $standard, $code, $target['key']) }}" class="a-eval-object" @if ($selected && $selected['key'] === $target['key']) aria-current="true" @endif
                               @if ($target['key'] === $nextKey) data-shortcut="j" data-shortcut-label="{{ __('evaluations.keys.next_object') }}" @elseif ($target['key'] === $previousKey) data-shortcut="k" data-shortcut-label="{{ __('evaluations.keys.previous_object') }}" @endif>
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold">@if ($target['item'])<span class="a-code">{{ $target['item']->code }}</span> {{ $target['item']->name }}@else{{ __('evaluations.product') }}@endif</span>
                                    @if ($e?->nodeReference())<span class="block truncate text-micro text-ink-subtle">{{ $e->nodeReference() }}@if ($e->shared_group && ! $e->shared_untied)<span class="a-eval-object-shared" title="{{ __('evaluations.shared.badge') }}"><x-lucide-link aria-hidden="true" /><span class="a-sr-only">{{ __('evaluations.shared.badge') }}</span></span>@endif</span>@endif
                                </span>
                                @if ($target['status'] === 'done')
                                    <x-verdict :verdict="$e?->verdict" :icon="false" />
                                @elseif ($target['status'] === 'inconclusive')
                                    <span class="a-badge a-badge-warning a-badge-inconclusive is-icon" title="{{ __('evaluations.status.inconclusive') }}"><x-lucide-circle-help aria-hidden="true" /><span class="a-sr-only">{{ __('evaluations.status.inconclusive') }}</span></span>
                                @elseif ($target['status'] === 'unjustified')
                                    <span class="a-badge a-badge-warning a-badge-unjustified is-icon" title="{{ __('evaluations.status.unjustified') }}"><x-lucide-scale aria-hidden="true" /><span class="a-sr-only">{{ __('evaluations.status.unjustified') }}</span></span>
                                @elseif ($target['status'] === 'missing')
                                    <span class="a-dot text-line-strong" title="{{ __('evaluations.status.missing') }}"></span>
                                @else
                                    <x-eval-status :status="$target['status']" />
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
                {{-- Not walked here: they follow from another walk (FAIL), or wait for a link in the inventory. --}}
                @foreach (collect($follows)->groupBy('state') as $state => $group)
                    <p class="a-micro a-eval-follows-head">{{ $state === 'fail' ? __('evaluations.follows.title', ['code' => $group->pluck('requirement')->unique()->implode(', ')]) : __('evaluations.follows.unlinked_title') }}</p>
                    <ul>
                        @foreach ($group as $f)
                            <li>
                                <a href="{{ $f['href'] }}" class="a-eval-object is-follows" title="{{ $f['state'] === 'fail' ? __('evaluations.follows.help', ['code' => $f['requirement']]) : __('evaluations.follows.unlinked_help', ['code' => $f['requirement']]) }}">
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold">@if ($f['item'])<span class="a-code">{{ $f['item']->code }}</span> {{ $f['item']->name }}@else{{ __('evaluations.product') }}@endif</span>
                                        <span class="block truncate text-micro text-ink-subtle">DT.{{ $f['requirement'] }}</span>
                                    </span>
                                    <x-follows-badge :code="$f['requirement']" :state="$f['state']" short />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </nav>

            {{-- The walk and the justification of the selected entry. --}}
            <section class="a-panel min-w-0" aria-labelledby="object-title">
                <header class="a-panel-head">
                    <h2 class="a-h2 min-w-0 truncate" id="object-title">{{ $selected['item']?->label() ?? __('evaluations.product') }}</h2>
                    <span class="flex items-center gap-2">
                        @if ($evaluation?->nodeReference())<span class="a-chip a-chip-sm a-chip-code">{{ $evaluation->nodeReference() }}</span>@endif
                        @if ($selected['status'] === 'done' || ! $evaluation)
                            <x-verdict :verdict="$evaluation?->verdict" />
                        @else
                            <x-eval-status :status="$selected['status']" />
                        @endif
                    </span>
                </header>
                @if ($inner !== [])
                    {{-- The inner "For each" loop of this walk (e.g. the communicated assets the mechanism secures). --}}
                    <div class="a-eval-inner" title="{{ __('evaluations.inner.help') }}">
                        <span class="a-micro">{{ __('evaluations.inner.title') }}</span>
                        <span class="a-chips">
                            @foreach ($inner as $innerItem)
                                <a href="{{ \App\Support\Links::item($version, $innerItem) }}" class="a-chip a-chip-sm" title="{{ $innerItem->name }}"><span class="a-code">{{ $innerItem->code }}</span> {{ $innerItem->name }}</a>
                            @endforeach
                        </span>
                    </div>
                @endif
                @if ($evaluation?->verdict === \App\Enums\Verdict::Inconclusive)
                    <div class="a-alert a-alert-warning a-inconclusive-alert"><x-lucide-circle-help aria-hidden="true" /><span><b>{{ __('verdicts.INCONCLUSIVE') }}.</b> {{ __('assessment.inconclusive_note') }}</span></div>
                @endif
                @include('evaluations._tree-form', ['targetKey' => $selected['key']])
                @if ($canEdit && $evaluation && $shared)
                    <form method="POST" action="{{ route($shared['untied'] ? 'versions.evaluate.retie' : 'versions.evaluate.untie', [$version, $standard, $code]) }}" id="{{ $shared['untied'] ? 'shared-retie' : 'shared-untie' }}" hidden>
                        @csrf
                        <input type="hidden" name="target" value="{{ $selected['key'] }}">
                    </form>
                @endif
                @if ($evaluation)
                    <div class="border-t border-line px-4 py-3 empty:hidden">@includeIf('ai._evaluation-tools', ['version' => $version, 'evaluation' => $evaluation, 'tree' => $tree, 'canEdit' => $canEdit])</div>
                @endif
            </section>

        </div>
    @endif
</x-layouts.app>
