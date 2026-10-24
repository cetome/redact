<?php

namespace App\Vulnerabilities;

use App\Models\VulnerabilityFinding;
use App\Models\VulnerabilityScan;
use Illuminate\Support\Carbon;

/**
 * The small vulnerability status of each component, for the rows of the component list:
 * when it was last looked up, how many findings wait for a decision, how many are in the
 * vulnerabilities list, how many were set aside, known exploited.
 * One query per version and request.
 */
final class ComponentStatus
{
    /** @return array{checked_at: Carbon|null, match: string|null, failed: bool, open: int, added: int, dismissed: int, exploited: bool}|null */
    public static function of(string $versionId, string $itemId): ?array
    {
        // Kept on the request, so a page with many rows runs the queries once.
        $attributes = request()->attributes;
        $key = 'vulnerability-status.'.$versionId;
        if (! $attributes->has($key)) {
            $attributes->set($key, self::load($versionId));
        }

        return $attributes->get($key)[$itemId] ?? null;
    }

    /** @return array<string, array{checked_at: Carbon|null, match: string|null, failed: bool, open: int, added: int, dismissed: int, exploited: bool}> */
    private static function load(string $versionId): array
    {
        $out = [];
        foreach (VulnerabilityScan::query()->where('product_version_id', $versionId)->get() as $scan) {
            $out[$scan->inventory_item_id] = [
                'checked_at' => $scan->checked_at, 'match' => $scan->match, 'failed' => ! empty($scan->sources),
                'open' => 0, 'added' => 0, 'dismissed' => 0, 'exploited' => false,
            ];
        }
        $findings = VulnerabilityFinding::query()->where('product_version_id', $versionId)
            ->get(['inventory_item_id', 'status', 'exploited']);
        foreach ($findings as $f) {
            $row = $out[$f->inventory_item_id] ?? ['checked_at' => null, 'match' => null, 'failed' => false, 'open' => 0, 'added' => 0, 'dismissed' => 0, 'exploited' => false];
            match ($f->status) {
                'added' => $row['added']++,
                'dismissed' => $row['dismissed']++,
                default => $row['open']++,
            };
            // Known exploited counts only while the finding is not set aside.
            $row['exploited'] = $row['exploited'] || ($f->exploited && $f->status !== 'dismissed');
            $out[$f->inventory_item_id] = $row;
        }

        return $out;
    }
}
