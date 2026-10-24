<x-layouts.app :title="__('trees.title')">
    @include('trees._back')
    <x-ui.page-header :title="__('trees.title')" :subtitle="__('trees.intro')" />

    <div class="a-table-wrap">
        <table class="a-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('trees.standard') }}</th>
                    <th scope="col">{{ __('trees.article_col') }}</th>
                    <th scope="col" class="is-num">{{ __('trees.trees') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($standards as $code => $standard)
                    <tr>
                        <th scope="row">
                            <a href="{{ route('trees.standard', $code) }}" class="a-row-link" data-row-link>{{ $standard['name'] }}</a>
                        </th>
                        <td class="a-muted">
                            @if ($standard['article'])
                                {{ __('trees.article', ['article' => $standard['article']]) }}@if (! empty($articles[$standard['article']])) · {{ $articles[$standard['article']] }}@endif
                            @endif
                        </td>
                        <td class="is-num">{{ $standard['count'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

</x-layouts.app>
