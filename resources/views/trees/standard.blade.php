<x-layouts.app :title="$name">
    @include('trees._back')
    <x-ui.page-header :title="$name">
        <x-slot:eyebrow><a href="{{ route('trees.index') }}">{{ __('trees.title') }}</a></x-slot:eyebrow>
    </x-ui.page-header>

    <div class="a-toolbar" data-filter-scope>
        <x-ui.search target="#tree-rows" :placeholder="__('trees.search')" />
        <span class="a-toolbar-count" data-filter-count data-template="{{ __('ui.shown') }}"></span>
        <span class="a-toolbar-end a-toolbar-count">{{ trans_choice('trees.count', collect($families)->flatten(1)->count(), ['count' => collect($families)->flatten(1)->count()]) }}</span>
    </div>

    <div class="a-table-wrap">
        <table class="a-table">
            <thead>
                <tr>
                    <th scope="col" class="is-shrink">{{ __('trees.requirement') }}</th>
                    <th scope="col">{{ __('trees.tree_title') }}</th>
                    <th scope="col" class="is-shrink">{{ __('trees.clause') }}</th>
                </tr>
            </thead>
            <tbody id="tree-rows">
                @foreach ($families as $family => $trees)
                    <tr class="a-table-group" data-filter-group>
                        <th colspan="3" scope="colgroup">[{{ $family }}] {{ \App\Content\Text::of($familyNames[$family]['name'] ?? '') }}</th>
                    </tr>
                    @foreach ($trees as $tree)
                        <tr data-filter-item data-search="{{ $family }} {{ $tree->requirement() }} {{ $tree->title() }}">
                            <th scope="row" class="is-nowrap">
                                <a class="a-row-link" data-row-link href="{{ route('trees.show', [$standard, $tree->requirement()]) }}">{{ $tree->requirement() }}</a>
                            </th>
                            <td>{{ $tree->title() }}</td>
                            <td class="is-nowrap a-muted">{{ $tree->clause() }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
        <p class="a-help px-4 py-3" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</p>
    </div>

</x-layouts.app>
