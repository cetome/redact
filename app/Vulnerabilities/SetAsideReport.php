<?php

namespace App\Vulnerabilities;

use App\Models\ProductVersion;
use App\Models\VulnerabilityFinding;
use App\Sbom\SbomFeature;

/**
 * Vulnerabilities people set aside, for the reports: every decision is justified, so each one
 * appears in the vulnerabilities list of the Word and Excel reports with its justification
 * (App\Reports\ReportData::inventory appends these rows to that list).
 */
final class SetAsideReport
{
    /**
     * Extra report rows for one list (none unless it is the vulnerabilities list).
     *
     * @param  array<string, mixed>  $list  the pack's list definition
     * @return list<array{code: string, name: string, members: list<string>, description: string, values: array<string, string>}>
     */
    public static function items(ProductVersion $version, string $listKey, array $list): array
    {
        if ($listKey !== SbomFeature::vulnerabilitiesList()) {
            return [];
        }
        $findings = VulnerabilityFinding::query()->where('product_version_id', $version->id)->where('status', 'dismissed')
            ->with('component:id,code,name')->orderBy('key')->get();

        $rows = [];
        foreach ($findings as $finding) {
            $last = collect($finding->history ?? [])->last();
            $component = $finding->component;
            $values = [];
            foreach ($list['fields'] ?? [] as $field) {
                $values[$field['key']] = match ($field['key']) {
                    'reference' => implode(', ', $finding->ids()),
                    'source' => implode(' ', array_values($finding->urls ?? [])),
                    default => '',
                };
            }
            $rows[] = [
                'code' => (string) __('sbom.report.set_aside'),
                'name' => $finding->key.($finding->title ? ' · '.$finding->title : ''),
                'members' => [],
                'description' => (string) __('sbom.report.description', [
                    'component' => $component ? trim($component->code.' '.$component->name) : '—',
                    'date' => $finding->decided_at?->isoFormat('D MMMM YYYY') ?? '—',
                    'name' => is_array($last) ? ($last['by_name'] ?? '—') : '—',
                    'reason' => (string) $finding->reason,
                ]),
                'values' => $values,
            ];
        }

        return $rows;
    }
}
