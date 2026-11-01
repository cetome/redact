{{--
    Printable QR labels of a published version (PublicPageController::labels): an A4 sheet of one
    kind (App\PublicPage\Labels), at actual size, in the language picked. A page of its own, so
    only the sheet prints: the bar above it is hidden on paper.
--}}
@php
    $product = $version->product;
    $size = \App\PublicPage\Labels::KINDS[$kind];
    $sheet = \App\PublicPage\Labels::SHEET;
    $short = preg_replace('#^https?://#', '', $url);
    // On the packaging sticker the address breaks after the host, never inside the code.
    [$host, $path] = explode('/', $short, 2) + [1 => ''];
    // One SVG for every copy (the QR code download), sharp at any print size.
    $qr = route('products.qr', [$product, 'svg']);
    $t = fn (string $key, array $replace = []) => __('public_page.labels.'.$key, $replace, $lang);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-layouts.head :title="__('public_page.labels.title').' · '.$version->displayName()" />
    {{-- Sizes in millimetres from Labels::KINDS, so the sheet and the paper agree. --}}
    <style nonce="{{ app('csp-nonce') }}">
        .pl-sheet { width: {{ $sheet['w'] }}mm; min-height: {{ $sheet['h'] }}mm; padding: {{ $sheet['margin'] }}mm;
            grid-template-columns: repeat({{ $grid['cols'] }}, {{ $size['w'] }}mm); grid-auto-rows: {{ $size['h'] }}mm; gap: {{ $size['gap'] }}mm; }
    </style>
</head>
<body class="bg-canvas text-ink font-brand antialiased">
    <header class="pl-bar">
        <div class="pl-bar-row">
            <a href="{{ route('versions.public', $version) }}" class="a-btn a-btn-ghost a-btn-sm"><x-lucide-arrow-left aria-hidden="true" />{{ __('public_page.title') }}</a>
            <h1 class="a-h3">{{ __('public_page.labels.title') }} <span class="a-muted font-normal">{{ $version->displayName() }}</span></h1>
            <span class="a-only-js ml-auto"><button type="button" class="a-btn a-btn-primary" data-print><x-lucide-printer aria-hidden="true" />{{ __('public_page.labels.print') }}</button></span>
        </div>
        <div class="pl-bar-row">
            <nav aria-label="{{ __('public_page.labels.kind') }}">
                <span class="a-seg">
                    @foreach (\App\PublicPage\Labels::KINDS as $k => $s)
                        <a href="{{ route('versions.public.labels', [$version, $k, $lang]) }}" class="a-seg-btn" @if ($k === $kind) aria-current="page" @endif>
                            {{ __('public_page.labels.kinds.'.$k) }} <span class="a-muted max-sm:hidden">{{ __('public_page.labels.size', ['w' => $s['w'], 'h' => $s['h']]) }}</span>
                        </a>
                    @endforeach
                </span>
            </nav>
            <nav aria-label="{{ __('public_page.labels.language') }}">
                <span class="a-seg">
                    @foreach (config('redact.locales') as $code => $name)
                        <a href="{{ route('versions.public.labels', [$version, $kind, $code]) }}" class="a-seg-btn" lang="{{ $code }}" title="{{ $name }}" @if ($code === $lang) aria-current="page" @endif>{{ strtoupper($code) }}</a>
                    @endforeach
                </span>
            </nav>
            <p class="a-help">{{ __('public_page.labels.per_sheet', ['count' => $grid['count'], 'cols' => $grid['cols'], 'rows' => $grid['rows']]) }} {{ __('public_page.labels.print_help') }}</p>
        </div>
    </header>

    <main id="main" class="pl-scroll">
        <div class="pl-sheet pl-{{ $kind }}" lang="{{ $lang }}">
            @for ($i = 0; $i < $grid['count']; $i++)
                <div class="pl-label" @if ($i > 0) aria-hidden="true" @endif>
                    <img class="pl-qr" src="{{ $qr }}" alt="">
                    @if ($kind === 'product')
                        <span class="pl-code">verify/{{ $product->public_code }}</span>
                    @elseif ($kind === 'packaging')
                        <span class="pl-text">
                            <b class="pl-head">{{ $t('heading') }}</b>
                            <span>{{ $product->name }} · {{ $version->label }}</span>
                            <span class="pl-maker">{{ $maker }}</span>
                            <span class="pl-url"><span>{{ $host }}</span><span>/{{ $path }}</span></span>
                        </span>
                    @else
                        <span class="pl-text">
                            <b class="pl-head">{{ $t('heading_long') }}</b>
                            <span class="pl-product">{{ $t('version', ['product' => $product->name, 'version' => $version->label]) }}</span>
                            @if ($contents)<span>{{ $contents }}</span>@endif
                            <span class="pl-maker">{{ $maker }}</span>
                            <span class="pl-url">{{ $short }}</span>
                        </span>
                    @endif
                </div>
            @endfor
        </div>
    </main>
</body>
</html>
