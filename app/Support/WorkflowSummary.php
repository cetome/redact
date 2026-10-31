<?php

namespace App\Support;

use App\Assessment\Assessment;
use App\Models\ProductVersion;

/**
 * The workflow steps of one version, ready for the screens: Assessment::steps() grouped by key
 * (decision trees and E.Info have one line per standard), with figures and the next thing to do.
 * Used by the sidebar, the version overview and the products dashboard. The order is
 * Assessment::ORDER (product, essential requirements, risks, assets & interfaces, …).
 */
class WorkflowSummary
{
    /** One lucide icon per step, used wherever a step is shown. */
    public const ICONS = [
        'overview' => 'layout-grid', 'scoping' => 'target', 'description' => 'package', 'inventory' => 'boxes', 'changes' => 'git-compare',
        'evaluate' => 'git-fork', 'einfo' => 'file-text', 'units' => 'flask-conical', 'risks' => 'shield-alert', 'conformity' => 'badge-check',
        'review' => 'clipboard-check', 'declaration' => 'stamp', 'reports' => 'file-down', 'team' => 'users', 'ai' => 'sparkles',
    ];

    public static function icon(string $key): string
    {
        return self::ICONS[$key] ?? 'circle';
    }

    /**
     * @var list<array{key: string, label: string, href: string, done: bool, detail: string, doneCount: int|null, total: int|null,
     *     subs: list<array{standard: string, name: string, href: string, done: int, total: int, unjustified: int}>,
     *     lists: list<array{key: string, name: string, href: string, count: int, none: bool, done: bool}>}>
     */
    public readonly array $groups;

    private function __construct(public readonly Assessment $assessment)
    {
        $version = $assessment->version;
        $groups = [];
        $sums = [];
        foreach ($assessment->steps() as $step) {
            $key = $step['key'];
            $href = route($step['route'], [$version, ...$step['params']]);
            $standard = $step['params']['standard'] ?? null;
            if (! isset($groups[$key])) {
                $groups[$key] = ['key' => $key, 'label' => __('workflow.steps.'.$key), 'href' => $href, 'done' => $step['done'],
                    'detail' => $step['detail'], 'doneCount' => $step['done_count'] ?? null, 'total' => $step['total'] ?? null, 'subs' => [], 'lists' => []];
            } else {
                $groups[$key]['done'] = $groups[$key]['done'] && $step['done'];
            }
            if ($standard !== null && in_array($key, ['evaluate', 'einfo'], true)) {
                $p = $key === 'evaluate' ? $assessment->evaluationProgress($standard) : $assessment->einfoProgress($standard);
                $groups[$key]['subs'][] = ['standard' => $standard, 'name' => $assessment->pack->standardName($standard),
                    'href' => $href, 'done' => $p['done'], 'total' => $p['total'], 'unjustified' => $p['unjustified'] ?? 0];
                $sums[$key]['done'] = ($sums[$key]['done'] ?? 0) + $p['done'];
                $sums[$key]['total'] = ($sums[$key]['total'] ?? 0) + $p['total'];
                $groups[$key]['doneCount'] = $sums[$key]['done'];
                $groups[$key]['total'] = $sums[$key]['total'];
            }
        }
        // Assets & interfaces: one line per list of the guided sequence (InventoryGuide).
        if (isset($groups['inventory'])) {
            $guide = $assessment->inventoryGuide();
            foreach ($guide->steps() as $sub) {
                $groups['inventory']['lists'][] = ['key' => $sub['key'], 'name' => $sub['label'], 'href' => $sub['href'],
                    'count' => $sub['count'], 'none' => $sub['none'], 'done' => $sub['done']];
            }
            $groups['inventory']['doneCount'] = $guide->progress()['done'];
            $groups['inventory']['total'] = $guide->progress()['total'];
        }
        // Essential requirements ("scoping") shows its state as text (in scope, to confirm), no count.
        $this->groups = array_values($groups);
    }

    /** One per version and request (kept on the request, so a new request recomputes it). */
    public static function for(ProductVersion $version): self
    {
        $attributes = request()->attributes;
        $key = 'redact.workflow.'.$version->id;
        if (! $attributes->has($key)) {
            $attributes->set($key, new self(Assessment::for($version)));
        }

        return $attributes->get($key);
    }

    public function doneSteps(): int
    {
        return count(array_filter($this->groups, fn (array $g) => $g['done']));
    }

    public function totalSteps(): int
    {
        return count($this->groups);
    }

    /**
     * The first step that is not done (for a per-standard step, its first unfinished standard).
     *
     * @return array{label: string, href: string, left: int|null}|null
     */
    public function next(): ?array
    {
        foreach ($this->groups as $group) {
            // The declaration waits for the review: not a next step before approval.
            if ($group['done'] || $group['key'] === 'review' || ($group['key'] === 'declaration' && $this->assessment->version->status->value !== 'approved')) {
                continue;
            }
            foreach ($group['subs'] as $sub) {
                if ($sub['total'] > $sub['done']) {
                    return ['label' => $group['label'].' · '.$sub['name'], 'href' => $sub['href'], 'left' => $sub['total'] - $sub['done']];
                }
            }
            foreach ($group['lists'] as $list) {
                if (! $list['done']) {
                    return ['label' => $group['label'].' · '.$list['name'], 'href' => $list['href'], 'left' => null];
                }
            }
            $left = $group['total'] !== null ? $group['total'] - (int) $group['doneCount'] : null;

            return ['label' => $group['label'], 'href' => $group['href'], 'left' => $left ?: null];
        }

        return null;
    }
}
