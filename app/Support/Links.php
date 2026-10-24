<?php

namespace App\Support;

use App\Models\InventoryItem;
use App\Models\ProductVersion;

/**
 * Addresses of the assessment pages that point at one object, built from path segments only:
 * the application never generates a query string (owner rule). "product" stands for the product
 * itself where an inventory item would otherwise be.
 */
final class Links
{
    /** The walk of one decision tree for one object (the product or an inventory item). */
    public static function walk(ProductVersion|string $version, string $standard, string $requirement, InventoryItem|string|null $target): string
    {
        return route('versions.evaluate.target', [$version, $standard, $requirement, self::key($target)]);
    }

    /** The E.Info page of a standard, on one object, every object or the incomplete ones only. */
    public static function einfo(ProductVersion|string $version, string $standard, InventoryItem|string|null $object = null, bool $missing = false): string
    {
        if ($object === null) {
            return route($missing ? 'versions.einfo.missing' : 'versions.einfo', [$version, $standard]);
        }

        return route($missing ? 'versions.einfo.missing.object' : 'versions.einfo.object', [$version, $standard, self::key($object)]);
    }

    /** One inventory record in its side panel. */
    public static function item(ProductVersion|string $version, InventoryItem $item): string
    {
        return route('versions.inventory.edit', [$version, $item->list, $item->id]);
    }

    /** The route key of an object: an item id, or "product". */
    public static function key(InventoryItem|string|null $target): string
    {
        if ($target instanceof InventoryItem) {
            return (string) $target->id;
        }

        return $target === null || $target === '' ? 'product' : $target;
    }
}
