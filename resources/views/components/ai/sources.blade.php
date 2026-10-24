{{--
    The documents an AI proposal was taken from, as pills (CRAted's DraftSources).
    <x-ai.sources :sources="[['document' => 'manual.pdf', 'location' => 'p. 4']]" />
--}}
@props(['sources' => []])
@if (! empty($sources))
    <ul {{ $attributes->class(['flex flex-wrap gap-1.5']) }} aria-label="{{ __('ai.sources') }}">
        @foreach ($sources as $source)
            <li class="inline-flex items-center gap-1 rounded-md border border-line bg-surface px-1.5 py-0.5 text-[10px] text-ink-muted">
                <x-lucide-file-text class="size-3 shrink-0 text-brand-blue dark:text-brand-blue-night" aria-hidden="true" />
                <span class="font-semibold text-ink">{{ $source['document'] ?? '' }}</span>
                @if (! empty($source['location']))
                    <span>· {{ $source['location'] }}</span>
                @endif
            </li>
        @endforeach
    </ul>
@endif
