<x-layouts.app :title="__('admin.dashboard.title')" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="__('admin.dashboard.title')" :subtitle="__('admin.dashboard.lead')">
        <x-slot:eyebrow>{{ __('admin.title') }}</x-slot:eyebrow>
        <x-slot:actions>
            <nav class="a-seg" aria-label="{{ __('admin.usage.period') }}">
                @foreach ($periods as $period)
                    <a href="{{ $period === 30 ? route('admin.dashboard') : route('admin.dashboard.period', $period) }}" class="a-seg-btn"
                        @if ($period === $days) aria-current="page" @endif>{{ __('admin.usage.days', ['days' => $period]) }}</a>
                @endforeach
            </nav>
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $tiles = [
            [__('admin.kpi.users'), $kpis['users'], __('admin.kpi.verified', ['count' => \Illuminate\Support\Number::format($kpis['verified'])])],
            [__('admin.kpi.new_7'), $kpis['new_7'], __('admin.kpi.new_30', ['count' => \Illuminate\Support\Number::format($kpis['new_30'])])],
            [__('admin.kpi.active_1'), $kpis['active_1'], __('admin.kpi.active_7_30', ['week' => \Illuminate\Support\Number::format($kpis['active_7']), 'month' => \Illuminate\Support\Number::format($kpis['active_30'])])],
            [__('admin.kpi.organisations'), $kpis['organisations'], null],
            [__('admin.kpi.products'), $kpis['products'], null],
            [__('admin.kpi.evaluations'), $kpis['evaluations'], null],
            [__('admin.kpi.reports'), $kpis['reports'], null],
            [__('admin.kpi.ai_today'), $kpis['ai_today'], __('admin.kpi.ai_30', ['count' => \Illuminate\Support\Number::format($kpis['ai_30'])])],
        ];
        $tz = auth()->user()->timezone ?: config('app.timezone');
        $when = fn (string $at) => \Carbon\CarbonImmutable::parse($at)->setTimezone($tz);
    @endphp

    <section aria-labelledby="kpi-heading">
        <h2 id="kpi-heading" class="a-sr-only">{{ __('admin.dashboard.figures') }}</h2>
        <ul class="a-stats grid-cols-2! sm:grid-cols-4!">
            @foreach ($tiles as [$label, $value, $sub])
                <li class="a-stat">
                    <span class="a-stat-label">{{ $label }}</span>
                    <span class="a-stat-value">{{ \Illuminate\Support\Number::format($value) }}</span>
                    @if ($sub)
                        <span class="a-help">{{ $sub }}</span>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="a-panel flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
            <h3 class="a-micro">{{ __('admin.kpi.versions') }}</h3>
            <dl class="flex flex-wrap gap-x-6 gap-y-2">
                @foreach ($kpis['versions'] as $status => $count)
                    <div class="flex items-center gap-2">
                        <dt><x-version-status :status="\App\Enums\VersionStatus::from($status)" /></dt>
                        <dd class="font-bold tabular-nums">{{ \Illuminate\Support\Number::format($count) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- Who does what (App\Admin\WorkActivity): accounts only, sandbox guests and the console's own work left out. --}}
    <section class="a-section" aria-labelledby="work-heading">
        <div class="a-section-head">
            <div>
                <h2 id="work-heading" class="a-h2">{{ __('admin.work.title', ['days' => $days]) }}</h2>
                <p class="a-help mt-0.5">{{ __('admin.work.lead') }}</p>
            </div>
        </div>

        <ul class="a-stats grid-cols-2! sm:grid-cols-4!">
            @foreach (['actions', 'people', 'products', 'organisations'] as $key)
                <li class="a-stat">
                    <span class="a-stat-label">{{ __('admin.work.totals.'.$key) }}</span>
                    <span class="a-stat-value">{{ \Illuminate\Support\Number::format($work['totals'][$key]) }}</span>
                </li>
            @endforeach
        </ul>

        @include('admin.partials.chart', ['chart' => $work['chart'], 'title' => __('admin.work.chart'), 'help' => __('admin.work.chart_help')])

        <div class="a-section-head mt-6"><h3 class="a-h3">{{ __('admin.work.people') }}</h3></div>
        @if ($work['people'] === [])
            <p class="a-muted">{{ __('admin.no_data') }}</p>
        @else
            <div class="a-table-wrap">
                <table class="a-table a-table-compact a-work">
                    <caption class="a-sr-only">{{ __('admin.work.people') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.users.name') }}</th>
                            <th scope="col">{{ __('admin.users.organisation') }}</th>
                            <th scope="col" class="is-num">{{ __('admin.work.actions') }}</th>
                            <th scope="col" class="is-num">{{ __('admin.work.days') }}</th>
                            <th scope="col" class="is-num">{{ __('admin.work.products') }}</th>
                            <th scope="col">{{ __('admin.work.areas_heading') }}</th>
                            <th scope="col">{{ __('admin.work.per_day') }}</th>
                            <th scope="col">{{ __('admin.work.last') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($work['people'] as $row)
                            <tr>
                                <th scope="row" class="a-work-name">
                                    <a href="{{ route('admin.users.show', $row['user']) }}" class="a-link">{{ $row['user']->name }}</a>
                                    <span class="a-work-sub">{{ $row['user']->email }}</span>
                                </th>
                                <td>
                                    @foreach ($row['user']->organisations as $organisation)
                                        <a href="{{ route('admin.organisations.show', $organisation) }}" class="a-link block">{{ $organisation->name }}</a>
                                    @endforeach
                                </td>
                                <td class="is-num font-bold">{{ \Illuminate\Support\Number::format($row['actions']) }}</td>
                                <td class="is-num">{{ \Illuminate\Support\Number::format($row['days']) }}</td>
                                <td class="is-num">{{ \Illuminate\Support\Number::format($row['products']) }}</td>
                                <td>@include('admin.partials.areas', ['areas' => $row['areas']])</td>
                                <td><x-ui.sparkline :values="$row['daily']" :label="__('admin.work.spark', ['count' => \Illuminate\Support\Number::format($row['actions']), 'days' => $days])" /></td>
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

        <div class="a-section-head mt-6"><h3 class="a-h3">{{ __('admin.work.products_heading') }}</h3></div>
        @if ($work['products'] === [])
            <p class="a-muted">{{ __('admin.no_data') }}</p>
        @else
            <div class="a-table-wrap">
                <table class="a-table a-table-compact a-work">
                    <caption class="a-sr-only">{{ __('admin.work.products_heading') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.work.product') }}</th>
                            <th scope="col">{{ __('admin.work.version') }}</th>
                            <th scope="col" class="is-num">{{ __('admin.work.actions') }}</th>
                            <th scope="col">{{ __('admin.work.who') }}</th>
                            <th scope="col">{{ __('admin.work.areas_heading') }}</th>
                            <th scope="col">{{ __('admin.work.per_day') }}</th>
                            <th scope="col">{{ __('admin.work.last') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($work['products'] as $row)
                            @php($version = $row['product']->versions->first())
                            <tr>
                                <th scope="row" class="a-work-name">
                                    {{ $row['product']->name }}
                                    @if ($row['product']->trashed())
                                        <span class="a-badge a-badge-neutral">{{ __('admin.work.deleted') }}</span>
                                    @endif
                                    @if ($row['product']->organisation)
                                        <a href="{{ route('admin.organisations.show', $row['product']->organisation) }}" class="a-link a-work-sub">{{ $row['product']->organisation->name }}</a>
                                    @endif
                                </th>
                                <td class="whitespace-nowrap">
                                    @if ($version)
                                        {{ $version->label }} <x-version-status :status="$version->status" />
                                    @endif
                                </td>
                                <td class="is-num font-bold">{{ \Illuminate\Support\Number::format($row['actions']) }}</td>
                                <td>
                                    <ul class="a-work-who">
                                        @foreach (array_slice($row['who'], 0, 3) as $person)
                                            <li><a href="{{ route('admin.users.show', $person['user']) }}" class="a-link">{{ $person['user']->name }}</a> <span class="a-work-count">{{ \Illuminate\Support\Number::format($person['actions']) }}</span></li>
                                        @endforeach
                                        @if (count($row['who']) > 3)
                                            <li class="a-work-sub">{{ __('admin.work.more_people', ['count' => count($row['who']) - 3]) }}</li>
                                        @endif
                                    </ul>
                                </td>
                                <td>@include('admin.partials.areas', ['areas' => $row['areas']])</td>
                                <td><x-ui.sparkline :values="$row['daily']" :label="__('admin.work.spark', ['count' => \Illuminate\Support\Number::format($row['actions']), 'days' => $days])" /></td>
                                <td class="a-work-last">
                                    <time datetime="{{ $when($row['last'])->toIso8601String() }}" title="{{ $when($row['last'])->isoFormat('D MMMM YYYY, HH:mm') }}">{{ $when($row['last'])->diffForHumans() }}</time>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="a-section-head mt-6">
            <h3 class="a-h3">{{ __('admin.work.latest') }}</h3>
            <a href="{{ route('admin.activity.index') }}" class="a-btn a-btn-ghost a-btn-sm"><x-lucide-history aria-hidden="true" />{{ __('admin.work.all_activity') }}</a>
        </div>
        @if ($work['latest'] === [])
            <p class="a-muted">{{ __('admin.no_data') }}</p>
        @else
            <div class="a-table-wrap">
                <table class="a-table a-table-compact a-hist a-work-feed">
                    <caption class="a-sr-only">{{ __('admin.work.latest') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.work.when') }}</th>
                            <th scope="col">{{ __('admin.users.name') }}</th>
                            <th scope="col">{{ __('admin.work.what') }}</th>
                            <th scope="col">{{ __('admin.work.product') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($work['latest'] as ['entry' => $entry, 'user' => $person, 'organisation' => $organisation])
                            <tr class="a-hist-row">
                                <td class="a-hist-time"><time datetime="{{ $entry->at->toIso8601String() }}" title="{{ $entry->at->isoFormat('D MMMM YYYY, HH:mm') }}">{{ $entry->at->diffForHumans() }}</time></td>
                                <td class="a-work-name">
                                    @if ($person)
                                        <a href="{{ route('admin.users.show', $person) }}" class="a-link">{{ $person->name }}</a>
                                    @else
                                        {{ $entry->actor['name'] }}
                                    @endif
                                    @if ($organisation)
                                        <span class="a-work-sub">{{ $organisation }}</span>
                                    @endif
                                </td>
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

    @include('guest._admin-kpis')

    <section class="a-section" aria-labelledby="charts-heading">
        <div class="a-section-head"><h2 id="charts-heading" class="a-h2">{{ __('admin.dashboard.trends', ['days' => $days]) }}</h2></div>
        <div class="grid gap-4 xl:grid-cols-2">
            @include('admin.partials.chart', ['chart' => $charts['signups'], 'title' => __('admin.charts.signups'), 'help' => __('admin.charts.signups_help')])
            @include('admin.partials.chart', ['chart' => $charts['ai'], 'title' => __('admin.charts.ai'), 'help' => __('admin.charts.ai_help')])
        </div>
    </section>
</x-layouts.app>
