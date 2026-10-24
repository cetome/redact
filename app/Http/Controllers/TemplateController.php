<?php

namespace App\Http\Controllers;

use App\Support\FreeTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Free templates: the original cetome REDact workbooks (Scoping, Risk assessment, E.DT and E.Just,
 * E.Info, REDux, all in a zip), downloadable without an account, as redact.cetome.com offered them.
 * The page is indexed; the downloads are not (robots.txt), block obvious crawlers (403), allow
 * 4 downloads per 30 seconds per visitor (429) and are counted in the usage analytics.
 */
class TemplateController extends Controller
{
    /** Downloads allowed per visitor (IP, or account when signed in) and window, as on the old site. */
    public const PER_WINDOW = 4;

    public const WINDOW_SECONDS = 30;

    /** User agents that announce a robot (the old site's list, plus the usual words). */
    private const CRAWLERS = '/bot|crawl|spider|slurp|scrapy|turnitin|panscient|owler/i';

    /** Sections of the page (redact.cetome.com's): their workbooks; any other workbook goes in "more". */
    private const GROUPS = ['pre' => ['scoping', 'risk'], 'en18031' => ['edt', 'einfo'], 'redux' => ['redux']];

    public function index(FreeTemplates $templates): View
    {
        $files = $templates->all();

        $groups = [];
        foreach (self::GROUPS as $group => $keys) {
            $groups[$group] = array_intersect_key($files, array_flip($keys));
        }
        $groups['more'] = array_diff_key($files, array_flip(array_merge(...array_values(self::GROUPS))));

        return view('public.templates', [
            'files' => $files,
            'groups' => array_filter($groups),
            'zip' => $templates->zip(),
            'jsonLd' => [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => __('templates.title'),
                'url' => route('templates'),
                'description' => __('templates.meta.description'),
                'inLanguage' => app()->getLocale(),
                'isAccessibleForFree' => true,
                'publisher' => ['@type' => 'Organization', 'name' => 'cetome', 'url' => 'https://cetome.com/'],
                'hasPart' => array_values(array_map(fn (array $file) => [
                    '@type' => 'DigitalDocument',
                    'name' => pathinfo($file['name'], PATHINFO_FILENAME),
                    'encodingFormat' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'isAccessibleForFree' => true,
                ], $files)),
            ],
        ]);
    }

    public function download(Request $request, string $file, FreeTemplates $templates): BinaryFileResponse
    {
        abort_if(preg_match(self::CRAWLERS, (string) $request->userAgent()) === 1, 403);

        $template = $templates->find($file);
        abort_if($template === null, 404);

        $limiter = 'free-templates:'.($request->user()?->getAuthIdentifier() ?? $request->ip());
        if (RateLimiter::tooManyAttempts($limiter, self::PER_WINDOW)) {
            abort(429, '', ['Retry-After' => (string) RateLimiter::availableIn($limiter)]);
        }
        RateLimiter::hit($limiter, self::WINDOW_SECONDS);

        activity('usage')
            ->causedBy($request->user())
            ->event('template-download')
            ->withProperties(['file' => $template['key']])
            ->log('template download');

        $type = $template['key'] === FreeTemplates::ALL
            ? 'application/zip'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->download($template['path'], $template['name'], [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /** Picture of one workbook (content pack templates/free/images/), cached for a year (its name carries its version). */
    public function image(string $file, FreeTemplates $templates): BinaryFileResponse
    {
        $image = $templates->all()[$file]['image'] ?? null;
        abort_if($image === null, 404);

        return response()->file($image['path'], [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /** redact.cetome.com's download links (/dl/?file=scoping …) keep working. */
    public function legacy(Request $request): RedirectResponse
    {
        $file = $request->query('file', FreeTemplates::ALL);

        return is_string($file) && preg_match('/^[a-z0-9-]{1,40}$/', $file) === 1
            ? redirect()->route('templates.download', ['file' => $file], 301)
            : redirect()->route('templates', [], 301);
    }
}
