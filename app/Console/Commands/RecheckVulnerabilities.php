<?php

namespace App\Console\Commands;

use App\Enums\VersionStatus;
use App\Models\ProductVersion;
use App\Sbom\SbomFeature;
use App\Vulnerabilities\Checker;
use App\Vulnerabilities\VulnerabilityChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Weekly re-check of known vulnerabilities (REDACT_VULN_WEEKLY=true): every version still in
 * work (draft or in review, not approved or archived, not a guest sandbox) that has components
 * to look up gets a check, queued like a manual one. New findings are shown on the results page
 * ("New since last week"); nothing is added to the vulnerabilities list without a person.
 */
#[Signature('redact:recheck-vulnerabilities {--dry-run : List the versions without queuing checks}')]
#[Description('Queue a known-vulnerability check of every version in work')]
class RecheckVulnerabilities extends Command
{
    public function handle(): int
    {
        if (! config('sbom.checks.enabled') || ! SbomFeature::checkable()) {
            $this->info('Vulnerability checks are switched off or the content pack has no vulnerability list.');

            return self::SUCCESS;
        }

        $queued = 0;
        ProductVersion::query()
            ->whereIn('status', [VersionStatus::Draft->value, VersionStatus::InReview->value])
            ->whereHas('product', fn ($q) => $q->whereHas('owner', fn ($o) => $o->where('is_guest', false)))
            ->whereHas('inventoryItems', fn ($q) => $q->where('list', SbomFeature::componentsList()))
            ->with('inventoryItems')
            ->each(function (ProductVersion $version) use (&$queued) {
                $searchable = $version->inventoryItems->where('list', SbomFeature::componentsList())
                    ->filter(fn ($item) => Checker::matchKind($item) !== 'none');
                if ($searchable->isEmpty() || VulnerabilityChecks::running($version)) {
                    return;
                }
                if (! $this->option('dry-run')) {
                    VulnerabilityChecks::start($version, null, 'schedule');
                }
                $queued++;
            });

        $this->info(($this->option('dry-run') ? 'Would queue' : 'Queued')." {$queued} checks.");

        return self::SUCCESS;
    }
}
