<?php

namespace App\Vulnerabilities\Sources;

use App\Sbom\Identifiers;
use App\Vulnerabilities\Cvss;
use App\Vulnerabilities\Hit;
use App\Vulnerabilities\VersionRange;
use Illuminate\Support\Facades\Cache;

/**
 * OSV.dev (Google, open source): vulnerabilities of open-source packages by package URL.
 * POST /v1/querybatch (up to 1000 queries, ids only), then GET /v1/vulns/{id} for the details
 * (cached). No key, no published rate limit. Data licences per upstream database.
 */
final class Osv
{
    /** purl types OSV knows (others, e.g. pkg:generic, go to the name-and-version search). */
    public const PURL_TYPES = ['npm', 'pypi', 'maven', 'golang', 'cargo', 'nuget', 'gem', 'composer', 'hex', 'pub', 'deb', 'apk', 'rpm',
        'cran', 'swift', 'hackage', 'conan', 'alpm', 'bitnami', 'github', 'cocoapods'];

    private const BATCH = 500;

    public int $calls = 0;

    public static function supports(?string $purl): bool
    {
        if ($purl === null) {
            return false;
        }
        $parsed = Identifiers::parsePurl($purl);

        return $parsed !== null && in_array($parsed['type'], self::PURL_TYPES, true);
    }

    /**
     * Vulnerability ids per key.
     *
     * @param  array<string, array{purl: string, version: string|null}>  $packages
     * @return array<string, list<string>>
     */
    public function idsFor(array $packages): array
    {
        $out = [];
        foreach (array_chunk($packages, self::BATCH, true) as $chunk) {
            $keys = array_keys($chunk);
            $queries = [];
            foreach ($chunk as $package) {
                $hasVersion = Identifiers::versionFromPurl($package['purl']) !== null;
                $queries[] = $hasVersion || $package['version'] === null
                    ? ['package' => ['purl' => $package['purl']]]
                    : ['package' => ['purl' => $package['purl']], 'version' => $package['version']];
            }
            $this->calls++;
            $data = Client::postJson('osv', rtrim((string) config('sbom.sources.osv.url'), '/').'/v1/querybatch', ['queries' => $queries]);
            foreach ((array) ($data['results'] ?? []) as $i => $result) {
                $key = $keys[$i] ?? null;
                if ($key === null || ! is_array($result)) {
                    continue;
                }
                $out[$key] = array_values(array_filter(array_map(fn ($v) => is_array($v) ? ($v['id'] ?? null) : null, (array) ($result['vulns'] ?? [])), 'is_string'));
            }
        }

        return $out;
    }

    /** @return array<mixed> the full OSV record (cached a day) */
    public function details(string $id): array
    {
        $ttl = now()->addDays(max(1, (int) config('sbom.checks.cache_days', 1)));

        return Cache::remember('vuln:osv:'.$id, $ttl, function () use ($id) {
            $this->calls++;

            return Client::getJson('osv', rtrim((string) config('sbom.sources.osv.url'), '/').'/v1/vulns/'.rawurlencode($id));
        });
    }

    /** @param array<mixed> $record */
    public static function hit(array $record, string $purl, ?string $version = null): ?Hit
    {
        $id = $record['id'] ?? null;
        if (! is_string($id) || isset($record['withdrawn'])) {
            return null;
        }
        $score = null;
        foreach ((array) ($record['severity'] ?? []) as $severity) {
            if (is_array($severity) && in_array($severity['type'] ?? null, ['CVSS_V3'], true)) {
                $score = Cvss::scoreV3((string) ($severity['score'] ?? ''));
            }
        }
        $label = Cvss::normaliseSeverity($record['database_specific']['severity'] ?? null);

        $base = Identifiers::purlBase($purl);
        $fixed = [];
        foreach ((array) ($record['affected'] ?? []) as $affected) {
            $affectedPurl = is_array($affected) ? ($affected['package']['purl'] ?? null) : null;
            if (! is_string($affectedPurl) || Identifiers::purlBase($affectedPurl) !== $base) {
                continue;
            }
            foreach ((array) ($affected['ranges'] ?? []) as $range) {
                if (! is_array($range) || ($range['type'] ?? null) === 'GIT') {
                    continue;
                }
                // introduced → fixed pairs: keep the fix of the branch this version is on.
                $introduced = null;
                foreach ((array) ($range['events'] ?? []) as $event) {
                    if (! is_array($event)) {
                        continue;
                    }
                    if (is_string($event['introduced'] ?? null)) {
                        $introduced = $event['introduced'];
                    }
                    if (is_string($event['fixed'] ?? null)) {
                        $inBranch = $version === null
                            || ((in_array($introduced, [null, '0'], true) || VersionRange::compare($version, (string) $introduced) >= 0)
                                && VersionRange::compare($version, $event['fixed']) < 0);
                        if ($inBranch) {
                            $fixed[] = $event['fixed'];
                        }
                    }
                }
            }
        }
        $aliases = array_values(array_filter((array) ($record['aliases'] ?? []), 'is_string'));
        $hit = new Hit(
            id: $id,
            aliases: $aliases,
            title: is_string($record['summary'] ?? null) ? $record['summary'] : null,
            description: is_string($record['details'] ?? null) ? mb_substr($record['details'], 0, 4000) : null,
            cvss: $score,
            severity: $label ?? Cvss::severity($score),
            fixed: array_values(array_unique($fixed)),
            sources: ['osv'],
            urls: ['osv' => Hit::urlFor('osv', $id)],
            confidence: 'exact',
            matchedOn: $purl,
            published: is_string($record['published'] ?? null) ? substr($record['published'], 0, 10) : null,
        );

        return $hit;
    }
}
