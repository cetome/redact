{{--
    Free templates: the original cetome REDact workbooks (content pack templates/free/) with the old
    redact.cetome.com page's sections, words and pictures. Hero: "Download all" (primary) and the way
    to the app (secondary); one card per workbook (picture, one line, version and size, Download).
    Without workbooks in the pack the page says none is offered. Downloads: TemplateController.
--}}
@php
    $kb = fn (int $bytes) => __('templates.size', ['size' => number_format(max(1, (int) round($bytes / 1024)), 0, ',', \App\Support\Locales::detail('thousands'))]);
    $label = fn (string $key, string $part, mixed $fallback = null) => \Illuminate\Support\Facades\Lang::has('templates.files.'.$key.'.'.$part)
        ? __('templates.files.'.$key.'.'.$part) : $fallback;
@endphp
<x-layouts.guest :bare="true" :index="true" :page-title="__('templates.meta.title')" :description="__('templates.meta.description')" :json-ld="$jsonLd">
    <section class="c-hero">
        <div class="c-container">
            <p class="c-hero-kicker">{{ __('templates.kicker') }}</p>
            <h1 class="c-hero-title"><span class="c-dot-end">{{ __('templates.title') }}</span></h1>
            <p class="c-hero-lead mt-6">{{ __('templates.lead') }}</p>
            <div class="c-btn-row mt-10">
                @if ($zip)
                    <a href="{{ route('templates.download', ['file' => 'all']) }}" class="c-btn c-btn-on-dark c-btn-lg max-sm:w-full max-sm:px-6 max-sm:tracking-button" rel="nofollow" download
                       aria-label="{{ __('templates.all_label') }}" title="{{ __('templates.zip') }} · {{ $kb($zip['size']) }}">
                        <x-lucide-download class="size-4" aria-hidden="true" />{{ __('templates.all') }}
                    </a>
                @endif
                <x-try-link class="c-btn c-btn-outline-on-dark c-btn-lg max-sm:w-full max-sm:px-6 max-sm:tracking-button">{{ __('templates.app') }}</x-try-link>
            </div>
        </div>
    </section>

    <div class="c-container py-12">
        @if ($files === [])
            <p class="c-callout">{{ __('templates.none') }}</p>
        @endif

        @foreach ($groups as $group => $items)
            <section @class(['mt-14' => ! $loop->first]) aria-labelledby="group-{{ $group }}">
                <p class="c-kicker">{{ __('templates.groups.'.$group.'.kicker') }}</p>
                <h2 class="c-h3 mt-2" id="group-{{ $group }}">{{ __('templates.groups.'.$group.'.title') }}</h2>

                <div @class(['mt-6 grid gap-6', 'md:grid-cols-2' => count($items) > 1])>
                    @foreach ($items as $key => $file)
                        @php
                            $name = $label($key, 'name', preg_replace('/^cetome REDact - | v[\d.]+$/', '', pathinfo($file['name'], PATHINFO_FILENAME)));
                            $wide = count($items) === 1;
                        @endphp
                        <article @class(['c-card overflow-hidden flex flex-col', 'md:flex-row' => $wide])>
                            @if ($file['image'])
                                <img src="{{ route('templates.image.v', ['file' => $key, 'v' => $file['image']['v']]) }}"
                                     width="{{ $file['image']['width'] }}" height="{{ $file['image']['height'] }}"
                                     alt="{{ $label($key, 'alt', '') }}" loading="lazy" decoding="async"
                                     @class(['block h-auto w-full bg-white border-line', 'border-b', 'md:w-1/2 md:border-b-0 md:border-r' => $wide])>
                            @endif
                            <div class="c-card-body flex flex-1 flex-col gap-3">
                                @if ($codes = (array) $label($key, 'codes', []))
                                    <p class="flex flex-wrap gap-1.5">
                                        @foreach ($codes as $code)
                                            <span class="c-badge">{{ $code }}</span>
                                        @endforeach
                                    </p>
                                @endif
                                <h3 class="c-h3">{{ $name }}</h3>
                                @if ($text = $label($key, 'text'))
                                    <p class="c-text">{{ $text }}</p>
                                @endif
                                <div class="mt-auto flex flex-wrap items-center justify-between gap-3 pt-3">
                                    <span class="c-meta tabular-nums">
                                        {{ __('templates.xlsx') }}@if ($file['version']) · {{ __('templates.version', ['version' => $file['version']]) }}@endif · {{ $kb($file['size']) }}
                                    </span>
                                    <a href="{{ route('templates.download', ['file' => $key]) }}" class="c-btn c-btn-outline c-btn-sm"
                                       rel="nofollow" download aria-label="{{ __('templates.download_label', ['name' => $name]) }}">
                                        <x-lucide-download class="size-4" aria-hidden="true" />{{ __('templates.download') }}
                                    </a>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-layouts.guest>
