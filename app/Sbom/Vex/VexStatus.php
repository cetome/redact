<?php

namespace App\Sbom\Vex;

use App\Ai\Provenance;
use App\Enums\Origin;
use App\Models\InventoryItem;
use App\Models\VexStatement;
use App\Models\VulnerabilityFinding;
use Illuminate\Support\Collection;

/**
 * The VEX statements recorded for a version, for the pages that show them (vulnerabilities
 * page, Known vulnerabilities rows, the CSV of the technical file). One query per version and request.
 */
final class VexStatus
{
    public const TONES = ['not_affected' => 'a-badge-success', 'fixed' => 'a-badge-success', 'affected' => 'a-badge-danger', 'under_investigation' => 'a-badge-warning'];

    /** @return Collection<int, VexStatement> */
    public static function of(string $versionId): Collection
    {
        $attributes = app()->bound('request') ? request()->attributes : null;
        $key = 'vex-statements.'.$versionId;
        if ($attributes && $attributes->has($key)) {
            return $attributes->get($key);
        }
        $statements = VexStatement::query()->where('product_version_id', $versionId)
            ->with(['component:id,code,name', 'importer:id,name'])->orderBy('vulnerability_key')->get();
        $attributes?->set($key, $statements);

        return $statements;
    }

    /** @return Collection<int, VexStatement> */
    public static function forVulnerability(string $versionId, string $itemId): Collection
    {
        return self::of($versionId)->where('vulnerability_item_id', $itemId)->values();
    }

    /** The statement about this finding: same component, one id in common. */
    public static function forFinding(VulnerabilityFinding $finding): ?VexStatement
    {
        $ids = array_map('strtoupper', $finding->ids());

        return self::of($finding->product_version_id)->first(fn (VexStatement $s) => $s->inventory_item_id === $finding->inventory_item_id
            && array_intersect(array_map('strtoupper', $s->ids()), $ids) !== []);
    }

    /**
     * The line under a Known vulnerability's name when its description is the VEX text nobody
     * changed: the justification, detail and action only (the status is on the badge beside it).
     */
    public static function detailLine(InventoryItem $item): ?string
    {
        if (blank($item->description) || app(Provenance::class)->origin('inventory_item', $item->id, 'description', $item->description) !== Origin::FromVex) {
            return null;
        }
        $line = self::forVulnerability($item->product_version_id, $item->id)->map(fn (VexStatement $s) => $s->explanation())->filter()->unique()->implode(' · ');

        return $line === '' ? null : $line;
    }

    /** Forget the cached statements (after an import in the same request). */
    public static function forget(string $versionId): void
    {
        if (app()->bound('request')) {
            request()->attributes->remove('vex-statements.'.$versionId);
        }
    }
}
