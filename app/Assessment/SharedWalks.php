<?php

namespace App\Assessment;

use App\Content\ContentPack;
use App\Content\Tree;
use App\Enums\Verdict;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use WeakMap;

/**
 * Walks shared across the parts of EN 18031. Many requirements have the same tree in several
 * parts (e.g. a security asset is assessed in EN 18031-1, -2 and -3): it is answered once.
 *
 * Two trees are equivalent when they start at the same node and have the same decision nodes,
 * with the same branch labels (case and order aside) leading to the same decision nodes or the
 * same results. The wording of the questions does not count, nor the ids of result nodes.
 *
 * Saving the evaluation of a requirement for an item (or the product) applies the same path,
 * last node, verdict and justification to the same requirement and item in the other working
 * parts where the item is a target too and the tree is equivalent, creating the evaluations
 * that do not exist yet. Siblings share a `shared_group` and stay in sync on every save, unless
 * one was untied ("decided separately", `shared_untied`): it is never filled in from another
 * part until it is tied again. The outcome is recomputed with each part's own tree. Reviews
 * stay per evaluation: a synced change makes them stale like any change.
 */
final class SharedWalks
{
    /** @var WeakMap<ContentPack, array<string, array<string, int>>>|null */
    private static ?WeakMap $classes = null;

    public function __construct(private readonly Assessment $assessment) {}

    public static function for(Assessment $assessment): self
    {
        return new self($assessment);
    }

    /**
     * Equivalence classes of the trees, per requirement: requirement => standard => class number
     * (standards with the same number have equivalent trees). Computed once per loaded pack.
     *
     * @return array<string, array<string, int>>
     */
    public static function classes(ContentPack $pack): array
    {
        self::$classes ??= new WeakMap;
        if (isset(self::$classes[$pack])) {
            return self::$classes[$pack];
        }

        $shapes = [];
        foreach (array_keys($pack->standards()) as $standard) {
            foreach ($pack->treesFor($standard) as $requirement => $tree) {
                $shapes[(string) $requirement][$standard] = self::shape($tree);
            }
        }
        $classes = [];
        foreach ($shapes as $requirement => $byStandard) {
            $seen = [];
            foreach ($byStandard as $standard => $shape) {
                $index = array_search($shape, $seen, true);
                if ($index === false) {
                    $seen[] = $shape;
                    $index = count($seen) - 1;
                }
                $classes[$requirement][$standard] = (int) $index;
            }
        }

        return self::$classes[$pack] = $classes;
    }

    /** A canonical form of the structure of a tree (what equivalence compares). */
    public static function shape(Tree $tree): string
    {
        $nodes = $tree->nodes();
        $to = function (string $id) use ($nodes): string {
            $node = $nodes[$id] ?? null;

            return $node !== null && ($node['kind'] ?? null) === 'result' ? 'result:'.mb_strtoupper((string) $node['result']) : 'node:'.$id;
        };
        $decisions = [];
        foreach ($nodes as $id => $node) {
            if (($node['kind'] ?? null) !== 'decision') {
                continue;
            }
            $branches = [];
            foreach ($node['branches'] ?? [] as $branch) {
                $branches[] = mb_strtolower(trim((string) $branch['label'])).'>'.$to((string) $branch['to']);
            }
            sort($branches);
            $decisions[(string) $id] = $branches;
        }
        ksort($decisions);

        return (string) json_encode(['start' => $to($tree->start()), 'decisions' => $decisions]);
    }

    /**
     * Other parts of the pack where the tree of this requirement is equivalent, in pack order.
     *
     * @return list<string>
     */
    public function equivalent(string $standard, string $requirement): array
    {
        $classes = self::classes($this->assessment->pack)[$requirement] ?? [];
        if (! isset($classes[$standard])) {
            return [];
        }

        return array_values(array_filter(
            array_keys($classes),
            fn (string $other) => $other !== $standard && $classes[$other] === $classes[$standard],
        ));
    }

    /**
     * The working parts where the same requirement and the same item (or the product) are
     * evaluated too with an equivalent tree, with their evaluation when there is one.
     *
     * @return list<array{standard: string, evaluation: Evaluation|null}>
     */
    public function siblings(string $standard, string $requirement, ?InventoryItem $item): array
    {
        $working = $this->assessment->workingStandards();
        $siblings = [];
        foreach ($this->equivalent($standard, $requirement) as $other) {
            if (! in_array($other, $working, true) || ! $this->isTarget($other, $requirement, $item?->id)) {
                continue;
            }
            $siblings[] = ['standard' => $other, 'evaluation' => $this->assessment->evaluation($other, $requirement, $item)];
        }

        return $siblings;
    }

    /**
     * What the walk page says about sharing for one item: the parts that follow this answer
     * (`parts`), the part the shared answer was last saved in when it is another one
     * (`source`), the equivalent parts decided separately (`apart`), and whether this
     * evaluation itself is decided separately (`untied`).
     *
     * @return array{parts: list<string>, source: string|null, apart: list<string>, untied: bool}
     */
    public function state(string $standard, string $requirement, ?InventoryItem $item, ?Evaluation $evaluation): array
    {
        $parts = [];
        $apart = [];
        foreach ($this->siblings($standard, $requirement, $item) as $sibling) {
            if ($sibling['evaluation']?->shared_untied) {
                $apart[] = $sibling['standard'];
            } else {
                $parts[] = $sibling['standard'];
            }
        }
        $untied = (bool) $evaluation?->shared_untied;
        $source = $evaluation?->shared_source;

        return [
            'parts' => $untied ? [] : $parts,
            'source' => ! $untied && $source !== null && $source !== $standard && in_array($source, $parts, true) ? $source : null,
            'apart' => $untied ? [...$parts, ...$apart] : $apart,
            'untied' => $untied,
        ];
    }

    /**
     * Apply $source (filled in, about to be saved) to its siblings: create or update their
     * evaluations with the outcome recomputed on their own tree, all in one shared group.
     * Sets the group on $source (not saved) and returns the parts written, in pack order.
     *
     * Call inside a transaction. The siblings' rows are written without their own activity
     * entry: the entry of $source names the parts (Evaluation::$sharedWith).
     *
     * @return list<string>
     */
    public function apply(Evaluation $source, ?int $userId): array
    {
        if ($source->shared_untied) {
            return [];
        }
        $item = $source->inventory_item_id ? $this->assessment->context()->item($source->inventory_item_id) : null;
        if ($source->inventory_item_id && ! $item) {
            return [];
        }

        $written = [];
        $group = $source->shared_group;
        foreach ($this->siblings($source->standard, $source->requirement, $item) as $sibling) {
            $other = $sibling['standard'];
            $tree = $this->assessment->pack->tree($other, $source->requirement);
            $outcome = $tree ? $this->replay($tree, $source) : null;
            if ($outcome === null) {
                continue;
            }
            $evaluation = $this->row($other, $source->requirement, $source->inventory_item_id, lock: true);
            if ($evaluation->shared_untied) {
                continue;
            }
            $group ??= $evaluation->shared_group ?? (string) Str::ulid();
            [$node, $verdict, $path] = $outcome;
            $evaluation->fill([
                'decision_node' => $node,
                'verdict' => $verdict,
                'path' => $path,
                'justification' => $source->justification,
                'shared_group' => $group,
                'shared_source' => $source->standard,
                'updated_by' => $userId,
            ]);
            if ($evaluation->isDirty() || ! $evaluation->exists) {
                $evaluation->disableLogging()->save();
                $evaluation->enableLogging();
            }
            $written[] = $other;
        }

        if ($written !== []) {
            $source->shared_group = $group;
            $source->shared_source = $source->standard;
        }

        return $written;
    }

    /** Decide this evaluation separately: it leaves its group and is never filled in from another part. */
    public function untie(Evaluation $evaluation): void
    {
        $evaluation->forceFill(['shared_untied' => true, 'shared_group' => null, 'shared_source' => null])->save();
    }

    /**
     * Use the shared answer again: take the latest answer of the parts still sharing it (if any),
     * then keep every sibling in sync again. Returns the parts written besides this one.
     * Call inside a transaction; saves $evaluation.
     *
     * @return list<string>
     */
    public function retie(Evaluation $evaluation, ?int $userId): array
    {
        $evaluation->shared_untied = false;
        $tree = $this->assessment->pack->tree($evaluation->standard, $evaluation->requirement);
        $item = $evaluation->inventory_item_id ? $this->assessment->context()->item($evaluation->inventory_item_id) : null;

        $latest = collect($this->siblings($evaluation->standard, $evaluation->requirement, $item))
            ->map(fn (array $sibling) => $this->row($sibling['standard'], $evaluation->requirement, $evaluation->inventory_item_id))
            ->filter(fn (Evaluation $other) => $other->exists && ! $other->shared_untied && ($other->decision_node || $other->verdict))
            ->sortByDesc(fn (Evaluation $other) => $other->updated_at?->getTimestamp() ?? 0)
            ->first();
        if ($latest && $tree && ($outcome = $this->replay($tree, $latest)) !== null) {
            [$node, $verdict, $path] = $outcome;
            $evaluation->fill([
                'decision_node' => $node,
                'verdict' => $verdict,
                'path' => $path,
                'justification' => $latest->justification,
                'shared_group' => $latest->shared_group,
                'updated_by' => $userId,
            ]);
        }

        $written = $this->apply($evaluation, $userId);
        $evaluation->sharedWith = array_map(fn (string $s) => $this->assessment->pack->standardName($s), $written);
        $evaluation->save();

        return $written;
    }

    /**
     * The outcome of $source on another tree: the same answers walked again (the server never
     * trusts a copied verdict), or the same node and verdict checked against the tree.
     * Null when the tree cannot reach it.
     *
     * @return array{0: string|null, 1: Verdict|null, 2: list<array{node: string, answer: string}>|null}|null
     */
    public function replay(Tree $tree, Evaluation $source): ?array
    {
        $inconclusive = $source->verdict === Verdict::Inconclusive;
        if (! empty($source->path)) {
            try {
                $walk = $tree->walk(array_column($source->path, 'answer'));
            } catch (InvalidArgumentException) {
                return null;
            }
            if ($inconclusive) {
                return [$walk['last_decision'], Verdict::Inconclusive, $walk['path'] ?: null];
            }

            return $walk['verdict'] ? [$walk['last_decision'], $walk['verdict'], $walk['path']] : null;
        }

        $node = $source->decision_node;
        if ($node !== null && ! in_array($node, $tree->decisionNodes(), true)) {
            return null;
        }
        if ($source->verdict && ! $inconclusive && ($node === null || ! $tree->allows($node, $source->verdict))) {
            return null;
        }

        return [$node, $source->verdict, null];
    }

    private function isTarget(string $standard, string $requirement, ?string $itemId): bool
    {
        foreach ($this->assessment->targets($standard, $requirement) as $target) {
            if ($target?->id === $itemId) {
                return true;
            }
        }

        return false;
    }

    /** The evaluation row of a part, read fresh from the database (or a new one). */
    private function row(string $standard, string $requirement, ?string $itemId, bool $lock = false): Evaluation
    {
        $version = $this->assessment->version;
        $query = Evaluation::query()
            ->where('product_version_id', $version->id)
            ->where('standard', $standard)
            ->where('requirement', $requirement)
            ->where('inventory_item_id', $itemId);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? new Evaluation([
            'product_version_id' => $version->id,
            'standard' => $standard,
            'requirement' => $requirement,
            'inventory_item_id' => $itemId,
        ]);
    }
}
