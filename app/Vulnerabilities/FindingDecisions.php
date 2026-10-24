<?php

namespace App\Vulnerabilities;

use App\Assessment\InventoryFields;
use App\Content\ContentPack;
use App\Models\InventoryItem;
use App\Models\InventoryLink;
use App\Models\ProductVersion;
use App\Models\User;
use App\Models\VulnerabilityFinding;
use App\Sbom\Identifiers;
use App\Sbom\SbomFeature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What people decide about findings: add them to the vulnerabilities list (one object per
 * vulnerability, linked to every component it was found in), set them aside, or reopen them.
 */
final class FindingDecisions
{
    public function __construct(private readonly ContentPack $pack) {}

    /**
     * @param  Collection<int, VulnerabilityFinding>  $findings
     * @return array{created: int, linked: int}
     */
    public function add(ProductVersion $version, Collection $findings, User $user): array
    {
        $definition = $this->pack->lists()[SbomFeature::vulnerabilitiesList()];
        $relation = SbomFeature::relation();
        $result = ['created' => 0, 'linked' => 0];

        DB::transaction(function () use ($version, $findings, $user, $definition, $relation, &$result) {
            $existing = $version->inventoryItems()->where('list', $definition['key'])->get();
            $codes = $existing->pluck('code')->flip()->all();
            $position = (int) $existing->max('position');

            foreach ($findings->groupBy('key') as $key => $group) {
                /** @var VulnerabilityFinding $first */
                $first = $group->first();
                $item = $this->existingItem($version, $existing, $first);
                if ($item === null) {
                    $item = new InventoryItem([
                        'product_version_id' => $version->id,
                        'list' => $definition['key'],
                        'code' => $this->nextCode($definition, $codes),
                        'name' => mb_substr($first->title ? $key.' · '.$first->title : (string) $key, 0, 200),
                        'description' => $this->description($group),
                        'attributes' => InventoryFields::cast($definition, array_intersect_key([
                            'reference' => mb_substr(implode(', ', $first->ids()), 0, 500),
                            'source' => mb_substr(implode(' ', $this->urls($group)), 0, 500),
                        ], array_flip(array_column($definition['fields'] ?? [], 'key')))),
                        'position' => ++$position,
                        'updated_by' => $user->id,
                    ]);
                    Identifiers::put($item, [
                        'key' => $key,
                        'ids' => $first->ids(),
                        'cvss' => $group->max(fn (VulnerabilityFinding $f) => $f->score()),
                        'severity' => $first->severity,
                        'exploited' => $group->contains(fn (VulnerabilityFinding $f) => $f->exploited) ?: null,
                        'exploited_by' => $group->flatMap(fn (VulnerabilityFinding $f) => $f->exploited_by ?? [])->unique()->values()->all(),
                        'sources' => $group->flatMap(fn (VulnerabilityFinding $f) => $f->sources)->unique()->values()->all(),
                        'urls' => $group->reduce(fn (array $c, VulnerabilityFinding $f) => $c + ($f->urls ?? []), []),
                        'fixed' => $group->flatMap(fn (VulnerabilityFinding $f) => $f->fixed ?? [])->unique()->values()->all(),
                    ]);
                    $item->save();
                    $existing->push($item);
                    $result['created']++;
                }
                foreach ($group as $finding) {
                    $link = InventoryLink::firstOrCreate([
                        'product_version_id' => $version->id,
                        'relation' => $relation,
                        'from_item_id' => $item->id,
                        'to_item_id' => $finding->inventory_item_id,
                    ]);
                    if ($link->wasRecentlyCreated) {
                        $result['linked']++;
                    }
                    $finding->forceFill([
                        'status' => 'added',
                        'vulnerability_item_id' => $item->id,
                        'decided_by' => $user->id,
                        'decided_at' => now(),
                        'reason' => null,
                        'history' => self::history($finding, 'added', null, $user),
                    ])->save();
                }
            }
        });

        activity('sbom')->performedOn($version)->causedBy($user)
            ->withProperties(['findings' => $findings->pluck('key')->unique()->values()->all()] + $result)
            ->log('vulnerabilities added');

        return $result;
    }

    /** @param Collection<int, VulnerabilityFinding> $findings */
    /**
     * Set aside (with the reason, required) or reopen; the earlier decisions stay in the history.
     *
     * @param  Collection<int, VulnerabilityFinding>  $findings
     */
    public function setStatus(ProductVersion $version, Collection $findings, string $status, User $user, ?string $reason = null): int
    {
        foreach ($findings as $finding) {
            $finding->forceFill([
                'status' => $status,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'reason' => $status === 'dismissed' ? $reason : null,
                'history' => self::history($finding, $status === 'dismissed' ? 'dismissed' : 'reopened', $status === 'dismissed' ? $reason : null, $user),
            ])->save();
        }
        activity('sbom')->performedOn($version)->causedBy($user)
            ->withProperties(['findings' => $findings->pluck('key')->unique()->values()->all(), 'status' => $status, 'reason' => $reason])
            ->log($status === 'dismissed' ? 'vulnerabilities dismissed' : 'vulnerabilities reopened');

        return $findings->count();
    }

    /**
     * The vulnerability already in the list: added from another component, or entered by hand
     * with the same id in its identifier field.
     *
     * @param  Collection<int, InventoryItem>  $existing
     */
    private function existingItem(ProductVersion $version, Collection $existing, VulnerabilityFinding $finding): ?InventoryItem
    {
        $added = VulnerabilityFinding::query()->where('product_version_id', $version->id)->where('key', $finding->key)
            ->whereNotNull('vulnerability_item_id')->value('vulnerability_item_id');
        if ($added && ($item = $existing->firstWhere('id', $added))) {
            return $item;
        }
        $ids = array_map('strtoupper', $finding->ids());

        return $existing->first(function (InventoryItem $item) use ($ids) {
            if (in_array(strtoupper((string) Identifiers::get($item, 'key')), $ids, true)) {
                return true;
            }
            $text = strtoupper((string) $item->attr('reference').' '.$item->name);
            foreach ($ids as $id) {
                if (preg_match('/(?<![A-Z0-9-])'.preg_quote($id, '/').'(?![0-9])/', $text)) {
                    return true;
                }
            }

            return false;
        });
    }

    /** @param Collection<int, VulnerabilityFinding> $group */
    private function description(Collection $group): string
    {
        /** @var VulnerabilityFinding $first */
        $first = $group->first();
        $facts = [];
        $score = $group->max(fn (VulnerabilityFinding $f) => $f->score());
        if ($score !== null) {
            $facts[] = __('sbom.added.cvss', ['score' => number_format((float) $score, 1), 'severity' => __('sbom.severity.'.($first->severity ?? Cvss::severity((float) $score)))]);
        }
        $by = $group->flatMap(fn (VulnerabilityFinding $f) => $f->exploited_by ?? [])->unique();
        if ($group->contains(fn (VulnerabilityFinding $f) => $f->exploited)) {
            $facts[] = __('sbom.added.exploited', ['sources' => $by->map(fn ($s) => __('sbom.exploited_by.'.$s))->implode(', ')]);
        }
        $fixed = $group->flatMap(fn (VulnerabilityFinding $f) => $f->fixed ?? [])->unique();
        if ($fixed->isNotEmpty()) {
            $facts[] = __('sbom.added.fixed', ['versions' => $fixed->implode(', ')]);
        }
        $facts[] = __('sbom.added.found_by', ['sources' => $group->flatMap(fn (VulnerabilityFinding $f) => $f->sources)->unique()->map(fn ($s) => __('sbom.sources.'.$s.'.name'))->implode(', ')]);

        return mb_substr(trim(($first->description ?? '')."\n\n".implode(' · ', $facts)), 0, 10000);
    }

    /**
     * @param  Collection<int, VulnerabilityFinding>  $group
     * @return list<string>
     */
    private function urls(Collection $group): array
    {
        $urls = $group->reduce(fn (array $c, VulnerabilityFinding $f) => $c + ($f->urls ?? []), []);
        $ordered = [];
        foreach (['euvd', 'nvd', 'osv', 'kev'] as $source) {
            if (isset($urls[$source])) {
                $ordered[] = $urls[$source];
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, int>  $codes
     */
    private function nextCode(array $definition, array &$codes): string
    {
        $prefix = $definition['code_prefix'] ?? '';
        $n = count($codes) + 1;
        do {
            $code = $prefix.str_pad((string) $n++, 2, '0', STR_PAD_LEFT);
        } while (isset($codes[$code]));
        $codes[$code] = 1;

        return $code;
    }

    /**
     * The decisions so far plus this one.
     *
     * @return list<array{status: string, reason?: string|null, by?: int|null, by_name?: string|null, at: string}>
     */
    private static function history(VulnerabilityFinding $finding, string $status, ?string $reason, User $user): array
    {
        $history = $finding->history ?? [];
        $history[] = array_filter([
            'status' => $status,
            'reason' => $reason,
            'by' => $user->id,
            'by_name' => $user->name,
            'at' => now()->toIso8601String(),
        ], fn ($v) => $v !== null);

        return array_slice($history, -20);
    }
}
