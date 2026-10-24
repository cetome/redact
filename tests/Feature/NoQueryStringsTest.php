<?php

namespace Tests\Feature;

use App\Assessment\Assessment;
use App\Assessment\ScopingForm;
use App\Auth\Altcha;
use App\Content\ContentPack;
use App\Enums\ProductRole;
use App\Mail\Auth\EmailChangedMail;
use App\Mail\Auth\PasskeyChangedMail;
use App\Mail\Auth\SignInMail;
use App\Mail\Auth\VerificationCodeMail;
use App\Mail\Contact\ContactMessageMail;
use App\Mail\OrganisationInvitation;
use App\Mail\ProductInvitation;
use App\Mail\ProductOwnershipTransferred;
use App\Mail\VersionSubmitted;
use App\Models\ContactMessage;
use App\Models\ProductVersion;
use App\Models\User;
use App\Support\PathSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Owner rule: no address the application makes carries a query string ("?q=…"): links, forms,
 * formaction, src, data-* addresses, redirects, e-mails, sitemap, security.txt. Addresses of other
 * sites may have one. The test crawls the application as a visitor, a sandbox guest, an owner, a
 * reviewer and cetome staff, following every link of ours, and reads the views, scripts and mails.
 * Runs on the fixture pack and on a real one (REDACT_TEST_PACK).
 */
class NoQueryStringsTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    /** Pages per role, at most: the crawl stops there (every kind of page is reached long before). */
    private const MAX_PAGES = 400;

    /** Never followed: files (and the counted free template downloads), sign-out, the dev sign-in, state-changing GETs. */
    private const SKIP = '#(\.(docx|xlsx|zip|csv|pdf|webp|png|svg|ico|js|css)$)|^/templates/.|^/dl|/export$|/_dev/|/logout|/build/|/welcome/passkey/skip|/login/link/|/altcha#';

    protected function setUp(): void
    {
        parent::setUp();

        $path = getenv('REDACT_TEST_PACK') ?: null;
        if ($path) {
            config(['redact.content_path' => realpath($path) ?: $path]);
            $this->app->forgetInstance(ContentPack::class);
        }
        if (empty(config('app.key'))) {
            config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        }
        config(['redact.guest.enabled' => true, 'redact.guest.per_ip_per_day' => 50]);
    }

    public function test_a_visitor_never_meets_a_query_string(): void
    {
        $visited = $this->crawl([
            route('home'), route('about'), route('pricing'), route('templates'), route('contact'),
            route('contact.topic', 'privacy'), route('legal.terms'), route('legal.privacy'),
            route('login'), route('register'), route('guest.start'), route('trees.index'),
        ]);
        $this->assertGreaterThan(8, $visited);

        foreach (['security.txt', 'sitemap', 'robots'] as $name) {
            $this->assertNoQuery((string) $this->get(route($name))->assertOk()->getContent(), route($name), text: true);
        }
    }

    public function test_an_owner_never_meets_a_query_string(): void
    {
        [$owner, $version] = $this->assessedProduct();
        $this->signIn($owner->fresh());

        $visited = $this->crawl([
            route('dashboard'), route('versions.show', $version), route('account.show'), route('trees.index'),
            route('organisations.team', $version->product->organisation), route('organisations.history', $version->product->organisation),
            route('billing.show', $version->product->organisation), route('versions.evaluate.trees', [$version, $this->standard($version)]),
        ]);
        $this->assertGreaterThan(40, $visited, 'The crawl should reach every step of the workflow.');
    }

    public function test_a_reviewer_and_staff_never_meet_a_query_string(): void
    {
        [$owner, $version] = $this->assessedProduct();
        $reviewer = $this->member($version->product, ProductRole::Reviewer);
        $this->provideUnits($version);
        $this->signIn($owner)->post(route('versions.submit', $version));

        $this->signIn($reviewer->fresh());
        $this->assertGreaterThan(10, $this->crawl([route('dashboard'), route('versions.show', $version), route('versions.review', $version)]));

        $staff = User::factory()->create(['name' => 'Staff Member']);
        $staff->assignRole(Role::findOrCreate('staff', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->signIn($staff->fresh(), 'passkey');
        $this->assertGreaterThan(10, $this->crawl([route('admin.dashboard'), route('versions.show', $version)]));
    }

    public function test_a_sandbox_guest_never_meets_a_query_string(): void
    {
        $this->app->instance(Altcha::class, new class extends Altcha
        {
            public function verify(mixed $payload): bool
            {
                return $payload === 'ok';
            }
        });
        $response = $this->post(route('guest.create'), ['altcha' => 'ok']);
        $this->assertNoQuery('', 'POST /try', $response);
        $guest = User::query()->where('is_guest', true)->latest('id')->firstOrFail();
        $this->post(route('products.store'), [
            'organisation_id' => $guest->organisations()->firstOrFail()->id, 'name' => 'Sandbox lock', 'version_label' => '1.0',
        ])->assertRedirect();

        $this->assertGreaterThan(10, $this->crawl([route('dashboard'), route('guest.claim')]));
    }

    /** @return iterable<string, array{0: string}> */
    public static function sources(): iterable
    {
        yield 'views' => ['resources/views'];
        yield 'scripts' => ['resources/js'];
        yield 'application' => ['app'];
        yield 'routes' => ['routes'];
    }

    /** No view, script or class writes a query string into an address of ours (other sites' may have one). */
    #[DataProvider('sources')]
    public function test_no_source_builds_a_query_string(string $dir): void
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! preg_match('/\.(php|js)$/', $file->getFilename())) {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                $code = preg_replace('#https?://(?!localhost|127\.0\.0\.1)[^\s\'"`<>]+#', '', $line);
                if (preg_match('#(\?[a-z_]+=|URLSearchParams|searchParams|withQueryString|->appends\(|fullUrlWithQuery|temporarySignedRoute|signedRoute)#', (string) $code)
                    && ! preg_match('#^\s*(//|\*|/\*|\{\{--|\#)#', $line)) {
                    $found[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($n + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $found, "Query strings built in:\n".implode("\n", $found));
    }

    public function test_the_mails_carry_no_query_string(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $version = $this->product($owner);
        $product = $version->product;
        $this->signIn($owner);

        // Invitations and "submitted for review" through the real flows.
        $this->post(route('team.invite', $product), ['email' => 'rev@example.test', 'role' => 'reviewer'])->assertSessionHasNoErrors();
        $this->post(route('organisations.team.invite', $product->organisation), [
            'invite_email' => 'new@example.test', 'organisation_role' => 'member', 'products' => [$product->id => 'contributor'],
        ])->assertSessionHasNoErrors();
        $this->member($product, ProductRole::Reviewer);
        $this->provideUnits($version);
        $this->post(route('versions.submit', $version));
        $sent = collect([ProductInvitation::class, OrganisationInvitation::class, VersionSubmitted::class])
            ->flatMap(fn (string $class) => [...Mail::queued($class), ...Mail::sent($class)]);
        $this->assertCount(3, $sent->map(fn (Mailable $m) => $m::class)->unique());

        $contact = ContactMessage::create(['name' => 'Jane', 'email' => 'jane@example.test', 'topic' => 'privacy', 'message' => 'Hello', 'locale' => 'en']);
        $mails = [
            ...$sent,
            new SignInMail('Ada', 'en', route('login.link', ['token' => str_repeat('a', 43)]), '123456'),
            new VerificationCodeMail('Ada', 'en', '123456', 'confirm'),
            new PasskeyChangedMail('Ada', 'en', 'Laptop', true),
            new EmailChangedMail('Ada', 'en', 'new@example.test'),
            new ProductOwnershipTransferred($product, $owner),
            new ContactMessageMail($contact),
        ];
        foreach ($mails as $mail) {
            $this->assertNoQuery($this->renderMail($mail), $mail::class, text: true);
        }
    }

    public function test_a_path_signature_is_checked_and_expires(): void
    {
        $expires = time() + 60;
        $signature = PathSignature::sign('dev-login', ['7', 'passkey'], $expires);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
        $this->assertTrue(PathSignature::valid('dev-login', ['7', 'passkey'], (string) $expires, $signature));
        $this->assertFalse(PathSignature::valid('dev-login', ['8', 'passkey'], (string) $expires, $signature), 'Another user.');
        $this->assertFalse(PathSignature::valid('dev-login', ['7', 'email-link'], (string) $expires, $signature), 'Another method.');
        $this->assertFalse(PathSignature::valid('other', ['7', 'passkey'], (string) $expires, $signature), 'Another purpose.');
        $this->assertFalse(PathSignature::valid('dev-login', ['7', 'passkey'], (string) ($expires + 1), $signature), 'Another expiry.');
        $past = time() - 1;
        $this->assertFalse(PathSignature::valid('dev-login', ['7', 'passkey'], (string) $past, PathSignature::sign('dev-login', ['7', 'passkey'], $past)), 'Expired.');
    }

    public function test_older_links_with_a_query_still_arrive(): void
    {
        $this->get('/contact?topic=privacy')->assertRedirect(route('contact.topic', 'privacy'))->assertStatus(301);
        $this->get('/dl?file=all')->assertStatus(301)->assertRedirect(route('templates.download', 'all'));
        $this->get(route('contact.topic', 'pricing'))->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('contact').'">', false)
            ->assertSee('<option value="pricing" selected', false);
    }

    /**
     * A product with something in every step: scoping answered, two objects per list, linked,
     * every tree walked once, a risk and an E.Info text.
     *
     * @return array{0: User, 1: ProductVersion}
     */
    private function assessedProduct(): array
    {
        $pack = app(ContentPack::class);
        $owner = $this->owner();
        $version = $this->product($owner);
        $this->signIn($owner);

        $answers = [];
        foreach (app(ScopingForm::class)->questions() as $key => $question) {
            $answers[$key] = ($question['type'] ?? 'boolean') === 'boolean' ? '1' : ($question['options'][0]['value'] ?? null);
        }
        $this->put(route('versions.scoping.update', $version), ['answers' => $answers])->assertSessionHasNoErrors();

        foreach ($pack->lists() as $key => $list) {
            foreach ([1, 2] as $n) {
                $attributes = [];
                foreach ($list['fields'] ?? [] as $field) {
                    $first = $field['options'][$n - 1]['value'] ?? $field['options'][0]['value'] ?? null;
                    $attributes[$field['key']] = match ($field['type']) {
                        'boolean' => '1',
                        'yesno' => 'yes',
                        'select' => $first,
                        'multiselect' => array_filter([$first]),
                        default => 'Sample '.$field['key'],
                    };
                }
                $this->post(route('versions.inventory.store', [$version, $key]), [
                    'code' => strtoupper(substr($key, 0, 3)).'-'.$n, 'name' => $key.' '.$n, 'attributes' => $attributes,
                ])->assertSessionHasNoErrors();
            }
        }
        foreach ($pack->relations() as $key => $relation) {
            $rows = $version->inventoryItems()->where('list', $relation['from'])->pluck('id')->all();
            $others = $version->inventoryItems()->where('list', $relation['to'])->pluck('id')->all();
            $this->put(route('versions.inventory.links', [$version, $key]), [
                'side' => 'from', 'rows' => $rows, 'links' => array_fill_keys($rows, $others),
            ]);
        }

        $assessment = Assessment::for($version->fresh());
        foreach ($assessment->standards() as $standard) {
            foreach ($assessment->applicableRequirements($standard) as $code => $requirement) {
                $tree = $pack->tree($standard, $code);
                foreach ($tree ? array_slice($assessment->targets($standard, $code), 0, 1) : [] as $item) {
                    $answers = [];
                    $node = $tree->node($tree->start());
                    while ($node['kind'] === 'decision') {
                        $branch = end($node['branches']);
                        $answers[] = $branch['label'];
                        $node = $tree->node($branch['to']);
                    }
                    $this->put(route('versions.evaluate.update', [$version, $standard, $code]), [
                        'target' => $item?->id ?? 'product', 'answers' => $answers, 'justification' => 'Crawl test.',
                    ]);
                }
            }
        }
        $this->post(route('versions.risks.store', $version), ['threat' => 'Stolen key', 'impact' => 3, 'likelihood' => 2])->assertSessionHasNoErrors();

        return [$owner, $version->fresh()];
    }

    private function standard(ProductVersion $version): string
    {
        return Assessment::for($version)->workingStandards()[0] ?? (string) array_key_first(app(ContentPack::class)->standards());
    }

    /**
     * Breadth-first over our own links from $seeds (GET only), checking every page and redirect.
     *
     * @param  list<string>  $seeds
     */
    private function crawl(array $seeds): int
    {
        $queue = $seeds;
        $seen = array_fill_keys($seeds, true);
        $visited = 0;
        while ($queue !== [] && $visited < self::MAX_PAGES) {
            $url = array_shift($queue);
            $response = $this->get($url);
            $visited++;
            $this->assertLessThan(500, $response->getStatusCode(), "GET {$url} failed: ".(($response->exception ?? null)?->getMessage() ?? ''));

            $html = $response->isRedirection() ? '' : (string) $response->getContent();
            $links = $this->assertNoQuery($html, $url, $response);
            if ($response->isRedirection()) {
                $links = [(string) $response->headers->get('Location')];
            }
            foreach ($links as $link) {
                $link = strtok($link, '#') ?: '';
                if ($link === '' || isset($seen[$link]) || preg_match(self::SKIP, (string) parse_url($link, PHP_URL_PATH)) === 1) {
                    continue;
                }
                $seen[$link] = true;
                $queue[] = $link;
            }
        }

        return $visited;
    }

    /**
     * Every address of ours in $html (attributes, or every line of a text file) and the Location
     * header: none carries a query string. Returns the hrefs of ours, to follow.
     *
     * @return list<string>
     */
    private function assertNoQuery(string $html, string $where, ?TestResponse $response = null, bool $text = false): array
    {
        $app = rtrim((string) config('app.url'), '/');
        $ours = fn (string $value) => str_starts_with($value, $app.'/') || $value === $app
            || (str_starts_with($value, '/') && ! str_starts_with($value, '//'));
        $bad = [];
        $follow = [];

        $location = $response?->headers->get('Location');
        if (is_string($location) && $ours($location) && str_contains($location, '?')) {
            $bad[] = 'Location: '.$location;
        }

        if ($text) {
            preg_match_all('#https?://[^\s<>"]+#', $html, $m);
            foreach ($m[0] as $value) {
                if ($ours($value) && str_contains($value, '?')) {
                    $bad[] = $value;
                }
            }
        } else {
            preg_match_all('/\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\')/', $html, $m, PREG_SET_ORDER);
            foreach ($m as $attribute) {
                $name = strtolower($attribute[1]);
                $value = html_entity_decode($attribute[3] !== '' ? $attribute[3] : ($attribute[4] ?? ''), ENT_QUOTES | ENT_HTML5);
                if (! $ours($value)) {
                    continue;
                }
                if (str_contains($value, '?')) {
                    $bad[] = $name.'="'.$value.'"';
                }
                if ($name === 'href') {
                    $follow[] = str_starts_with($value, '/') ? $app.$value : $value;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($bad)), "Query strings on {$where}");

        return $follow;
    }

    /** The HTML and the plain-text part. */
    private function renderMail(Mailable $mail): string
    {
        [$html, $text] = (fn () => $this->renderForAssertions())->call($mail);

        return $html."\n".$text;
    }
}
