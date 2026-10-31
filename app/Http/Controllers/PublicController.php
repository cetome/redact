<?php

namespace App\Http\Controllers;

use App\Assessment\Assessment;
use App\Assessment\ScopingForm;
use App\Billing\Plan;
use App\Content\ContentPack;
use App\Guest\Sandbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public pages: the home page (hero, the RED scoping tool at #start, then the workflow with a
 * picture of each step), about (what REDact is, FAQ), legal texts (terms and legal notice on one page, privacy), security.txt (RFC 9116), robots.txt and the sitemap.
 * Only home, about, free templates and "try without an account" are meant for search engines.
 */
class PublicController extends Controller
{
    /**
     * The workflow in its real order, then the reports: "What happens next" and the tour pictures.
     * "Changes" exists only on a version made from an earlier one: not in the tour of a first assessment.
     */
    public const TOUR = ['description', 'scoping', 'risks', 'inventory', 'evaluate', 'einfo', 'units', 'conformity', 'review', 'declaration', 'reports'];

    public function home(ContentPack $pack, ScopingForm $form): View
    {
        return view('public.home', [
            'jsonLd' => ['@context' => 'https://schema.org', '@graph' => [$this->application()]],
            'steps' => $this->tourSteps(),
            // The tool at #start: the scoping cards and their live result.
            'pack' => $pack,
            'liveMap' => $form->liveMap(),
            'hours' => Sandbox::ttlHours(),
            // Pricing under the slider: the four offers of /pricing (public/_plans).
            ...$this->plans(),
        ]);
    }

    /** What REDact is, how it works, the FAQ, and the pictures of each step (the tour without script). */
    public function about(): View
    {
        return view('public.about', ['steps' => $this->tourSteps(), 'jsonLd' => [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'AboutPage',
                    'name' => __('public.about.meta.title'),
                    'url' => route('about'),
                    'description' => __('public.about.meta.description'),
                    'inLanguage' => app()->getLocale(),
                    'about' => ['@type' => 'SoftwareApplication', 'name' => 'REDact', 'url' => route('home')],
                ],
                [
                    '@type' => 'FAQPage',
                    'mainEntity' => array_map(fn (array $item) => [
                        '@type' => 'Question',
                        'name' => $item['q'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                    ], (array) __('public.faq')),
                ],
            ],
        ]]);
    }

    public function terms(): View
    {
        return view('public.terms');
    }

    public function privacy(): View
    {
        return view('public.privacy');
    }

    /** The social card's address: its version (a hash of the file) in its name, never in a query. */
    public static function ogImageUrl(): string
    {
        $file = public_path('images/og-redact.png');

        return is_file($file) ? route('og.image', substr((string) hash_file('xxh3', $file), 0, 8)) : asset('images/og-redact.png');
    }

    /** The social card under its versioned name (an older version: on to the current one). */
    public function ogImage(string $v): BinaryFileResponse|RedirectResponse
    {
        $current = self::ogImageUrl();
        if ($current !== route('og.image', $v)) {
            return redirect()->to($current, 301);
        }

        return response()->file(public_path('images/og-redact.png'), [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function securityTxt(): Response
    {
        $contact = config('redact.security_contact');
        $body = implode("\n", [
            'Contact: mailto:'.$contact,
            'Encryption: '.config('redact.security_pgp.url'),
            'Encryption: openpgp4fpr:'.str_replace(' ', '', (string) config('redact.security_pgp.fingerprint')),
            'Expires: '.now()->addYear()->startOfMonth()->toIso8601ZuluString(),
            'Preferred-Languages: en, fr',
            'Canonical: '.route('security.txt'),
            'Policy: '.route('legal.terms').'#security',
            '',
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** Crawlers may read the public pages; the application itself is private (and noindex). */
    public function robots(): Response
    {
        $body = implode("\n", [
            'User-agent: *',
            'Allow: /$',
            'Allow: /about$',
            'Allow: /pricing$',
            'Allow: /templates$',
            'Allow: /templates/*.webp$',
            'Allow: /contact$',
            'Allow: /try$',
            'Disallow: /dashboard',
            'Disallow: /products',
            'Disallow: /versions',
            'Disallow: /account',
            'Disallow: /admin',
            // The free templates page is indexed, its downloads are not.
            'Disallow: /templates/',
            'Disallow: /dl',
            'Disallow: /invitations',
            'Disallow: /ai',
            '',
            'Sitemap: '.route('sitemap'),
            '',
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function sitemap(): Response
    {
        $urls = [route('home'), route('about'), route('pricing'), route('templates'), route('contact')];
        if (config('redact.guest.enabled') && app('router')->has('guest.start')) {
            $urls[] = route('guest.start');
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url).'</loc></url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /**
     * Pricing: open source, web-based Free and Paid, self-hosted with cetome rules. Every number comes
     * from config('redact.plans') and App\Billing\Plan, so the page follows the configuration.
     */
    public function pricing(): View
    {
        $plans = $this->plans();
        $faq = (array) __('public.pricing.faq');

        return view('public.pricing', [
            ...$plans,
            'jsonLd' => [
                '@context' => 'https://schema.org',
                '@graph' => [
                    [
                        '@type' => 'WebPage',
                        'name' => __('public.pricing.meta.title'),
                        'url' => route('pricing'),
                        'description' => __('public.pricing.meta.description', ['price' => $plans['price'], 'count' => $plans['included']]),
                        'inLanguage' => app()->getLocale(),
                        'about' => ['@type' => 'SoftwareApplication', 'name' => 'REDact', 'url' => route('home')],
                    ],
                    [
                        '@type' => 'FAQPage',
                        'mainEntity' => array_map(fn (array $item) => [
                            '@type' => 'Question',
                            'name' => $item['q'],
                            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                        ], $faq),
                    ],
                ],
            ],
        ]);
    }

    /**
     * The offers, from the configuration (public/_plans on the home page and /pricing): the price of a
     * paid product, the products the paid offer includes (the free ones plus the first paid one), the
     * free and paid allowances, the contact and open-source links.
     *
     * @return array{price: string, included: int, free: array<string, mixed>, paid: array<string, mixed>, contactUrl: string, billing: bool, pricingContactUrl: string, openSourceUrl: string}
     */
    private function plans(): array
    {
        $free = (array) config('redact.plans.free');

        return [
            'price' => Plan::priceLabel(),
            'included' => (int) ($free['products'] ?? 1) + 1,
            'free' => $free,
            'paid' => (array) config('redact.plans.paid'),
            'contactUrl' => Plan::contactUrl('self-hosted'),
            // Without Stripe there is no check-out: the paid offer is "Talk to us" (pricing topic).
            'billing' => Plan::billingEnabled(),
            'pricingContactUrl' => Plan::contactUrl('pricing'),
            'openSourceUrl' => (string) config('redact.open_source_url', ''),
        ];
    }

    /**
     * The tour steps and their labels (a step just added to the workflow shows its key until its
     * label exists).
     *
     * @return array<string, string>
     */
    private function tourSteps(): array
    {
        $steps = [];
        foreach (self::TOUR as $step) {
            $steps[$step] = Lang::has('workflow.steps.'.$step) ? (string) __('workflow.steps.'.$step) : Str::headline($step);
        }

        return $steps;
    }

    /**
     * schema.org data of the home page: the application and its publisher.
     *
     * @return array<string, mixed>
     */
    private function application(): array
    {
        return [
            '@type' => 'SoftwareApplication',
            'name' => 'REDact',
            'url' => route('home'),
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'description' => __('public.meta.description'),
            'inLanguage' => ['en', 'fr'],
            'image' => self::ogImageUrl(),
            'publisher' => ['@type' => 'Organization', 'name' => 'cetome', 'url' => 'https://cetome.com/'],
        ];
    }
}
