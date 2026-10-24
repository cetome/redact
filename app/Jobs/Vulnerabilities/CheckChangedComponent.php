<?php

namespace App\Jobs\Vulnerabilities;

use App\Models\InventoryItem;
use App\Vulnerabilities\Checker;
use App\Vulnerabilities\VulnerabilityChecks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The automatic look-up of a component someone created or renamed by hand, queued a minute
 * after the change (debounce): if it changed again meanwhile, the newer job does the work.
 */
class CheckChangedComponent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $itemId, public readonly string $fingerprint) {}

    public function handle(): void
    {
        $item = InventoryItem::with('version')->find($this->itemId);
        if (! $item || ! $item->version || Checker::fingerprint($item) !== $this->fingerprint || ! VulnerabilityChecks::isStale($item)) {
            return;
        }
        if (! config('sbom.checks.enabled') || Checker::matchKind($item) === 'none') {
            return;
        }
        VulnerabilityChecks::start($item->version, null, 'auto', [$item->id]);
    }
}
