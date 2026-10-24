<?php

namespace App\Assessment;

use App\Content\Text;
use App\Models\ProductVersion;

/**
 * The "Assets & interfaces" step as a guided sequence: the inventory lists that matter for the
 * version, in the pack's order, then the workflow's next step. The mappings are not a step of the
 * sequence (owner, 2026-10-23): a separate button (mappings()), reachable from every list.
 *
 * Which lists: a list the pack's requirements or E.Info loop over is shown when one of those
 * belongs to a working standard of the version (e.g. logged events only with EN 18031-2 or -3);
 * a list used by none of them (only through its relations, like entities) is always shown, and
 * so is a list that already holds objects (nothing a person entered is ever hidden).
 *
 * A list is done when it has objects or is marked "This product has none" (product_versions.
 * inventory_none). The mappings never count in the progress.
 *
 * "Prefill everything" (prefillPlan()): for every list of the sequence not marked none, the
 * catalogue items the pickers pre-tick (linked to a scoping answer) that are not in the inventory yet.
 */
class InventoryGuide
{
    public const MAPPINGS = '_mappings';

    /** @var list<array{key: string, label: string, singular: string, href: string, count: int, none: bool, done: bool, catalogue: int}>|null */
    private ?array $steps = null;

    /** @var array{lists: array<string, array{label: string, rows: list<array<string, mixed>>}>, skipped: list<string>, total: int}|null */
    private ?array $prefill = null;

    /** @var array<string, int>|null */
    private ?array $counts = null;

    public function __construct(private readonly Assessment $assessment, private readonly ?string $current = null) {}

    public function version(): ProductVersion
    {
        return $this->assessment->version;
    }

    /** @return array<string, int> list key => number of objects */
    public function counts(): array
    {
        return $this->counts ??= $this->assessment->version->inventoryItems()->reorder()
            ->selectRaw('list, count(*) as n')->groupBy('list')->pluck('n', 'list')
            ->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Keys of the lists of the sequence, in pack order.
     *
     * @return list<string>
     */
    public function listKeys(): array
    {
        $pack = $this->assessment->pack;
        $all = array_keys($pack->standards());
        $used = [];
        foreach ($pack->requirements() as $requirement) {
            if (! empty($requirement['list'])) {
                $used[$requirement['list']] = [...($used[$requirement['list']] ?? []), ...($requirement['standards'] ?? $all)];
            }
        }
        foreach ($pack->einfo() as $einfo) {
            if (! empty($einfo['list'])) {
                $used[$einfo['list']] = [...($used[$einfo['list']] ?? []), ...($einfo['standards'] ?? $all)];
            }
        }
        $working = $this->assessment->workingStandards();
        $counts = $this->counts();

        return array_values(array_filter(array_keys($pack->lists()), fn (string $key) => ! isset($used[$key])
            || array_intersect($used[$key], $working) !== []
            || ($counts[$key] ?? 0) > 0
            || $key === $this->current));
    }

    /**
     * Relations between two lists of the sequence (the mappings to show).
     *
     * @return array<string, array<string, mixed>>
     */
    public function relations(): array
    {
        $keys = array_flip($this->listKeys());

        return array_filter($this->assessment->pack->relations(), fn (array $r) => isset($keys[$r['from']], $keys[$r['to']]));
    }

    /**
     * The sub-steps: one per list of the sequence.
     *
     * @return list<array{key: string, label: string, singular: string, href: string, count: int, none: bool, done: bool, catalogue: int}>
     */
    public function steps(): array
    {
        if ($this->steps !== null) {
            return $this->steps;
        }
        $version = $this->assessment->version;
        $pack = $this->assessment->pack;
        $none = array_flip($version->noneLists());
        $counts = $this->counts();
        $steps = [];
        foreach ($this->listKeys() as $key) {
            $definition = $pack->lists()[$key];
            $count = $counts[$key] ?? 0;
            $isNone = $count === 0 && isset($none[$key]);
            $steps[] = [
                'key' => $key,
                'label' => Text::of($definition['label'] ?? $key),
                'singular' => Text::of($definition['singular'] ?? $definition['label'] ?? $key),
                'href' => route('versions.inventory', [$version, $key]),
                'count' => $count,
                'none' => $isNone,
                'done' => $count > 0 || $isNone,
                'catalogue' => count($pack->catalogue($key)),
            ];
        }

        return $this->steps = $steps;
    }

    /**
     * The mappings between the lists of the sequence: a separate page, not a step. $from is the
     * list the page goes back to (default: the first list). Null when the pack has no relation.
     *
     * @return array{href: string, count: int, back: string}|null
     */
    public function mappings(?string $from = null): ?array
    {
        $keys = $this->listKeys();
        if ($keys === [] || $this->relations() === []) {
            return null;
        }
        $back = in_array($from, $keys, true) ? (string) $from : $keys[0];

        return [
            'href' => route('versions.inventory.mappings', [$this->assessment->version, $back]),
            'count' => $this->assessment->version->inventoryLinks()->whereIn('relation', array_keys($this->relations()))->count(),
            'back' => $back,
        ];
    }

    /** @return array{key: string, label: string, singular: string, href: string, count: int, none: bool, done: bool, catalogue: int}|null */
    public function step(string $key): ?array
    {
        return collect($this->steps())->firstWhere('key', $key);
    }

    /** @return array{key: string, label: string, singular: string, href: string, count: int, none: bool, done: bool, catalogue: int}|null */
    public function previous(string $key): ?array
    {
        $steps = $this->steps();
        $i = $this->indexOf($key);

        return $i !== null && $i > 0 ? $steps[$i - 1] : null;
    }

    /** @return array{key: string, label: string, singular: string, href: string, count: int, none: bool, done: bool, catalogue: int}|null */
    public function next(string $key): ?array
    {
        $i = $this->indexOf($key);

        return $i !== null ? ($this->steps()[$i + 1] ?? null) : null;
    }

    /**
     * Where "continue" leads from a sub-step: the next list's catalogue picker when that list is
     * still empty and has a catalogue (with $picker), else its page; after the last sub-step, the
     * workflow's next step.
     */
    public function continueUrl(string $key, bool $picker = false): string
    {
        $next = $this->next($key);
        if ($next === null) {
            return $this->afterStep()['href'];
        }
        if ($picker && ! $next['done'] && $next['catalogue'] > 0) {
            return route('versions.inventory.pick', [$this->assessment->version, $next['key']]);
        }

        return $next['href'];
    }

    /** @return array{label: string, href: string} */
    public function continueTarget(string $key): array
    {
        $next = $this->next($key);

        return $next ? ['label' => $next['label'], 'href' => $next['href']] : $this->afterStep();
    }

    /**
     * The workflow step after "Assets & interfaces" (in the order of the workflow, whatever it is).
     *
     * @return array{label: string, href: string}
     */
    public function afterStep(): array
    {
        $next = $this->assessment->nextStepAfter('inventory');
        if ($next !== null) {
            return ['label' => $next['label'], 'href' => $next['href']];
        }

        return ['label' => __('workflow.overview'), 'href' => route('versions.show', $this->assessment->version)];
    }

    /** @return array{done: int, total: int} lists done (objects or "none") of the lists to fill */
    public function progress(): array
    {
        $lists = $this->steps();

        return ['done' => count(array_filter($lists, fn (array $s) => $s['done'])), 'total' => count($lists)];
    }

    /**
     * What "Prefill everything" adds: per list of the sequence (not marked none), the catalogue
     * rows linked to a scoping answer (the ones each picker pre-ticks) that are not in the
     * inventory yet (same name rule as the pickers). Always recomputed on the server.
     *
     * @return array{lists: array<string, array{label: string, rows: list<array<string, mixed>>}>, skipped: list<string>, total: int}
     */
    public function prefillPlan(): array
    {
        if ($this->prefill !== null) {
            return $this->prefill;
        }
        $suggestions = new CatalogueSuggestions($this->assessment);
        $lists = [];
        $skipped = [];
        $total = 0;
        foreach ($this->steps() as $step) {
            if ($step['catalogue'] === 0) {
                continue;
            }
            $rows = array_values(array_filter($suggestions->forList($step['key']), fn (array $r) => $r['strength'] === 'answer' && ! $r['added']));
            if ($rows === []) {
                continue;
            }
            if ($step['none']) {
                $skipped[] = $step['label'];

                continue;
            }
            $lists[$step['key']] = ['label' => $step['label'], 'rows' => $rows];
            $total += count($rows);
        }

        return $this->prefill = ['lists' => $lists, 'skipped' => $skipped, 'total' => $total];
    }

    private function indexOf(string $key): ?int
    {
        foreach ($this->steps() as $i => $step) {
            if ($step['key'] === $key) {
                return $i;
            }
        }

        return null;
    }
}
