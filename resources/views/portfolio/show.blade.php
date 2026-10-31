{{--
    An organisation's portfolio (PortfolioController, its admins, paid plans): figures, every product
    and version with where it stands (App\Portfolio\Portfolio), then who does what over 7, 30 or 90
    days (App\Admin\WorkActivity limited to the organisation; console partials chart and areas).
    On the free plan: what the portfolio shows, and the way to a paid plan.
--}}
@php
    $tz = auth()->user()->timezone ?: config('app.timezone');
    $when = fn (string $at) => \Carbon\CarbonImmutable::parse($at)->setTimezone($tz)->locale(app()->getLocale());
    $changeBadge = ['to_decide' => 'a-badge-warning', 'outdated' => 'a-badge-warning', 'significant' => 'a-badge-danger', 'not_significant' => 'a-badge-success', 'no_signs' => 'a-badge-neutral'];
@endphp
<x-layouts.app :title="__('portfolio.title').' · '.$organisation->name" :wide="true">
    <x-ui.page-header :title="__('portfolio.title')" :subtitle="__('portfolio.lead')">
        <x-slot:eyebrow>{{ $organisation->name }}</x-slot:eyebrow>
        <x-slot:actions>
            @if ($organisations->count() > 1)
                <details class="a-menu">
                    <summary class="a-btn a-btn-ghost"><x-lucide-building-2 aria-hidden="true" />{{ __('orgteam.switch') }}<x-lucide-chevron-down class="size-3.5" aria-hidden="true" /></summary>
                    <div class="a-menu-panel">
                        @foreach ($organisations as $other)
                            <a class="a-menu-item" href="{{ route('organisations.portfolio', $other) }}" @if ($other->is($organisation)) aria-current="page" @endif>
                                <x-lucide-building-2 aria-hidden="true" /><span class="min-w-0 truncate">{{ $other->name }}</span>
                            </a>
                        @endforeach
                    </div>
                </details>
            @endif
            @if ($available)
                <nav class="a-seg" aria-label="{{ __('portfolio.period') }}">
                    @foreach ($periods as $period)
                        <a href="{{ $period === 30 ? route('organisations.portfolio', $organisation) : route('organisations.portfolio.period', [$organisation, $period]) }}" class="a-seg-btn"
                            @if ($period === $days) aria-current="page" @endif>{{ __('portfolio.days', ['days' => $period]) }}</a>
                    @endforeach
                </nav>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @include('organisations._tabs', ['organisation' => $organisation, 'active' => 'portfolio', 'isAdmin' => true])

    @if (! $available)
        <x-ui.empty icon="layout-dashboard" :title="__('portfolio.paid_title')" :text="__('portfolio.paid_text')">
            <a href="{{ $upgradeUrl }}" class="a-btn a-btn-primary"><x-lucide-circle-arrow-up aria-hidden="true" />{{ __('plans.upgrade') }}</a>
        </x-ui.empty>
    @else
        <section aria-labelledby="figures-heading">
            <h2 id="figures-heading" class="a-sr-only">{{ __('portfolio.figures') }}</h2>
            <div class="a-stats grid-cols-2! sm:grid-cols-3! xl:grid-cols-6!">
                @foreach (['products', 'draft', 'in_review', 'approved', 'to_decide', 'rechecks'] as $key)
                    <x-ui.stat :label="__('portfolio.totals.'.$key)" :value="\Illuminate\Support\Number::format($totals[$key])"
                        :tone="in_array($key, ['to_decide', 'rechecks'], true) && $totals[$key] > 0 ? 'bad' : null" />
                @endforeach
            </div>
        </section>

        <section class="a-section" aria-labelledby="products-heading">
            <div class="a-section-head"><h2 id="products-heading" class="a-h2">{{ __('portfolio.products') }}</h2></div>
            @if ($products === [])
                <x-ui.empty icon="package" :title="__('portfolio.no_products')" />
            @else
                <div class="a-table-wrap">
                    <table class="a-table a-table-compact a-work a-folio">
                        <caption class="a-sr-only">{{ __('portfolio.products') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('portfolio.col.product') }}</th>
                                <th scope="col">{{ __('portfolio.col.version') }}</th>
                                <th scope="col" class="w-[16%] max-sm:hidden">{{ __('portfolio.col.progress') }}</th>
                                <th scope="col" class="max-md:hidden">{{ __('portfolio.col.change') }}</th>
                                <th scope="col" class="is-num max-md:hidden">{{ __('portfolio.col.rechecks') }}</th>
                                <th scope="col" class="max-md:hidden">{{ __('portfolio.col.last') }}</th>
                            </tr>
                        </thead>
                        @foreach ($products as ['product' => $product, 'versions' => $versions])
                            <tbody>
                                @foreach ($versions as $row)
                                    @php
                                        $version = $row['version'];
                                        $state = $row['change'] ? ($row['change']['state'] === 'decided' ? $row['change']['decision'] : $row['change']['state']) : null;
                                    @endphp
                                    <tr @class(['a-folio-older' => ! $row['latest']])>
                                        @if ($loop->first)
                                            <th scope="rowgroup" rowspan="{{ count($versions) }}" class="a-work-name">
                                                <a href="{{ $product->homeUrl() }}" class="a-link">{{ $product->name }}</a>
                                                <span class="a-work-sub">{{ collect([$product->reference, $product->owner?->name, trans_choice('portfolio.team', $product->members_count, ['count' => $product->members_count])])->filter()->implode(' · ') }}</span>
                                            </th>
                                        @endif
                                        <td class="is-nowrap">
                                            <a href="{{ route('versions.show', $version) }}" class="a-link">{{ $version->label }}</a>
                                            <x-version-status :status="$version->status" class="ml-1" />
                                            @if ($version->approved_at)
                                                <span class="a-work-sub">{{ __('portfolio.approved_on', ['date' => $version->approved_at->locale(app()->getLocale())->isoFormat('D MMM YYYY')]) }}</span>
                                            @endif
                                            {{-- Phone: the significant change under the version (its column is hidden). --}}
                                            @if ($state)
                                                <span class="mt-1 block md:hidden"><a href="{{ route('versions.changes', $version) }}" class="a-badge {{ $changeBadge[$state] }}">{{ __('changes.state_short.'.$state) }}</a></span>
                                            @endif
                                        </td>
                                        <td class="max-sm:hidden">
                                            @if ($row['summary'])
                                                <x-ui.progress :value="$row['summary']->doneSteps()" :max="$row['summary']->totalSteps()" line :label="__('ui.steps_done', ['done' => $row['summary']->doneSteps(), 'total' => $row['summary']->totalSteps()])" />
                                            @else
                                                <span class="a-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="max-md:hidden">
                                            @if ($state)
                                                <a href="{{ route('versions.changes', $version) }}" class="a-badge {{ $changeBadge[$state] }}">{{ __('changes.state_short.'.$state) }}</a>
                                            @else
                                                <span class="a-muted" title="{{ __('portfolio.first_version') }}">—</span>
                                            @endif
                                        </td>
                                        <td @class(['is-num max-md:hidden', 'font-bold a-folio-bad' => $row['rechecks'] > 0])>{{ $row['rechecks'] ?: '—' }}</td>
                                        <td class="a-work-last max-md:hidden">
                                            @if ($row['last'])
                                                <time datetime="{{ $when($row['last']['at'])->toIso8601String() }}" title="{{ $when($row['last']['at'])->isoFormat('D MMMM YYYY, HH:mm') }}">{{ $when($row['last']['at'])->diffForHumans() }}</time>
                                                @if ($row['last']['user'])
                                                    <span class="a-work-sub">{{ $row['last']['user']->name }}</span>
                                                @endif
                                            @else
                                                <span class="a-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
            @endif
        </section>

        {{-- Who does what (App\Admin\WorkActivity, this organisation): accounts only, sandbox guests left out. --}}
        <section class="a-section" aria-labelledby="work-heading">
            <div class="a-section-head">
                <div>
                    <h2 id="work-heading" class="a-h2">{{ __('portfolio.work_title', ['days' => $days]) }}</h2>
                    <p class="a-help mt-0.5">{{ __('portfolio.work_lead') }}</p>
                </div>
            </div>

            {{-- The actions are the chart's total: not repeated in a tile. --}}
            <ul class="a-stats grid-cols-2!">
                @foreach (['people', 'products'] as $key)
                    <li class="a-stat">
                        <span class="a-stat-label">{{ __('portfolio.work_totals.'.$key) }}</span>
                        <span class="a-stat-value">{{ \Illuminate\Support\Number::format($work['totals'][$key]) }}</span>
                    </li>
                @endforeach
            </ul>

            @include('admin.partials.chart', ['chart' => $work['chart'], 'title' => __('admin.work.chart'), 'help' => __('admin.work.chart_help')])

            <div class="a-section-head mt-6"><h3 class="a-h3">{{ __('portfolio.people') }}</h3></div>
            @if ($work['people'] === [])
                <p class="a-muted">{{ __('portfolio.no_work', ['days' => $days]) }}</p>
            @else
                <div class="a-table-wrap">
                    <table class="a-table a-table-compact a-work">
                        <caption class="a-sr-only">{{ __('portfolio.people') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('portfolio.col.person') }}</th>
                                <th scope="col" class="is-num">{{ __('admin.work.actions') }}</th>
                                <th scope="col" class="is-num max-md:hidden">{{ __('admin.work.days') }}</th>
                                <th scope="col" class="is-num max-md:hidden">{{ __('admin.work.products') }}</th>
                                <th scope="col" class="max-md:hidden">{{ __('admin.work.areas_heading') }}</th>
                                <th scope="col" class="max-md:hidden">{{ __('admin.work.per_day') }}</th>
                                <th scope="col">{{ __('admin.work.last') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($work['people'] as $row)
                                <tr>
                                    <th scope="row" class="a-work-name">
                                        <a href="{{ route('organisations.team.person', [$organisation, $row['user']->id]) }}" class="a-link">{{ $row['user']->name }}</a>
                                        <span class="a-work-sub">{{ $row['user']->email }}</span>
                                    </th>
                                    <td class="is-num font-bold">{{ \Illuminate\Support\Number::format($row['actions']) }}</td>
                                    <td class="is-num max-md:hidden">{{ \Illuminate\Support\Number::format($row['days']) }}</td>
                                    <td class="is-num max-md:hidden">{{ \Illuminate\Support\Number::format($row['products']) }}</td>
                                    <td class="max-md:hidden">@include('admin.partials.areas', ['areas' => $row['areas']])</td>
                                    <td class="max-md:hidden"><x-ui.sparkline :values="$row['daily']" :label="__('admin.work.spark', ['count' => \Illuminate\Support\Number::format($row['actions']), 'days' => $days])" /></td>
                                    <td class="a-work-last">
                                        <time datetime="{{ $when($row['last'])->toIso8601String() }}" title="{{ $when($row['last'])->isoFormat('D MMMM YYYY, HH:mm') }}">{{ $when($row['last'])->diffForHumans() }}</time>
                                        @if ($row['latest'])
                                            <span class="a-work-sub">{!! $row['latest']->html !!}@if ($row['latest']->productName) · {{ $row['latest']->productName }}@endif</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="a-section-head mt-6">
                <h3 class="a-h3">{{ __('admin.work.latest') }}</h3>
                <a href="{{ route('organisations.history', $organisation) }}" class="a-btn a-btn-ghost a-btn-sm"><x-lucide-history aria-hidden="true" />{{ __('portfolio.all_history') }}</a>
            </div>
            @if ($work['latest'] === [])
                <p class="a-muted">{{ __('portfolio.no_work', ['days' => $days]) }}</p>
            @else
                <div class="a-table-wrap">
                    <table class="a-table a-table-compact a-hist a-work-feed">
                        <caption class="a-sr-only">{{ __('admin.work.latest') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.work.when') }}</th>
                                <th scope="col">{{ __('portfolio.col.person') }}</th>
                                <th scope="col">{{ __('admin.work.what') }}</th>
                                <th scope="col">{{ __('admin.work.product') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($work['latest'] as ['entry' => $entry, 'user' => $person])
                                <tr class="a-hist-row">
                                    <td class="a-hist-time"><time datetime="{{ $entry->at->toIso8601String() }}" title="{{ $entry->at->isoFormat('D MMMM YYYY, HH:mm') }}">{{ $entry->at->diffForHumans() }}</time></td>
                                    <td class="a-work-name">{{ $person?->name ?? $entry->actor['name'] }}</td>
                                    <td class="a-hist-what">
                                        <div class="a-hist-line">
                                            <span class="a-hist-kind" title="{{ __('history.kinds.'.$entry->kind) }}">
                                                <x-dynamic-component :component="'lucide-'.$entry->icon()" aria-hidden="true" />
                                                <span class="a-sr-only">{{ __('history.kinds.'.$entry->kind) }} · </span>
                                            </span>
                                            <span class="a-hist-text">{!! $entry->html !!}</span>
                                        </div>
                                    </td>
                                    <td class="a-hist-where">
                                        {{ $entry->productName }}
                                        @if ($entry->versionLabel)<span class="a-badge a-badge-neutral">{{ $entry->versionLabel }}</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</x-layouts.app>
