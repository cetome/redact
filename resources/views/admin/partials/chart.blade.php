{{--
    One console chart with its accessible table.
    @include('admin.partials.chart', ['chart' => DailySeries::chart(...), 'title' => '...', 'help' => '...'])
    A stacked chart (App\Admin\WorkActivity::chart) holds `datasets` instead of `values`: one column per series.
    The canvas is drawn by the `adminChart` Alpine component (resources/js/modules/admin.js).
--}}
@php
    $series = $chart['datasets'] ?? [['label' => $chart['label'], 'values' => $chart['values']]];
    $total = array_sum(array_map(fn ($one) => array_sum($one['values']), $series));
@endphp
<figure class="a-panel">
    <div class="a-panel-head">
        <div class="min-w-0">
            <h3 class="a-h3">{{ $title }}</h3>
            @isset($help)
                <p class="a-help mt-0.5">{{ $help }}</p>
            @endisset
        </div>
        <span class="a-badge a-badge-neutral">{{ __('admin.charts.total', ['total' => \Illuminate\Support\Number::format($total)]) }}</span>
    </div>
    <div class="a-panel-body">
        <div @class(['relative', isset($chart['datasets']) ? 'h-72' : 'h-52']) x-data="adminChart" data-chart="{{ json_encode($chart) }}">
            <canvas role="img" aria-label="{{ __('admin.charts.aria', ['title' => $title, 'total' => \Illuminate\Support\Number::format($total)]) }}"></canvas>
        </div>
        <details class="mt-3">
            <summary class="a-link-btn cursor-pointer text-label">{{ __('admin.charts.show_table') }}</summary>
            <div class="a-table-wrap mt-2 max-h-72 overflow-y-auto">
                <table class="a-table a-table-compact">
                    <caption class="a-sr-only">{{ $title }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.charts.day') }}</th>
                            @foreach ($series as $one)
                                <th scope="col" class="is-num">{{ $one['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_reverse(array_keys($chart['labels'])) as $i)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($chart['labels'][$i])->isoFormat('D MMMM YYYY') }}</td>
                                @foreach ($series as $one)
                                    <td class="is-num">{{ \Illuminate\Support\Number::format($one['values'][$i]) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</figure>
