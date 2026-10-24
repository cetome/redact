<?php

namespace App\Vulnerabilities\Sources;

use App\Vulnerabilities\Cvss;
use App\Vulnerabilities\Hit;
use App\Vulnerabilities\VersionRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * ENISA EU Vulnerability Database (EUVD, NIS2 article 12): public API, no key.
 *   /api/search?product=…&size=100   possible matches by product name, versions as free text
 *   /api/search?text=CVE-…           the EUVD record of a CVE (EUVD id, score, exploited since)
 *   /api/kev/dump                    known exploited: CISA KEV and the EU KEV, daily
 * "Source: ENISA EU Vulnerability Database" with a link, as the ENISA legal notice asks.
 */
final class Euvd
{
    private const PAGE = 100;

    private const MAX_PAGES = 3;

    public int $calls = 0;

    private function url(string $path): string
    {
        return rtrim((string) config('sbom.sources.euvd.url'), '/').$path;
    }

    private function ttl(): \DateTimeInterface
    {
        return now()->addDays(max(1, (int) config('sbom.checks.cache_days', 1)));
    }

    /**
     * Records whose product is this component, in a version the record says is affected.
     *
     * @return list<Hit>
     */
    public function byProduct(string $name, string $version, ?string $vendor = null): array
    {
        $wanted = self::normalise($name);
        if ($wanted === '') {
            return [];
        }
        $hits = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = Cache::remember('vuln:euvd:product:'.sha1($name.'|'.$page), $this->ttl(), function () use ($name, $page) {
                $this->calls++;

                return Client::getJson('euvd', $this->url('/search'), ['product' => mb_substr($name, 0, 100), 'size' => self::PAGE, 'page' => $page]);
            });
            $items = array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));
            foreach ($items as $item) {
                $hit = self::matchProduct($item, $wanted, $version, $vendor);
                if ($hit) {
                    $hits[] = $hit;
                }
            }
            if (count($items) < self::PAGE || ($page + 1) * self::PAGE >= (int) ($data['total'] ?? 0)) {
                break;
            }
        }

        return $hits;
    }

    /** @param array<mixed> $item */
    public static function matchProduct(array $item, string $wanted, string $version, ?string $vendor): ?Hit
    {
        $statements = [];
        foreach ((array) ($item['enisaIdProduct'] ?? []) as $product) {
            if (! is_array($product) || self::normalise((string) ($product['product']['name'] ?? '')) !== $wanted) {
                continue;
            }
            $statements[] = (string) ($product['product_version'] ?? '');
        }
        $covered = array_values(array_filter($statements, fn ($s) => VersionRange::covers($s, $version) === true));
        if ($covered === []) {
            return null;
        }
        $hit = self::hit($item, 'possible');
        if ($hit === null) {
            return null;
        }
        $hit->matchedOn = mb_substr(implode(' · ', $covered), 0, 300);
        $hit->fixed = array_values(array_unique(array_filter(array_map(fn ($s) => VersionRange::fixedIn($s), $covered))));

        return $hit;
    }

    /**
     * The EUVD record of a CVE (cached; null when the EUVD has none).
     *
     * @return array<mixed>|null
     */
    public function byCve(string $cve): ?array
    {
        $data = Cache::remember('vuln:euvd:cve:'.$cve, now()->addDays(7), function () use ($cve) {
            $this->calls++;

            return Client::getJson('euvd', $this->url('/search'), ['text' => $cve, 'size' => 5]);
        });
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (is_array($item) && in_array($cve, self::aliases($item), true)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Known exploited CVEs from the EUVD's KEV dump (CISA KEV and EU KEV): CVE => [euvd id, sources].
     *
     * @return array<string, array{0: string|null, 1: list<string>}>
     */
    public function exploited(): array
    {
        return Cache::remember('vuln:euvd:kev', now()->addHours(12), function () {
            $this->calls++;
            $data = Client::getJson('euvd', $this->url('/kev/dump'));
            $map = [];
            foreach ($data as $row) {
                if (is_array($row) && is_string($row['cveId'] ?? null)) {
                    $sources = array_map(fn ($s) => $s === 'eukev_kev' ? 'eu_kev' : (string) $s, array_filter((array) ($row['sources'] ?? []), 'is_string'));
                    $map[$row['cveId']] = [is_string($row['euvdId'] ?? null) ? $row['euvdId'] : null, array_values($sources)];
                }
            }

            return $map;
        });
    }

    /** @param array<mixed> $item */
    public static function hit(array $item, string $confidence): ?Hit
    {
        $id = $item['id'] ?? null;
        if (! is_string($id)) {
            return null;
        }
        $score = is_numeric($item['baseScore'] ?? null) && (float) $item['baseScore'] > 0 ? (float) $item['baseScore'] : null;
        $exploited = filled($item['exploitedSince'] ?? null);

        return new Hit(
            id: $id,
            aliases: self::aliases($item),
            description: is_string($item['description'] ?? null) ? mb_substr($item['description'], 0, 4000) : null,
            cvss: $score,
            severity: Cvss::severity($score),
            sources: ['euvd'],
            urls: ['euvd' => Hit::urlFor('euvd', $id)],
            confidence: $confidence,
            published: self::date($item['datePublished'] ?? null),
            exploited: $exploited,
            exploitedBy: $exploited ? ['euvd'] : [],
        );
    }

    /**
     * "GHSA-…\nCVE-…\n" → ["GHSA-…", "CVE-…"]
     *
     * @param  array<mixed>  $item
     * @return list<string>
     */
    public static function aliases(array $item): array
    {
        $raw = $item['aliases'] ?? '';
        $list = is_array($raw) ? $raw : preg_split('/\s+/', (string) $raw);

        return array_values(array_filter(array_map('trim', array_filter((array) $list, 'is_string')), fn ($a) => $a !== ''));
    }

    /** "Apr 16, 2018, 5:00:00 PM" → 2018-04-16 */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('M j, Y, g:i:s A', $value, 'UTC')?->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    public static function normalise(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($name)));
    }
}
