<?php

namespace App\Vulnerabilities\Sources;

use App\Sbom\Identifiers;
use App\Vulnerabilities\Cvss;
use App\Vulnerabilities\Hit;
use App\Vulnerabilities\VersionRange;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * NIST National Vulnerability Database, CVE API 2.0: CVEs by CPE name (exact), or by a CPE
 * match string built from a product name and version (possible; only with an API key, the
 * keyless rate of 5 requests per 30 s is kept for exact matches).
 *
 * "This product uses the NVD API but is not endorsed or certified by the NVD."
 */
final class Nvd
{
    public int $calls = 0;

    /** @param Closure(): int $secondsLeft time left in the job, to decide between waiting and deferring */
    public function __construct(private readonly ?Closure $secondsLeft = null) {}

    public static function hasKey(): bool
    {
        return filled(config('sbom.sources.nvd.key'));
    }

    /**
     * CVEs that apply to this exact CPE (the component's version replaces a wildcard version).
     *
     * @return list<Hit>|null null when the CPE says nothing usable (no version)
     */
    public function byCpe(string $cpe, ?string $version): ?array
    {
        $parsed = Identifiers::parseCpe($cpe);
        if ($parsed === null) {
            return null;
        }
        if ($parsed['version'] === null) {
            if ($version === null || preg_match('/[\s:*?]/', $version)) {
                return null;
            }
            $parts = preg_split('/(?<!\\\\):/', $cpe) ?: [];
            $parts[5] = str_replace(':', '\\:', $version);
            $cpe = implode(':', $parts);
        }

        // isVulnerable: only CVEs where this CPE is the vulnerable one (not merely the platform it runs on).
        return $this->query(['cpeName' => $cpe, 'isVulnerable' => null, 'noRejected' => null], $cpe, 'exact', $parsed, $parsed['version'] ?? $version);
    }

    /**
     * Possible matches from a product name and version (any vendor, any part).
     *
     * @return list<Hit>|null
     */
    public function byName(string $name, string $version): ?array
    {
        $product = strtolower(trim((string) preg_replace('/[^A-Za-z0-9._\-]+/', '_', $name), '_'));
        $version = strtolower(trim($version));
        if ($product === '' || $version === '' || preg_match('/[\s:*?]/', $version)) {
            return null;
        }
        $match = "cpe:2.3:*:*:{$product}:{$version}";

        return $this->query(['virtualMatchString' => $match, 'noRejected' => null], $match, 'possible', ['vendor' => '*', 'product' => $product], $version);
    }

    /**
     * @param  array<string, string|null>  $params
     * @param  array{vendor: string, product: string}|array<string, mixed>  $cpe
     * @return list<Hit>
     */
    private function query(array $params, string $matchedOn, string $confidence, array $cpe, ?string $version = null): array
    {
        $ttl = now()->addDays(max(1, (int) config('sbom.checks.cache_days', 1)));
        $data = Cache::remember('vuln:nvd:'.sha1(json_encode($params) ?: ''), $ttl, function () use ($params) {
            $this->throttle();
            $this->calls++;
            // isVulnerable and noRejected are flags without a value.
            $query = http_build_query(array_filter($params, fn ($v) => $v !== null) + ['resultsPerPage' => 500]);
            foreach (array_keys(array_filter($params, fn ($v) => $v === null)) as $flag) {
                $query .= '&'.$flag;
            }
            $key = (string) config('sbom.sources.nvd.key');

            return Client::getJson('nvd', (string) config('sbom.sources.nvd.url').'?'.$query, [], $key !== '' ? ['apiKey' => $key] : []);
        });

        $hits = [];
        foreach ((array) ($data['vulnerabilities'] ?? []) as $entry) {
            $hit = is_array($entry) && is_array($entry['cve'] ?? null) ? self::hit($entry['cve'], $cpe, $confidence, $matchedOn, $version) : null;
            if ($hit) {
                $hits[] = $hit;
            }
        }

        return $hits;
    }

    /** Waits for a slot of the shared NVD allowance, or gives up (the job comes back later). */
    private function throttle(): void
    {
        $key = 'vuln:nvd-api';
        $max = max(1, (int) config('sbom.sources.nvd.per_30s', 5));
        while (RateLimiter::tooManyAttempts($key, $max)) {
            $wait = RateLimiter::availableIn($key) + 1;
            $left = $this->secondsLeft ? ($this->secondsLeft)() : 0;
            if ($wait > $left) {
                throw new SourceException('nvd', 'rate_limited', true);
            }
            Sleep::sleep($wait);
        }
        RateLimiter::hit($key, 30);
    }

    /**
     * @param  array<mixed>  $cve
     * @param  array<string, mixed>  $cpe
     */
    public static function hit(array $cve, array $cpe, string $confidence, string $matchedOn, ?string $version = null): ?Hit
    {
        $id = $cve['id'] ?? null;
        if (! is_string($id) || ($cve['vulnStatus'] ?? null) === 'Rejected') {
            return null;
        }
        $description = null;
        foreach ((array) ($cve['descriptions'] ?? []) as $d) {
            if (is_array($d) && ($d['lang'] ?? null) === 'en') {
                $description = (string) ($d['value'] ?? '');
            }
        }
        [$score, $severity] = self::score((array) ($cve['metrics'] ?? []));

        $fixed = [];
        $productSeen = false;
        $vendor = strtolower((string) ($cpe['vendor'] ?? '*'));
        $product = strtolower((string) ($cpe['product'] ?? ''));
        foreach ((array) ($cve['configurations'] ?? []) as $configuration) {
            foreach ((array) ($configuration['nodes'] ?? []) as $node) {
                foreach ((array) ($node['cpeMatch'] ?? []) as $match) {
                    $criteria = Identifiers::parseCpe((string) ($match['criteria'] ?? ''));
                    if (! $criteria || ! ($match['vulnerable'] ?? false) || strtolower($criteria['product']) !== $product
                        || ($vendor !== '*' && strtolower($criteria['vendor']) !== $vendor)) {
                        continue;
                    }
                    $productSeen = true;
                    $end = $match['versionEndExcluding'] ?? null;
                    if (is_string($end) && self::inRange($match, $version ?? $criteria['version'])) {
                        $fixed[] = $end;
                    }
                }
            }
        }
        if ($confidence === 'possible' && ! $productSeen) {
            return null;
        }
        $kev = is_string($cve['cisaExploitAdd'] ?? null);

        return new Hit(
            id: $id,
            description: $description !== null ? mb_substr($description, 0, 4000) : null,
            cvss: $score,
            severity: $severity,
            fixed: array_values(array_unique($fixed)),
            sources: ['nvd'],
            urls: ['nvd' => Hit::urlFor('nvd', $id)],
            confidence: $confidence,
            matchedOn: $matchedOn,
            published: is_string($cve['published'] ?? null) ? substr($cve['published'], 0, 10) : null,
            exploited: $kev,
            exploitedBy: $kev ? ['cisa_kev'] : [],
        );
    }

    /**
     * Is $version inside the range of this cpeMatch? Unknown version: yes (every fix is shown).
     *
     * @param  array<mixed>  $match
     */
    private static function inRange(array $match, ?string $version): bool
    {
        if ($version === null || $version === '') {
            return true;
        }
        $start = $match['versionStartIncluding'] ?? $match['versionStartExcluding'] ?? null;
        if (is_string($start)) {
            $cmp = VersionRange::compare($version, $start);
            if ($cmp < 0 || ($cmp === 0 && isset($match['versionStartExcluding']))) {
                return false;
            }
        }
        $end = $match['versionEndExcluding'] ?? null;

        return ! is_string($end) || VersionRange::compare($version, $end) < 0;
    }

    /**
     * Base score and severity: CVSS 3.1, else 4.0, 3.0, 2.0; the NVD's own (Primary) first.
     *
     * @param  array<mixed>  $metrics
     * @return array{0: float|null, 1: string|null}
     */
    private static function score(array $metrics): array
    {
        foreach (['cvssMetricV31', 'cvssMetricV40', 'cvssMetricV30', 'cvssMetricV2'] as $kind) {
            $list = array_values(array_filter((array) ($metrics[$kind] ?? []), 'is_array'));
            usort($list, fn ($a, $b) => (($a['type'] ?? '') === 'Primary' ? 0 : 1) <=> (($b['type'] ?? '') === 'Primary' ? 0 : 1));
            $metric = $list[0] ?? null;
            if ($metric && is_numeric($metric['cvssData']['baseScore'] ?? null)) {
                $score = (float) $metric['cvssData']['baseScore'];
                $severity = Cvss::normaliseSeverity($metric['cvssData']['baseSeverity'] ?? $metric['baseSeverity'] ?? null) ?? Cvss::severity($score);

                return [$score, $severity];
            }
        }

        return [null, null];
    }
}
