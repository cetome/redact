<?php

namespace App\Assessment;

use App\Content\ContentPack;
use App\Content\Text;
use App\Models\InventoryItem;

/**
 * What a decision tree is walked for, named the way the content pack (and the REDact workbooks)
 * name it: "Assets", "Interfaces", "Software and hardware components", "Known vulnerabilities",
 * "The product"... The app never says "objects" for them.
 *
 * A requirement loops over its list (requirements.json "list", none = the product). The pack rules
 * may add items of another list (e.g. components without a known vulnerability): the names then
 * follow the items really walked.
 */
final class TargetNames
{
    /** The kind of a product-level walk. */
    public const PRODUCT = '';

    public function __construct(private readonly ContentPack $pack) {}

    /**
     * The kinds walked: list keys, PRODUCT for the product. The requirement's own list first,
     * then the others in pack order.
     *
     * @param  array<string, mixed>|null  $requirement
     * @param  iterable<InventoryItem|null>  $targets
     * @return list<string>
     */
    public function kinds(?array $requirement, iterable $targets): array
    {
        $kinds = [];
        foreach ($targets as $target) {
            $kinds[$target->list ?? self::PRODUCT] = true;
        }
        if ($kinds === []) {
            $kinds[(string) ($requirement['list'] ?? self::PRODUCT)] = true;
        }

        return $this->sorted($requirement, array_map('strval', array_keys($kinds)));
    }

    /**
     * The heading of the list of what is walked: "Assets", "The product", or several lists
     * ("Known vulnerabilities / Software and hardware components").
     *
     * @param  array<string, mixed>|null  $requirement
     * @param  iterable<InventoryItem|null>  $targets
     */
    public function heading(?array $requirement, iterable $targets): string
    {
        return implode(' / ', array_map($this->plural(...), $this->kinds($requirement, $targets)));
    }

    /**
     * "5 assets", "1 interface", "the product", "2 known vulnerabilities, 1 component".
     *
     * @param  iterable<InventoryItem|null>  $targets
     * @param  array<string, mixed>|null  $requirement  names the list when there is no target
     */
    public function count(iterable $targets, ?array $requirement = null): string
    {
        $counts = [];
        foreach ($targets as $target) {
            $kind = $target->list ?? self::PRODUCT;
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }
        if ($counts === []) {
            $kind = (string) ($requirement['list'] ?? self::PRODUCT);

            return $kind === self::PRODUCT ? $this->lower($this->plural($kind)) : '0 '.$this->lower($this->plural($kind));
        }

        $parts = [];
        foreach ($this->sorted($requirement, array_map('strval', array_keys($counts))) as $kind) {
            $n = $counts[$kind];
            $parts[] = $kind === self::PRODUCT
                ? $this->lower($this->plural($kind))
                : $n.' '.$this->lower($n === 1 ? $this->singular($kind) : $this->plural($kind));
        }

        return implode(', ', $parts);
    }

    /** "Assets", "Software and hardware components", "The product". */
    public function plural(string $kind): string
    {
        if ($kind === self::PRODUCT) {
            return __('evaluations.product');
        }

        return Text::of($this->pack->lists()[$kind]['label'] ?? $kind);
    }

    /** "Asset", "Component", "The product". */
    public function singular(string $kind): string
    {
        if ($kind === self::PRODUCT) {
            return __('evaluations.product');
        }
        $list = $this->pack->lists()[$kind] ?? [];

        return Text::of($list['singular'] ?? $list['label'] ?? $kind);
    }

    /** The singular name of one walked item: "Asset", "Interface", "The product". */
    public function of(?InventoryItem $item): string
    {
        return $this->singular($item->list ?? self::PRODUCT);
    }

    /**
     * @param  array<string, mixed>|null  $requirement
     * @param  list<string>  $kinds
     * @return list<string> the requirement's own list first, then pack order, the product last
     */
    private function sorted(?array $requirement, array $kinds): array
    {
        $order = array_flip([(string) ($requirement['list'] ?? self::PRODUCT), ...array_keys($this->pack->lists()), self::PRODUCT]);
        usort($kinds, fn (string $a, string $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return $kinds;
    }

    /** First letter in lower case (labels may hold acronyms further on). */
    private function lower(string $text): string
    {
        return mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
