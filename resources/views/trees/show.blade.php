<x-layouts.app :title="$tree->requirement().' · '.$name">
    @include('trees._back')
    <x-ui.page-header :title="$tree->requirement()" :muted="$tree->title()">
        <x-slot:eyebrow>
            <a href="{{ route('trees.index') }}">{{ __('trees.title') }}</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('trees.standard', $standard) }}">{{ $name }}</a>
        </x-slot:eyebrow>
        <p class="a-help mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
            @if ($tree->clause())<span>{{ __('evaluations.clause', ['clause' => $tree->clause()]) }}</span>@endif
            @if ($tree->figure())<span>{{ $tree->figure() }}</span>@endif
        </p>
        @if ($others)
            <x-slot:actions>
                <nav class="a-seg" aria-label="{{ __('trees.other_parts') }}">
                    <span class="a-seg-btn" aria-current="page">{{ $name }}</span>
                    @foreach ($others as $other)
                        <a class="a-seg-btn" href="{{ route('trees.show', [$other, $tree->requirement()]) }}">{{ app(\App\Content\ContentPack::class)->standardName($other) }}</a>
                    @endforeach
                </nav>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($tree->scope())
        <div class="a-alert"><x-lucide-repeat aria-hidden="true" /><span class="min-w-0 [overflow-wrap:anywhere]"><strong>{{ __('evaluations.scope_label') }}</strong> {{ $tree->scope() }}</span></div>
    @endif

    <div class="max-w-3xl">
        @include('trees._diagram', ['tree' => $tree, 'order' => $order, 'idPrefix' => 'node'])
    </div>

</x-layouts.app>
