<?php

namespace Tests\Feature;

use App\Content\ContentPack;
use App\Http\Controllers\TemplateController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers;
use Tests\TestCase;
use ZipArchive;

/**
 * Free templates (/templates): indexed page, downloads without an account with their original
 * names, crawlers refused, 4 downloads per 30 seconds, each one counted; without workbooks in
 * the pack the page says none is offered.
 *
 * The workbooks are invented bytes in a copy of the fixture pack (never the real files).
 */
class FreeTemplatesTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    private const FILES = [
        'scoping' => 'Example pack - Scoping v1.0.xlsx',
        'risk' => 'Example pack - Risk assessment v2.1.xlsx',
        'edt' => 'Example pack - E.DT and E.Just v1.03.xlsx',
    ];

    /** A 1 x 1 WebP picture. */
    private const WEBP = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    /** A copy of the fixture pack with three invented workbooks in templates/free/. */
    private function withTemplates(): void
    {
        $this->dir = sys_get_temp_dir().'/redact-free-'.bin2hex(random_bytes(6));
        File::copyDirectory(base_path('tests/Fixtures/content-pack'), $this->dir.'/pack');
        File::ensureDirectoryExists($this->dir.'/pack/templates/free');
        File::ensureDirectoryExists($this->dir.'/storage');

        File::ensureDirectoryExists($this->dir.'/pack/templates/free/images');
        File::put($this->dir.'/pack/templates/free/images/scoping.webp', base64_decode(self::WEBP));

        $entries = [];
        foreach (self::FILES as $key => $name) {
            File::put($this->dir.'/pack/templates/free/'.$name, 'workbook '.$key.' '.str_repeat('x', 3000));
            $entries[] = ['key' => $key, 'file' => $name, 'image' => 'images/'.$key.'.webp'];
        }
        // Refused: not a plain .xlsx name, a missing file, the reserved key.
        $entries[] = ['key' => 'evil', 'file' => '../pack.json'];
        $entries[] = ['key' => 'gone', 'file' => 'Missing v1.0.xlsx'];
        $entries[] = ['key' => 'all', 'file' => self::FILES['scoping']];
        File::put($this->dir.'/pack/templates/free/free.json', json_encode(['zip' => 'Example templates v1.0.zip', 'files' => $entries]));

        $this->app->instance(ContentPack::class, new ContentPack($this->dir.'/pack'));
        $this->app->useStoragePath($this->dir.'/storage');
    }

    private function download(string $file, string $agent = self::BROWSER)
    {
        return $this->withHeaders(['User-Agent' => $agent])->get(route('templates.download', ['file' => $file]));
    }

    public function test_the_page_is_indexed_lists_each_workbook_and_is_in_the_sitemap(): void
    {
        $this->withTemplates();

        $html = $this->get(route('templates'))->assertOk()
            ->assertSee(__('templates.title'))
            ->assertSee(__('templates.files.scoping.name'))
            ->assertSee(__('templates.files.risk.text'))
            ->assertSee('v2.1')
            ->assertSee('v1.03')
            ->assertSee(__('templates.size', ['size' => 3]))
            ->assertSee(route('templates.download', ['file' => 'all']), false)
            ->assertSee(route('templates.download', ['file' => 'edt']), false)
            ->assertDontSee(route('templates.download', ['file' => 'evil']), false)
            ->assertDontSee(route('templates.download', ['file' => 'gone']), false)
            ->assertSee('href="'.route('guest.start').'" class="c-btn c-btn-outline-on-dark', false)
            ->assertSee(__('templates.groups.pre.title'))
            ->assertSee(e(__('templates.files.scoping.alt')), false)
            ->getContent();

        // Only the workbook whose picture exists shows one.
        // Its version is part of its name (/templates/scoping.<v>.webp), never a query.
        $this->assertSame(1, preg_match_all('#<img src="'.preg_quote(url('/templates/scoping.'), '#').'[0-9a-f]{8}\.webp"#', $html));

        $this->assertStringContainsString('<meta name="robots" content="index, follow', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.route('templates').'">', $html);
        $this->assertStringContainsString('<title>'.e(__('templates.meta.title')).'</title>', $html);
        $this->assertStringContainsString('application/ld+json', $html);

        // Header (bar and phone menu) and footer of every public page link to it.
        $this->assertSame(3, substr_count($this->get(route('about'))->getContent(), 'href="'.route('templates').'"'));

        $this->get('/sitemap.xml')->assertOk()->assertSee('<loc>'.route('templates').'</loc>', false);
        $this->get('/robots.txt')->assertOk()
            ->assertSee('Allow: /templates$')
            ->assertSee('Disallow: /templates/');
    }

    public function test_pictures_are_served_with_a_long_cache_and_not_counted(): void
    {
        $this->withTemplates();

        $response = $this->get(route('templates.image', ['file' => 'scoping', 'v' => 'x']))->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
        $this->get(route('templates.image', ['file' => 'risk']))->assertNotFound();
        $this->get(route('templates.image', ['file' => 'nothing']))->assertNotFound();

        $this->assertSame(0, DB::table('request_logs')->where('route', 'templates.image')->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'usage')->count());
    }

    public function test_each_workbook_downloads_with_its_original_name(): void
    {
        $this->withTemplates();

        foreach (self::FILES as $key => $name) {
            $response = $this->download($key)->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->assertDownload($name);
            $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
            $this->assertSame($this->dir.'/pack/templates/free/'.$name, $response->baseResponse->getFile()->getPathname());
            $this->travel(31)->seconds();
        }
    }

    public function test_download_all_is_a_zip_built_once(): void
    {
        $this->withTemplates();

        $response = $this->download('all')->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertDownload('Example templates v1.0.zip');
        $path = $response->baseResponse->getFile()->getPathname();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertSame(array_values(self::FILES), $names);
        $this->assertSame('workbook risk '.str_repeat('x', 3000), $zip->getFromName(self::FILES['risk']));
        $zip->close();

        // Cached: the second download serves the same archive, untouched.
        $mtime = filemtime($path);
        $this->assertSame($path, $this->download('all')->baseResponse->getFile()->getPathname());
        $this->assertSame($mtime, filemtime($path));
    }

    public function test_an_unknown_or_refused_key_is_not_found(): void
    {
        $this->withTemplates();

        $this->download('nothing')->assertNotFound();
        $this->download('evil')->assertNotFound();
        $this->download('gone')->assertNotFound();
        $this->get('/templates/..%2Fpack.json')->assertNotFound();
    }

    public function test_the_fifth_download_within_30_seconds_is_refused(): void
    {
        $this->withTemplates();

        for ($i = 0; $i < TemplateController::PER_WINDOW; $i++) {
            $this->download('scoping')->assertOk();
        }
        $this->download('risk')->assertStatus(429)->assertHeader('Retry-After');

        $this->travel(TemplateController::WINDOW_SECONDS + 1)->seconds();
        $this->download('risk')->assertOk();
    }

    public function test_crawlers_are_refused(): void
    {
        $this->withTemplates();

        foreach (['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Scrapy/2.11 (+https://scrapy.org)', 'Some Crawler 1.0'] as $agent) {
            $this->download('scoping', $agent)->assertForbidden();
        }
        $this->assertSame(0, Activity::query()->where('log_name', 'usage')->count());
    }

    public function test_each_download_is_counted_and_shown_in_the_console(): void
    {
        $this->withTemplates();

        $this->download('scoping')->assertOk();
        $this->download('scoping')->assertOk();
        $this->download('all')->assertOk();

        $this->assertSame(3, Activity::query()->where('log_name', 'usage')->where('description', 'template download')->count());
        $this->assertSame(
            ['scoping' => 2, 'all' => 1],
            Activity::query()->where('log_name', 'usage')->get()->countBy(fn (Activity $a) => $a->properties['file'] ?? null)->all(),
        );

        $this->signIn($this->staff())->get(route('admin.usage.index'))->assertOk()
            ->assertSee(__('templates.admin.title'))
            ->assertSeeInOrder([__('templates.files.scoping.name'), '2', __('templates.admin.all'), '1']);
    }

    public function test_the_old_download_links_redirect(): void
    {
        $this->get('/dl/?file=edt')->assertRedirect(route('templates.download', ['file' => 'edt']))->assertStatus(301);
        $this->get('/dl/')->assertRedirect(route('templates.download', ['file' => 'all']));
        $this->get('/dl/?file=../x')->assertRedirect(route('templates'));
    }

    public function test_without_workbooks_in_the_pack_the_page_says_so(): void
    {
        $this->get(route('templates'))->assertOk()
            ->assertSee(__('templates.none'))
            ->assertDontSee(__('templates.all'))
            ->assertSee('data-sandbox-open', false);

        $this->download('scoping')->assertNotFound();
        $this->download('all')->assertNotFound();
    }
}
