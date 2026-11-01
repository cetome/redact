{{--
    Public product page /verify/{code} (PublicProductController): the manufacturer's page, not
    REDact's: their name on top, the language buttons (path segments), the page (its address and
    QR code in the label at the top), then "Made with cetome REDact" in the footer. The latest
    version is indexed.
--}}
@php
    $maker = $page->manufacturer()['name'];
    $code = $product->public_code;
    $version = $page->version;
    $langUrl = fn (string $lang) => $latest ? route('verify.show.lang', [$code, $lang]) : route('verify.version.lang', [$code, $version->id, $lang]);
    $updated = $version->public_page['live']['at'] ?? $version->published_at;
    // Indexed when the manufacturer chose so: the latest version, each language at its own address.
    // Earlier versions stay out of search results.
    $indexed = $latest && $page->settings->indexed;
    $alternates = \App\Http\Controllers\PublicProductController::alternates($code);
    $lang = request()->route('lang');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-layouts.head :title="__('public_page.page.title', ['product' => $product->name])" :page-title="__('public_page.page.title', ['product' => $product->name])"
        :description="trim(__('public_page.page.description', ['product' => $product->name, 'manufacturer' => $maker]).' '.\App\PublicPage\Labels::contents($version, app()->getLocale()))"
        :index="$indexed" :canonical="$lang ? $alternates[$lang] : $alternates['x-default']" :alternates="$alternates" :json-ld="$indexed ? $jsonLd : null" />
</head>
<body class="bg-canvas text-ink font-brand antialiased">
    <div class="pp-shell">
        <header class="pp-bar-top">
            <div class="pp-bar-inner">
                <p class="pp-maker">{{ $maker }}</p>
                <div class="flex items-center gap-2">
                    <nav class="pp-langs" aria-label="{{ __('public_page.page.language') }}">
                        @foreach (config('redact.locales') as $lang => $name)
                            <a href="{{ $langUrl($lang) }}" class="a-btn a-btn-sm {{ app()->getLocale() === $lang ? 'a-btn-primary' : 'a-btn-outline' }}" lang="{{ $lang }}"
                               title="{{ $name }}" @if (app()->getLocale() === $lang) aria-current="page" @endif>{{ strtoupper($lang) }}</a>
                        @endforeach
                    </nav>
                    <x-ui.theme-toggle />
                </div>
            </div>
        </header>

        <main id="main" class="pp-body">
            @include('public-product._page', ['preview' => false])
        </main>

        <footer class="pp-foot">
            <div class="pp-foot-inner">
                <p>{{ __('public_page.page.updated', ['manufacturer' => $maker, 'date' => \Illuminate\Support\Carbon::parse($updated ?? now())->locale(app()->getLocale())->isoFormat('D MMMM YYYY')]) }}</p>
                <div class="pp-made">
                    <span>{{ __('public_page.page.made_with') }} <a href="{{ route('home') }}">cetome REDact</a></span>
                </div>
            </div>
        </footer>
    </div>
</body>
</html>
