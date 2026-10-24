<?php

namespace App\Vulnerabilities\Sources;

use Illuminate\Support\Facades\Cache;

/**
 * CISA Known Exploited Vulnerabilities catalogue (CC0): one JSON file, cached 12 hours and
 * kept as a small CVE => [date added, ransomware use] map. Matched by CVE id only.
 */
final class Kev
{
    public int $calls = 0;

    /** @return array<string, array{0: string|null, 1: bool}> */
    public function catalogue(): array
    {
        return Cache::remember('vuln:cisa-kev', now()->addHours(12), function () {
            $this->calls++;
            $data = Client::getJson('kev', (string) config('sbom.sources.kev.url'));
            $map = [];
            foreach ((array) ($data['vulnerabilities'] ?? []) as $row) {
                if (is_array($row) && is_string($row['cveID'] ?? null)) {
                    $map[$row['cveID']] = [
                        is_string($row['dateAdded'] ?? null) ? $row['dateAdded'] : null,
                        ($row['knownRansomwareCampaignUse'] ?? null) === 'Known',
                    ];
                }
            }

            return $map;
        });
    }
}
