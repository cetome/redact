<?php

namespace App\Http\Controllers;

use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\SbomImport;
use App\Models\VexImport;
use App\PublicPage\PublicPageData;
use App\PublicPage\PublicSettings;
use App\Support\StoredPicture;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public product page /verify/{code} (no account): the product's latest published version,
 * or an earlier published one, in the visitor's language or the one picked on the page (a path
 * segment, never a query string). Only what the manufacturer published is read: a version that
 * is not published, a file the page does not offer, an unknown code all answer 404.
 */
class PublicProductController extends Controller
{
    public function show(Request $request, string $code): View
    {
        // Parameters by name: the routes pass a version, a language, both or neither.
        $version = $request->route('version');
        $lang = $request->route('lang');
        $product = $this->product($code);
        $published = $this->published($product);
        $current = $version === null ? $published->first() : $published->firstWhere('id', $version);
        abort_unless($current instanceof ProductVersion, 404);
        if ($lang !== null) {
            app()->setLocale($lang);
        }

        $page = new PublicPageData($current, PublicSettings::live($current) ?? abort(404));

        return view('public-product.show', [
            'product' => $product,
            'page' => $page,
            'published' => $published,
            'latest' => $current->is($published->first()),
            'jsonLd' => $this->jsonLd($page, $lang),
        ]);
    }

    /**
     * Search engines index the latest published version: the bare address follows the visitor's
     * language (x-default), /verify/{code}/{lang} is each language (head: canonical and alternates).
     *
     * @return array<string, string>
     */
    public static function alternates(string $code): array
    {
        $links = ['x-default' => route('verify.show', $code)];
        foreach (array_keys((array) config('redact.locales')) as $lang) {
            $links[$lang] = route('verify.show.lang', [$code, $lang]);
        }

        return $links;
    }

    /** @return array<string, mixed> schema.org: the page about the product and its manufacturer. */
    private function jsonLd(PublicPageData $page, ?string $lang): array
    {
        $product = $page->version->product;
        $code = (string) $product->public_code;

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => __('public_page.page.title', ['product' => $product->name]),
            'url' => $lang === null ? route('verify.show', $code) : route('verify.show.lang', [$code, $lang]),
            'inLanguage' => app()->getLocale(),
            'about' => array_filter([
                '@type' => 'Product',
                'name' => $product->name,
                'model' => $page->models()[0] ?? null,
                'image' => $product->image_path ? route('verify.picture', $code) : null,
                'manufacturer' => ['@type' => 'Organization', 'name' => $page->manufacturer()['name']],
            ]),
        ];
    }

    public function picture(string $code): StreamedResponse
    {
        $product = $this->product($code);
        abort_unless($this->published($product)->isNotEmpty() && $product->image_path && Storage::disk('local')->exists($product->image_path), 404);

        return Storage::disk('local')->response($product->image_path, 'product.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** The organisation's logo, when a published version of the product shows it. */
    public function logo(string $code): StreamedResponse
    {
        $product = $this->product($code);
        $shown = $this->published($product)->contains(fn (ProductVersion $v) => PublicSettings::live($v)?->shows('logo') ?? false);
        abort_unless($shown && $product->organisation->logo_path, 404);

        return StoredPicture::response((string) $product->organisation->logo_path, 'logo.png', 'public, max-age=300');
    }

    public function document(string $code, string $version, Document $document): Response
    {
        $page = $this->page($code, $version);
        abort_unless($page->offersDocument($document) && Storage::disk(DocumentStore::DISK)->exists($document->path), 404);

        return $this->download(DocumentStore::DISK, $document->path, $document->original_name, $document->mime);
    }

    public function bom(string $code, string $version, SbomImport $import, int $file): Response
    {
        $page = $this->page($code, $version);
        abort_unless($import->product_version_id === $page->version->id && $page->offersBom($import), 404);

        return $this->file($import->files[$file] ?? null);
    }

    public function vex(string $code, string $version, VexImport $vex, int $file): Response
    {
        $page = $this->page($code, $version);
        abort_unless($vex->product_version_id === $page->version->id && $page->offersVex($vex), 404);

        return $this->file($vex->files[$file] ?? null);
    }

    private function product(string $code): Product
    {
        return Product::query()->where('public_code', $code)->with('organisation')->firstOrFail();
    }

    /** @return Collection<int, ProductVersion> the published versions, newest first */
    private function published(Product $product)
    {
        return $product->versions()->whereNotNull('published_at')->get();
    }

    private function page(string $code, string $version): PublicPageData
    {
        $current = $this->published($this->product($code))->firstWhere('id', $version);
        abort_unless($current instanceof ProductVersion, 404);

        return new PublicPageData($current, PublicSettings::live($current) ?? abort(404));
    }

    /** @param  array{name?: string, path?: string, mime?: string}|null  $entry */
    private function file(?array $entry): Response
    {
        abort_unless(is_array($entry) && isset($entry['path']) && Storage::disk('local')->exists($entry['path']), 404);

        return $this->download('local', $entry['path'], (string) ($entry['name'] ?? 'file'), 'application/octet-stream');
    }

    private function download(string $disk, string $path, string $name, string $mime): Response
    {
        return Storage::disk($disk)->download($path, $name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
