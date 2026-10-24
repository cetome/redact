<?php

namespace App\Content;

use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Read-only snapshot of a version's assessment data, loaded once and handed
 * to the content pack rules so they never query the database themselves.
 */
final class VersionContext
{
    /** @var Collection<array-key, EloquentCollection<int, InventoryItem>> */
    private Collection $itemsByList;

    /** @var Collection<string, InventoryItem> */
    private Collection $itemsById;

    /** @var array<string, array<string, list<string>>> relation => from id => [to ids] */
    private array $links = [];

    /** @var array<string, Evaluation> */
    private array $evaluations = [];

    /** @var array<string, mixed> */
    private array $scoping;

    public function __construct(public readonly ProductVersion $version)
    {
        $items = $version->inventoryItems()->get();
        $this->itemsById = $items->keyBy('id');
        $this->itemsByList = $items->groupBy('list');

        foreach ($version->inventoryLinks()->get(['relation', 'from_item_id', 'to_item_id']) as $link) {
            $this->links[$link->relation][$link->from_item_id][] = $link->to_item_id;
        }

        foreach ($version->evaluations()->get() as $evaluation) {
            $this->evaluations[self::evaluationKey($evaluation->standard, $evaluation->requirement, $evaluation->inventory_item_id)] = $evaluation;
        }

        $this->scoping = $version->scopingAnswers()->pluck('value', 'question')->all();
    }

    public static function evaluationKey(string $standard, string $requirement, ?string $itemId): string
    {
        return $standard.'|'.$requirement.'|'.($itemId ?? '-');
    }

    /** @return Collection<int, InventoryItem> */
    public function items(string $list): Collection
    {
        return $this->itemsByList->get($list, collect());
    }

    public function item(string $id): ?InventoryItem
    {
        return $this->itemsById->get($id);
    }

    /**
     * Items linked from $item through $relation (or to it, when $reverse).
     *
     * @return Collection<int, InventoryItem>
     */
    public function linked(InventoryItem $item, string $relation, bool $reverse = false): Collection
    {
        if (! $reverse) {
            $ids = $this->links[$relation][$item->id] ?? [];
        } else {
            $ids = [];
            foreach ($this->links[$relation] ?? [] as $from => $tos) {
                if (in_array($item->id, $tos, true)) {
                    $ids[] = $from;
                }
            }
        }

        return collect($ids)->map(fn (string $id) => $this->item($id))->filter()->values();
    }

    public function evaluation(string $standard, string $requirement, ?InventoryItem $item): ?Evaluation
    {
        return $this->evaluations[self::evaluationKey($standard, $requirement, $item?->id)] ?? null;
    }

    /** Last decision node reached, e.g. "DN-4", or null. */
    public function node(string $standard, string $requirement, ?InventoryItem $item): ?string
    {
        return $this->evaluation($standard, $requirement, $item)?->decision_node;
    }

    /** The number of the last decision node reached (4 for DN-4), or null. */
    public function nodeNumber(string $standard, string $requirement, ?InventoryItem $item): ?int
    {
        $node = $this->node($standard, $requirement, $item);

        return $node && preg_match('/(\d+)$/', $node, $m) ? (int) $m[1] : null;
    }

    public function scoping(string $question, mixed $default = null): mixed
    {
        return $this->scoping[$question] ?? $default;
    }

    /** @return array<string, Evaluation> */
    public function allEvaluations(): array
    {
        return $this->evaluations;
    }
}
