<?php

namespace App\Vulnerabilities;

use App\Models\InventoryItem;
use App\Models\VulnerabilityCheck;
use App\Models\VulnerabilityFinding;
use App\Models\VulnerabilityScan;
use App\Sbom\Identifiers;
use App\Vulnerabilities\Sources\Euvd;
use App\Vulnerabilities\Sources\Kev;
use App\Vulnerabilities\Sources\Nvd;
use App\Vulnerabilities\Sources\Osv;
use App\Vulnerabilities\Sources\SourceException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Looks components up in the public databases and stores what they report as findings.
 *
 *   purl of an ecosystem OSV knows   → OSV.dev (exact)
 *   CPE 2.3                          → NVD, by CPE name (exact)
 *   name and version only            → EUVD product search, and NVD by CPE match string when
 *                                      an NVD API key is set (possible: a person confirms)
 * Every CVE found is then marked known exploited from CISA KEV and the EUVD (EU KEV,
 * "exploited since"), and given its EUVD id. A source that fails is reported and skipped:
 * the others still answer, and a later check fills the gap.
 */
final class Checker
{
    private Osv $osv;

    private Nvd $nvd;

    private Euvd $euvd;

    private Kev $kev;

    /** @var array<string, array{state: string, calls?: int, error?: string|null}> */
    private array $sources = [];

    private int $euvdLookups = 0;

    /** @var array<string, array{0: string|null, 1: bool}>|null */
    private ?array $cisa = null;

    /** @var array<string, array{0: string|null, 1: list<string>}>|null */
    private ?array $euKev = null;

    private float $deadline;

    public function __construct(?float $deadline = null)
    {
        $this->deadline = $deadline ?? microtime(true) + 3600;
        $this->osv = new Osv;
        $this->nvd = new Nvd(fn () => (int) floor($this->deadline - microtime(true)));
        $this->euvd = new Euvd;
        $this->kev = new Kev;
    }

    public static function fingerprint(InventoryItem $item): string
    {
        return sha1(implode('|', [
            mb_strtolower(trim((string) $item->name)),
            mb_strtolower(trim((string) $item->attr('version'))),
            (string) Identifiers::get($item, 'purl'),
            (string) Identifiers::get($item, 'cpe'),
        ]));
    }

    /** exact (purl OSV knows, or CPE), possible (name and version), or none (nothing to search with). */
    public static function matchKind(InventoryItem $item): string
    {
        if (Osv::supports(Identifiers::get($item, 'purl')) || Identifiers::get($item, 'cpe')) {
            return 'exact';
        }

        return filled($item->name) && filled($item->attr('version')) ? 'possible' : 'none';
    }

    /**
     * Check these components; stops early when time runs out or the NVD allowance is used up.
     *
     * @param  Collection<int, InventoryItem>  $items
     * @return int how many were checked
     */
    public function run(VulnerabilityCheck $check, Collection $items): int
    {
        $this->sources = $check->sources ?? [];
        $osvIds = $this->osvIds($items);

        $done = 0;
        foreach ($items as $item) {
            if (microtime(true) > $this->deadline) {
                break;
            }
            try {
                $found = $this->checkOne($item, $osvIds[$item->id] ?? null);
            } catch (SourceException $e) {
                if ($e->rateLimited && $e->source === 'nvd') {
                    $this->state('nvd', 'deferred');
                    break;
                }
                throw $e;
            }
            $check->found += $found;
            $done++;
        }
        $this->state('osv', null, $this->osv->calls);
        $this->state('nvd', null, $this->nvd->calls);
        $this->state('euvd', null, $this->euvd->calls);
        $this->state('kev', null, $this->kev->calls);
        $check->sources = $this->sources;

        return $done;
    }

    /**
     * One OSV batch for the components with a usable purl.
     *
     * @param  Collection<int, InventoryItem>  $items
     * @return array<string, list<string>>
     */
    private function osvIds(Collection $items): array
    {
        $packages = [];
        foreach ($items as $item) {
            $purl = Identifiers::get($item, 'purl');
            if (Osv::supports($purl)) {
                $version = $item->attr('version');
                $packages[$item->id] = ['purl' => (string) $purl, 'version' => is_string($version) && $version !== '' ? $version : null];
            }
        }
        if ($packages === []) {
            return [];
        }
        try {
            $ids = $this->osv->idsFor($packages);
            $this->state('osv', 'ok');

            return $ids;
        } catch (SourceException $e) {
            $this->state('osv', 'error', null, $e->getMessage());

            return [];
        }
    }

    /**
     * @param  list<string>|null  $osvIds
     */
    private function checkOne(InventoryItem $item, ?array $osvIds): int
    {
        $purl = Identifiers::get($item, 'purl');
        $cpe = Identifiers::get($item, 'cpe');
        $version = trim((string) $item->attr('version'));
        $version = $version === '' ? null : $version;
        $match = self::matchKind($item);
        $failed = [];
        /** @var list<Hit> $hits */
        $hits = [];

        if (is_string($purl) && Osv::supports($purl) && $osvIds !== null) {
            foreach ($osvIds as $id) {
                try {
                    $hit = Osv::hit($this->osv->details($id), $purl, Identifiers::versionFromPurl($purl) ?? $version);
                    if ($hit) {
                        $hits[] = $hit;
                    }
                } catch (SourceException $e) {
                    $failed['osv'] = $e->getMessage();
                    $this->state('osv', 'error', null, $e->getMessage());
                    break;
                }
            }
        } elseif (is_string($purl) && Osv::supports($purl)) {
            $failed['osv'] = 'unavailable';
        }

        if (is_string($cpe)) {
            $hits = array_merge($hits, $this->guard('nvd', $failed, fn () => $this->nvd->byCpe($cpe, $version) ?? []));
        }

        if ($match === 'possible' && $version !== null) {
            $name = (string) $item->name;
            $supplier = $item->attr('reference');
            $hits = array_merge($hits, $this->guard('euvd', $failed, fn () => $this->euvd->byProduct($name, $version, is_string($supplier) ? $supplier : null)));
            if (Nvd::hasKey()) {
                $hits = array_merge($hits, $this->guard('nvd', $failed, fn () => $this->nvd->byName($name, $version) ?? []));
            }
        }

        $merged = $this->enrich($this->merge($hits), $failed);
        $this->store($item, $merged, $match, $failed);

        return count($merged);
    }

    /**
     * Runs one source call; a failure is recorded, the rest of the check goes on.
     *
     * @param  array<string, string>  $failed
     * @param  callable(): list<Hit>  $call
     * @return list<Hit>
     */
    private function guard(string $source, array &$failed, callable $call): array
    {
        try {
            $hits = $call();
            $this->state($source, ($this->sources[$source]['state'] ?? null) === 'error' ? 'error' : 'ok');

            return $hits;
        } catch (SourceException $e) {
            if ($e->rateLimited && $source === 'nvd') {
                throw $e;
            }
            $failed[$source] = $e->getMessage();
            $this->state($source, 'error', null, $e->getMessage());

            return [];
        }
    }

    /**
     * Hits about the same vulnerability (shared CVE or alias) become one.
     *
     * @param  list<Hit>  $hits
     * @return array<string, Hit>
     */
    private function merge(array $hits): array
    {
        /** @var array<string, Hit> $byKey */
        $byKey = [];
        foreach ($hits as $hit) {
            $target = null;
            foreach ($byKey as $existing) {
                if (array_intersect($existing->ids(), $hit->ids()) !== []) {
                    $target = $existing;
                    break;
                }
            }
            if ($target) {
                $old = $target->key();
                $target->merge($hit);
                if ($old !== $target->key()) {
                    unset($byKey[$old]);
                }
                $byKey[$target->key()] = $target;
            } else {
                $byKey[$hit->key()] = $hit;
            }
        }

        return $byKey;
    }

    /**
     * Known exploited (CISA KEV, EU KEV via the EUVD, EUVD "exploited since") and EUVD ids.
     *
     * @param  array<string, Hit>  $hits
     * @param  array<string, string>  $failed
     * @return array<string, Hit>
     */
    private function enrich(array $hits, array &$failed): array
    {
        if ($hits === []) {
            return $hits;
        }
        if ($this->cisa === null) {
            try {
                $this->cisa = $this->kev->catalogue();
                $this->state('kev', 'ok');
            } catch (SourceException $e) {
                $this->cisa = [];
                $this->state('kev', 'error', null, $e->getMessage());
            }
        }
        if ($this->euKev === null) {
            try {
                $this->euKev = $this->euvd->exploited();
            } catch (SourceException $e) {
                $this->euKev = [];
                $this->state('euvd', 'error', null, $e->getMessage());
            }
        }
        $max = (int) config('sbom.checks.max_euvd_lookups', 150);

        foreach ($hits as $hit) {
            $cve = $hit->cve();
            if ($cve === null) {
                continue;
            }
            if (isset($this->cisa[$cve])) {
                $hit->exploited = true;
                $hit->exploitedBy[] = 'cisa_kev';
                $hit->sources[] = 'kev';
                $hit->urls['kev'] = Hit::urlFor('kev', $cve);
            }
            if (isset($this->euKev[$cve])) {
                [$euvdId, $kevSources] = $this->euKev[$cve];
                $hit->exploited = true;
                $hit->exploitedBy = array_merge($hit->exploitedBy, $kevSources);
                if ($euvdId) {
                    $hit->aliases[] = $euvdId;
                    $hit->sources[] = 'euvd';
                    $hit->urls['euvd'] ??= Hit::urlFor('euvd', $euvdId);
                }
            }
            $hasEuvd = (bool) array_filter($hit->ids(), fn ($id) => str_starts_with($id, 'EUVD-'));
            if (! $hasEuvd && $this->euvdLookups < $max && ! isset($failed['euvd'])) {
                $this->euvdLookups++;
                try {
                    $record = $this->euvd->byCve($cve);
                    if ($record) {
                        $euvdHit = Euvd::hit($record, $hit->confidence);
                        if ($euvdHit) {
                            $euvdHit->confidence = $hit->confidence;
                            $hit->merge($euvdHit);
                        }
                    }
                } catch (SourceException $e) {
                    $failed['euvd'] = $e->getMessage();
                    $this->state('euvd', 'error', null, $e->getMessage());
                }
            }
            $hit->sources = array_values(array_unique($hit->sources));
            $hit->exploitedBy = array_values(array_unique($hit->exploitedBy));
            // The CVE becomes the id, every other id an alias.
            $key = $hit->key();
            $hit->aliases = array_values(array_diff($hit->ids(), [$key]));
            $hit->id = $key;
        }

        return $hits;
    }

    /**
     * @param  array<string, Hit>  $hits
     * @param  array<string, string>  $failed
     */
    private function store(InventoryItem $item, array $hits, string $match, array $failed): void
    {
        $now = now();
        $seen = [];
        foreach ($hits as $hit) {
            $key = mb_substr($hit->key(), 0, 80);
            $seen[] = $key;
            $finding = VulnerabilityFinding::firstOrNew(['inventory_item_id' => $item->id, 'key' => $key]);
            $finding->fill([
                'product_version_id' => $item->product_version_id,
                'aliases' => array_slice($hit->aliases, 0, 20),
                'title' => $hit->title !== null ? mb_substr($hit->title, 0, 300) : null,
                'description' => $hit->description,
                'cvss' => $hit->cvss,
                'severity' => $hit->severity,
                'exploited' => $hit->exploited,
                'exploited_by' => $hit->exploitedBy,
                'fixed' => array_slice($hit->fixed, 0, 10),
                'sources' => $hit->sources,
                'urls' => $hit->urls,
                'confidence' => $hit->confidence,
                'matched_on' => $hit->matchedOn !== null ? mb_substr($hit->matchedOn, 0, 300) : null,
                'published_on' => $hit->published,
                'last_seen_at' => $now,
            ]);
            if (! $finding->exists) {
                $finding->status = 'open';
            }
            try {
                $finding->save();
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Open findings the sources no longer report for this component (new version, fixed
        // record) go; decisions people took (added, dismissed) stay. Only when every source answered.
        if ($failed === []) {
            VulnerabilityFinding::query()->where('inventory_item_id', $item->id)->where('status', 'open')
                ->whereNotIn('key', $seen === [] ? [''] : $seen)->delete();
        }

        VulnerabilityScan::updateOrCreate(['inventory_item_id' => $item->id], [
            'product_version_id' => $item->product_version_id,
            'fingerprint' => self::fingerprint($item),
            'match' => $match,
            'sources' => $failed === [] ? null : $failed,
            'checked_at' => $now,
        ]);
    }

    private function state(string $source, ?string $state, ?int $calls = null, ?string $error = null): void
    {
        $current = $this->sources[$source] ?? ['state' => 'skipped', 'calls' => 0];
        if ($state !== null && ! ($current['state'] === 'error' && $state === 'ok')) {
            $current['state'] = $state;
        }
        if ($calls !== null) {
            $current['calls'] = (int) ($current['calls'] ?? 0) + $calls;
        }
        if ($error !== null) {
            $current['error'] = $error;
        }
        $this->sources[$source] = $current;
    }
}
