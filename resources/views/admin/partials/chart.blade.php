{{--
    One console chart with its accessible table.
    @include('admin.partials.chart', ['chart' => DailySeries::chart(...), 'title' => '...', 'help' => '...'])
    The canvas is drawn by the `adminChart` Alpine component (resources/js/modules/admin.js).
--}}
@php($total = array_sum($chart['values']))
<figure class="a-panel">
    <div class="a-panel-head">
        <div class="min-w-0">
            <h3 class="a-h3">{{ $title }}</h3>
            @isset($help)
                <p class="a-help mt-0.5">{{ $help }}</p>
            @endisset
        </div>
        <span class="a-badge a-badge-neutral">{{ __('admin.charts.total', ['total' => number_format($total)]) }}</span>
    </div>
    <div class="a-panel-body">
        <div class="relative h-52" x-data="adminChart" data-chart="{{ json_encode($chart) }}">
            <canvas role="img" aria-label="{{ __('admin.charts.aria', ['title' => $title, 'total' => number_format($total)]) }}"></canvas>
        </div>
        <details class="mt-3">
            <summary class="a-link-btn cursor-pointer text-label">{{ __('admin.charts.show_table') }}</summary>
            <div class="a-table-wrap mt-2 max-h-72 overflow-y-auto">
                <table class="a-table a-table-compact">
                    <caption class="a-sr-only">{{ $title }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.charts.day') }}</th>
                            <th scope="col" class="is-num">{{ $chart['label'] }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_reverse(array_combine($chart['labels'], $chart['values'])) as $day => $value)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($day)->isoFormat('D MMMM YYYY') }}</td>
                                <td class="is-num">{{ number_format($value) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</figure>
