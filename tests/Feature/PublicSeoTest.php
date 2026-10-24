<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Search engines: the landing page is indexed with its title, canonical link and schema.org data;
 * the application and the sign-in pages are not.
 */
class PublicSeoTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_the_home_page_is_indexed_with_structured_data(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="index, follow', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.route('home').'">', $html);
        $this->assertStringContainsString('<title>REDact, compliance REDefined ◼ cetome</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="'.e(__('public.meta.description')).'">', $html);
        $this->assertStringContainsString('For RED cyber and EN 18031', $html);
        $this->assertStringNotContainsString('Delegated Act', strip_tags(preg_replace('#<head>.*</head>#s', '', $html)), 'Only metadata names the Delegated Act on the home page.');

        $data = $this->jsonLd($html);
        $this->assertSame('SoftwareApplication', $data['@graph'][0]['@type'] ?? null);
        $this->assertCount(1, $data['@graph'], 'The FAQ lives on /about.');
    }

    public function test_the_about_page_is_indexed_with_its_own_title_and_the_faq(): void
    {
        $html = $this->get(route('about'))->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="index, follow', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.route('about').'">', $html);
        $this->assertStringContainsString('<title>'.e(__('public.about.meta.title')).'</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="'.e(__('public.about.meta.description')).'">', $html);
        $this->assertStringContainsString(e(__('public.how_title')), $html);
        $this->assertStringContainsString(e(__('public.about.ways_title')), $html);

        $data = $this->jsonLd($html);
        $this->assertSame('AboutPage', $data['@graph'][0]['@type'] ?? null);
        $this->assertSame('FAQPage', $data['@graph'][1]['@type'] ?? null);
        $this->assertNotEmpty($data['@graph'][1]['mainEntity']);

        // Linked from every public page (header and footer).
        $this->assertStringContainsString('href="'.route('about').'"', $this->get(route('home'))->getContent());
    }

    /** @return array<string, mixed> */
    private function jsonLd(string $html): array
    {
        preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m);

        return (array) json_decode($m[1] ?? '', true);
    }

    public function test_sign_in_and_application_pages_are_not_indexed(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $owner = $this->owner();
        $this->product($owner);
        $this->signIn($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('application/ld+json', false);
    }

    public function test_robots_txt_points_to_the_sitemap_and_keeps_the_app_out(): void
    {
        $this->get('/robots.txt')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('Disallow: /versions')
            ->assertSee('Sitemap: '.route('sitemap'));

        $this->get('/sitemap.xml')->assertOk()
            ->assertSee('<loc>'.route('home').'</loc>', false)
            ->assertSee('<loc>'.route('about').'</loc>', false);
    }
}
