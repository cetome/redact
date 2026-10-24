{{-- Small table: label => count, with an optional share. Expects $rows, $labelHeading, $valueHeading, $percent. --}}
@php($sum = max(1, array_sum($rows)))
@if ($rows === [])
    <p class="a-help px-4 py-3">{{ __('admin.no_data') }}</p>
@else
    <table class="a-table a-table-compact">
        <caption class="a-sr-only">{{ $labelHeading }}</caption>
        <thead>
            <tr>
                <th scope="col">{{ $labelHeading }}</th>
                <th scope="col" class="is-num">{{ $valueHeading }}</th>
                @if ($percent)
                    <th scope="col" class="is-num">{{ __('admin.usage.share') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $label => $count)
                <tr>
                    <td>{{ $label }}</td>
                    <td class="is-num">{{ number_format($count) }}</td>
                    @if ($percent)
                        <td class="is-num">{{ number_format($count / $sum * 100, 1) }} %</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
