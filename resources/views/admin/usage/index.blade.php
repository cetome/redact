<x-layouts.app :title="__('admin.usage.title')" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="__('admin.usage.title')" :subtitle="__('admin.usage.lead', ['days' => $days])">
        <x-slot:eyebrow>{{ __('admin.title') }}</x-slot:eyebrow>
        <x-slot:actions>
            <nav class="a-seg" aria-label="{{ __('admin.usage.period') }}">
                @foreach ($periods as $period)
                    <a href="{{ route('admin.usage.period', $period) }}" class="a-seg-btn"
                        @if ($period === $days) aria-current="page" @endif>{{ __('admin.usage.days', ['days' => $period]) }}</a>
                @endforeach
            </nav>
        </x-slot:actions>
    </x-ui.page-header>

    <ul class="a-stats grid-cols-2! sm:grid-cols-4!">
        @foreach ([
            [__('admin.usage.requests'), \Illuminate\Support\Number::format($totals['requests']), null],
            [__('admin.usage.users'), \Illuminate\Support\Number::format($totals['users']), null],
            [__('admin.usage.errors'), \Illuminate\Support\Number::format($totals['errors']), $totals['errors'] > 0 ? 'bad' : null],
            [__('admin.usage.error_rate'), \Illuminate\Support\Number::format($totals['error_rate'], 2).' %', $totals['errors'] > 0 ? 'bad' : null],
        ] as [$label, $value, $tone])
            <li @class(['a-stat', 'a-stat-'.$tone => $tone])>
                <span class="a-stat-label">{{ $label }}</span>
                <span class="a-stat-value">{{ $value }}</span>
            </li>
        @endforeach
    </ul>

    <div class="grid gap-4 xl:grid-cols-2">
        @include('admin.partials.chart', ['chart' => $charts['views'], 'title' => __('admin.charts.views'), 'help' => __('admin.charts.views_help')])
        @include('admin.partials.chart', ['chart' => $charts['users'], 'title' => __('admin.charts.unique_users'), 'help' => __('admin.charts.unique_users_help')])
    </div>

    <div class="a-section grid gap-4 xl:grid-cols-2">
        @include('guest._admin-audience')

        {{-- Funnel --}}
        <section class="a-panel" aria-labelledby="funnel-heading">
            <div class="a-panel-head">
                <div>
                    <h2 id="funnel-heading" class="a-h3">{{ __('admin.usage.funnel') }}</h2>
                    <p class="a-help mt-0.5">{{ __('admin.usage.funnel_help', ['days' => $days]) }}</p>
                </div>
            </div>
            @php($base = max(1, $funnel['signed_up']))
            <table class="a-table a-table-compact">
                <caption class="a-sr-only">{{ __('admin.usage.funnel') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.usage.step') }}</th>
                        <th scope="col" class="is-num">{{ __('admin.usage.accounts') }}</th>
                        <th scope="col" class="is-num">{{ __('admin.usage.of_signups') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($funnel as $step => $count)
                        <tr>
                            <th scope="row" class="font-normal!">{{ __('admin.usage.steps.'.$step) }}</th>
                            <td class="is-num">{{ \Illuminate\Support\Number::format($count) }}</td>
                            <td class="is-num">{{ $funnel['signed_up'] > 0 ? \Illuminate\Support\Number::format($count / $base * 100, 1).' %' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    </div>

    {{-- Routes --}}
    <section class="a-section" aria-labelledby="routes-heading">
        <div class="a-section-head"><h2 id="routes-heading" class="a-h2">{{ __('admin.usage.routes') }}</h2></div>
        <div class="a-table-wrap a-table-scroll">
            <table class="a-table a-table-compact">
                <caption class="a-sr-only">{{ __('admin.usage.routes') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.usage.route') }}</th>
                        <th scope="col" class="is-num">{{ __('admin.usage.hits') }}</th>
                        <th scope="col" class="is-num">{{ __('admin.usage.route_users') }}</th>
                        <th scope="col" class="is-num">{{ __('admin.usage.avg_ms') }}</th>
                        <th scope="col" class="is-num">{{ __('admin.usage.p95_ms') }}</th>
                        <th scope="col" class="is-num">{{ __('admin.usage.route_errors') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($routes as $row)
                        <tr>
                            <td class="a-mono">{{ $row['route'] }}</td>
                            <td class="is-num">{{ \Illuminate\Support\Number::format($row['hits']) }}</td>
                            <td class="is-num">{{ \Illuminate\Support\Number::format($row['users']) }}</td>
                            <td class="is-num">{{ \Illuminate\Support\Number::format($row['avg_ms']) }}</td>
                            <td class="is-num">{{ \Illuminate\Support\Number::format($row['p95_ms']) }}</td>
                            <td class="is-num">
                                {{ \Illuminate\Support\Number::format($row['errors']) }}
                                @if ($row['errors'] > 0)
                                    <span class="a-badge a-badge-danger ml-1">{{ \Illuminate\Support\Number::format($row['error_rate'], 2) }} %</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="a-muted">{{ __('admin.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="a-section grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {{-- Unique users per week --}}
        <section class="a-panel" aria-labelledby="weeks-heading">
            <div class="a-panel-head"><h2 id="weeks-heading" class="a-h3">{{ __('admin.usage.weekly_users') }}</h2></div>
            @include('admin.usage.split', ['rows' => $weeks, 'labelHeading' => __('admin.usage.week'), 'valueHeading' => __('admin.usage.users'), 'percent' => false])
        </section>

        {{-- Locales --}}
        <section class="a-panel" aria-labelledby="locales-heading">
            <div class="a-panel-head"><h2 id="locales-heading" class="a-h3">{{ __('admin.usage.locales') }}</h2></div>
            @include('admin.usage.split', [
                'rows' => collect($locales)->mapWithKeys(fn ($n, $locale) => [config('redact.locales.'.$locale, $locale ?: '—') => $n])->all(),
                'labelHeading' => __('admin.usage.locale'), 'valueHeading' => __('admin.usage.requests'), 'percent' => true,
            ])
        </section>

        {{-- Sign-in methods --}}
        <section class="a-panel" aria-labelledby="signins-heading">
            <div class="a-panel-head"><h2 id="signins-heading" class="a-h3">{{ __('admin.usage.sign_ins') }}</h2></div>
            @include('admin.usage.split', [
                'rows' => collect($signIns)->mapWithKeys(fn ($n, $method) => [\App\Admin\Label::of('methods', (string) $method) => $n])->all(),
                'labelHeading' => __('admin.usage.method'), 'valueHeading' => __('admin.usage.sign_in_count'), 'percent' => true,
            ])
        </section>

        {{-- Free template downloads (/templates) --}}
        <section class="a-panel" aria-labelledby="templates-heading">
            <div class="a-panel-head">
                <div>
                    <h2 id="templates-heading" class="a-h3">{{ __('templates.admin.title') }}</h2>
                    <p class="a-help mt-0.5">{{ __('templates.admin.help') }}</p>
                </div>
            </div>
            @include('admin.usage.split', [
                'rows' => collect($templates)->mapWithKeys(fn ($n, $file) => [
                    $file === 'all' ? __('templates.admin.all') : (\Illuminate\Support\Facades\Lang::has('templates.files.'.$file.'.name') ? __('templates.files.'.$file.'.name') : $file) => $n,
                ])->all(),
                'labelHeading' => __('templates.admin.file'), 'valueHeading' => __('templates.admin.downloads'), 'percent' => true,
            ])
        </section>
    </div>

    {{-- AI --}}
    <section class="a-section" aria-labelledby="ai-heading">
        <div class="a-section-head"><h2 id="ai-heading" class="a-h2">{{ __('admin.usage.ai') }}</h2></div>
        <div class="a-table-wrap">
            <table class="a-table a-table-compact">
                <caption class="a-sr-only">{{ __('admin.usage.ai') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.usage.feature') }}</th>
                        <th scope="col">{{ __('admin.usage.outcome') }}</th>
                        <th scope="col" class="is-num">{{ __('admin.usage.calls') }}</th>
                        <th scope="col" class="is-num">{{ __('admin.usage.units') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ai as $row)
                        <tr>
                            <td class="a-mono">{{ $row['feature'] }}</td>
                            <td>
                                <span class="a-badge {{ $row['outcome'] === 'ok' ? 'a-badge-success' : ($row['outcome'] === 'error' ? 'a-badge-danger' : 'a-badge-warning') }}">{{ \App\Admin\Label::of('outcomes', $row['outcome']) }}</span>
                            </td>
                            <td class="is-num">{{ \Illuminate\Support\Number::format($row['calls']) }}</td>
                            <td class="is-num">{{ \Illuminate\Support\Number::format($row['units']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="a-muted">{{ __('admin.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
