<x-layouts.app :title="__('admin.dashboard.title')" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="__('admin.dashboard.title')" :subtitle="__('admin.dashboard.lead')">
        <x-slot:eyebrow>{{ __('admin.title') }}</x-slot:eyebrow>
    </x-ui.page-header>

    @php
        $tiles = [
            [__('admin.kpi.users'), $kpis['users'], __('admin.kpi.verified', ['count' => number_format($kpis['verified'])])],
            [__('admin.kpi.new_7'), $kpis['new_7'], __('admin.kpi.new_30', ['count' => number_format($kpis['new_30'])])],
            [__('admin.kpi.active_1'), $kpis['active_1'], __('admin.kpi.active_7_30', ['week' => number_format($kpis['active_7']), 'month' => number_format($kpis['active_30'])])],
            [__('admin.kpi.organisations'), $kpis['organisations'], null],
            [__('admin.kpi.products'), $kpis['products'], null],
            [__('admin.kpi.evaluations'), $kpis['evaluations'], null],
            [__('admin.kpi.reports'), $kpis['reports'], null],
            [__('admin.kpi.ai_today'), $kpis['ai_today'], __('admin.kpi.ai_30', ['count' => number_format($kpis['ai_30'])])],
        ];
    @endphp

    <section aria-labelledby="kpi-heading">
        <h2 id="kpi-heading" class="a-sr-only">{{ __('admin.dashboard.figures') }}</h2>
        <ul class="a-stats grid-cols-2! sm:grid-cols-4!">
            @foreach ($tiles as [$label, $value, $sub])
                <li class="a-stat">
                    <span class="a-stat-label">{{ $label }}</span>
                    <span class="a-stat-value">{{ number_format($value) }}</span>
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
                        <dd class="font-bold tabular-nums">{{ number_format($count) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    @include('guest._admin-kpis')

    <section class="a-section" aria-labelledby="charts-heading">
        <div class="a-section-head"><h2 id="charts-heading" class="a-h2">{{ __('admin.dashboard.trends', ['days' => $days]) }}</h2></div>
        <div class="grid gap-4 xl:grid-cols-2">
            @include('admin.partials.chart', ['chart' => $charts['signups'], 'title' => __('admin.charts.signups'), 'help' => __('admin.charts.signups_help')])
            @include('admin.partials.chart', ['chart' => $charts['active'], 'title' => __('admin.charts.active'), 'help' => __('admin.charts.active_help')])
            @include('admin.partials.chart', ['chart' => $charts['views'], 'title' => __('admin.charts.views'), 'help' => __('admin.charts.views_help')])
            @include('admin.partials.chart', ['chart' => $charts['ai'], 'title' => __('admin.charts.ai'), 'help' => __('admin.charts.ai_help')])
        </div>
    </section>
</x-layouts.app>
