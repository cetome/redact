<?php

namespace Tests\Feature\Sbom;

use App\Content\ContentPack;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Bills of materials and vulnerability checks run on the fixture pack plus an invented
 * component list, vulnerability list and link between them (the fixture pack has neither).
 * No real network: every source is faked, stray requests fail the test.
 */
abstract class SbomTestCase extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    protected string $packPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packPath = $this->packWithComponents();
        $this->app->instance(ContentPack::class, new ContentPack($this->packPath));
        Http::preventStrayRequests();
        Sleep::fake();
        Storage::fake('local');
        config(['sbom.sources.nvd.per_30s' => 100, 'sbom.checks.enabled' => true, 'sbom.checks.auto' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packPath);
        parent::tearDown();
    }

    /** A copy of the fixture pack with a component list, a vulnerability list and their link. */
    private function packWithComponents(): string
    {
        $dir = storage_path('framework/testing/pack-'.uniqid());
        File::copyDirectory(base_path('tests/Fixtures/content-pack'), $dir);
        $lists = json_decode((string) file_get_contents($dir.'/lists.json'), true);
        $lists['lists'][] = [
            'key' => 'software', 'label' => 'Parts', 'singular' => 'Part', 'code_prefix' => 'SW-',
            'fields' => [
                ['key' => 'type', 'label' => 'Kind of part', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'software', 'label' => 'Software'], ['value' => 'hardware', 'label' => 'Hardware'],
                ]],
                ['key' => 'version', 'label' => 'Version', 'type' => 'text'],
                ['key' => 'reference', 'label' => 'Maker', 'type' => 'text'],
            ],
        ];
        $lists['lists'][] = [
            'key' => 'vulnerabilities', 'label' => 'Weaknesses', 'singular' => 'Weakness', 'code_prefix' => 'VU-',
            'fields' => [
                ['key' => 'reference', 'label' => 'Id', 'type' => 'text'],
                ['key' => 'source', 'label' => 'Where', 'type' => 'text'],
            ],
        ];
        $lists['relations'][] = ['key' => 'vulnerability_component', 'from' => 'vulnerabilities', 'to' => 'software', 'label' => 'In'];
        file_put_contents($dir.'/lists.json', json_encode($lists, JSON_PRETTY_PRINT));

        return $dir;
    }

    /** @return array{0: User, 1: ProductVersion} */
    protected function ownerWithVersion(): array
    {
        $owner = $this->owner();

        return [$owner, $this->product($owner)];
    }

    protected function fixture(string $name, ?string $as = null): UploadedFile
    {
        $path = base_path('tests/Fixtures/sbom/'.$name);
        $copy = sys_get_temp_dir().'/'.uniqid('sbom').'-'.basename($as ?? $name);
        copy($path, $copy);

        return new UploadedFile($copy, $as ?? basename($name), null, null, true);
    }

    protected function upload(User $user, ProductVersion $version, UploadedFile ...$files): TestResponse
    {
        return $this->signIn($user)->post(route('versions.sbom.store', $version), ['files' => $files]);
    }

    /**
     * Fake answers of the four sources.
     *   lodash 4.17.20 (purl)      → OSV GHSA-29mw-wpgm-hmr9 / CVE-2020-28500, in CISA KEV (for the test)
     *   openssl 3.0.7 (CPE)        → NVD CVE-2023-0286 (7.4 high), EU KEV per the EUVD dump
     *   Example RTOS 10.5.1 (name) → EUVD EUVD-2025-1111 / CVE-2025-1111, exploited since 2025
     *
     * @param  array<string, int>  $fail  source => HTTP status to answer instead
     */
    protected function fakeSources(array $fail = []): void
    {
        Http::fake(function (Request $request) use ($fail) {
            $url = $request->url();
            $source = match (true) {
                str_contains($url, 'api.osv.dev') => 'osv',
                str_contains($url, 'services.nvd.nist.gov') => 'nvd',
                str_contains($url, 'euvdservices.enisa.europa.eu') => 'euvd',
                str_contains($url, 'cisa.gov') => 'kev',
                default => 'other',
            };
            if (isset($fail[$source])) {
                return Http::response(['error' => 'down'], $fail[$source]);
            }

            if (str_contains($url, '/v1/querybatch')) {
                $results = array_map(fn ($q) => str_contains((string) ($q['package']['purl'] ?? ''), 'lodash')
                    ? ['vulns' => [['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]
                    : (object) [], $request->data()['queries'] ?? []);

                return Http::response(['results' => $results]);
            }
            if (str_contains($url, '/v1/vulns/GHSA-29mw-wpgm-hmr9')) {
                return Http::response([
                    'id' => 'GHSA-29mw-wpgm-hmr9', 'aliases' => ['CVE-2020-28500'], 'summary' => 'Regular Expression Denial of Service (ReDoS) in lodash',
                    'details' => 'lodash versions prior to 4.17.21 are vulnerable to ReDoS via the toNumber, trim and trimEnd functions.',
                    'published' => '2022-01-06T20:30:46Z', 'database_specific' => ['severity' => 'MODERATE'],
                    'severity' => [['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:L']],
                    'affected' => [['package' => ['name' => 'lodash', 'ecosystem' => 'npm', 'purl' => 'pkg:npm/lodash'],
                        'ranges' => [['type' => 'SEMVER', 'events' => [['introduced' => '4.0.0'], ['fixed' => '4.17.21']]]]]],
                    'references' => [['type' => 'ADVISORY', 'url' => 'https://nvd.nist.gov/vuln/detail/CVE-2020-28500']],
                ]);
            }
            if ($source === 'nvd') {
                $vulnerabilities = str_contains(urldecode($url), 'openssl:openssl:3.0.7') ? [['cve' => [
                    'id' => 'CVE-2023-0286', 'published' => '2023-02-08T20:15:23.887', 'vulnStatus' => 'Analyzed',
                    'descriptions' => [['lang' => 'en', 'value' => 'There is a type confusion vulnerability relating to X.400 address processing inside an X.509 GeneralName.']],
                    'metrics' => ['cvssMetricV31' => [['source' => 'nvd@nist.gov', 'type' => 'Primary', 'cvssData' => ['baseScore' => 7.4, 'baseSeverity' => 'HIGH']]]],
                    'configurations' => [['nodes' => [['operator' => 'OR', 'cpeMatch' => [
                        ['vulnerable' => true, 'criteria' => 'cpe:2.3:a:openssl:openssl:*:*:*:*:*:*:*:*', 'versionStartIncluding' => '1.1.1', 'versionEndExcluding' => '1.1.1t'],
                        ['vulnerable' => true, 'criteria' => 'cpe:2.3:a:openssl:openssl:*:*:*:*:*:*:*:*', 'versionStartIncluding' => '3.0.0', 'versionEndExcluding' => '3.0.8'],
                    ]]]]],
                ]]] : [];

                return Http::response(['resultsPerPage' => count($vulnerabilities), 'startIndex' => 0, 'totalResults' => count($vulnerabilities), 'vulnerabilities' => $vulnerabilities]);
            }
            if (str_contains($url, '/api/kev/dump')) {
                return Http::response([['cveId' => 'CVE-2023-0286', 'euvdId' => 'EUVD-2023-0286', 'dateAdded' => '2025-02-01', 'sources' => ['eukev_kev']]]);
            }
            if (str_contains($url, '/api/search')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                if (($query['product'] ?? null) === 'Example RTOS') {
                    return Http::response(['total' => 2, 'items' => [
                        ['id' => 'EUVD-2025-1111', 'description' => 'Heap overflow in the TCP/IP stack of Example RTOS.', 'datePublished' => 'Mar 3, 2025, 10:00:00 AM',
                            'baseScore' => 9.8, 'baseScoreVersion' => '3.1', 'aliases' => "CVE-2025-1111\n", 'exploitedSince' => 'Apr 1, 2025, 12:00:00 AM',
                            'enisaIdProduct' => [['product' => ['name' => 'Example RTOS', 'vendor' => ['name' => 'Example']], 'product_version' => '10.0.0 <10.6.0']]],
                        ['id' => 'EUVD-2024-2222', 'description' => 'Old issue fixed long ago.', 'baseScore' => 5.0, 'aliases' => "CVE-2024-2222\n",
                            'enisaIdProduct' => [['product' => ['name' => 'Example RTOS'], 'product_version' => '<9.0.0']]],
                    ]]);
                }
                if (($query['text'] ?? null) === 'CVE-2020-28500') {
                    return Http::response(['total' => 1, 'items' => [['id' => 'EUVD-2020-9999', 'aliases' => "GHSA-29mw-wpgm-hmr9\nCVE-2020-28500\n", 'baseScore' => 5.3, 'description' => 'ReDoS in lodash.']]]);
                }

                return Http::response(['total' => 0, 'items' => []]);
            }
            if ($source === 'kev') {
                return Http::response(['catalogVersion' => '2026.10.21', 'count' => 1, 'vulnerabilities' => [
                    ['cveID' => 'CVE-2020-28500', 'vendorProject' => 'Lodash', 'product' => 'lodash', 'dateAdded' => '2026-01-01', 'knownRansomwareCampaignUse' => 'Unknown'],
                ]]);
            }

            return Http::response('unexpected', 500);
        });
    }
}
