<?php

namespace App\Ai;

use App\Content\ContentPack;
use App\Models\EinfoEntry;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\RiskEntry;

/**
 * The strict whitelist of human fields the AI may propose values for.
 *
 *   product_version  intended_use, description (the product's description)
 *   product          description (same field, keyed by the product)
 *   inventory_item   name, description, attributes.<field of the pack list>
 *   evaluation       justification   (NEVER decision_node, path or verdict)
 *   einfo_entry      text            (key "<E.Info code>|<item id or ->", created when missing,
 *                                     or the id of an existing entry)
 *   risk_entry       description, risk, remediation, implementation
 *
 * New records proposed by a draft (an inventory item, a risk) use the key
 * "new:<id>" and the field "*"; Provenance creates them on acceptance.
 */
final class Targets
{
    public const FIELDS = [
        'product_version' => ['intended_use', 'description'],
        'product' => ['description'],
        'inventory_item' => ['name', 'description'],
        'evaluation' => ['justification'],
        'einfo_entry' => ['text'],
        'risk_entry' => ['description', 'risk', 'remediation', 'implementation'],
    ];

    /** Types whose new records a draft may propose. */
    public const CREATABLE = ['inventory_item', 'risk_entry'];

    public const NEW_FIELD = '*';

    public static function isNewKey(string $key): bool
    {
        return str_starts_with($key, 'new:');
    }

    /** @return array{0: string, 1: ?string} */
    public static function splitEinfoKey(string $key): array
    {
        [$code, $item] = array_pad(explode('|', $key, 2), 2, '-');

        return [$code, $item === '-' || $item === '' ? null : $item];
    }

    public static function einfoKey(string $code, ?string $itemId): string
    {
        return $code.'|'.($itemId ?? '-');
    }

    /** Is (type, field) on the whitelist, whatever the record? Cheap check for validation rules. */
    public static function allowed(string $type, string $field): bool
    {
        if ($type === 'inventory_item' && preg_match('/^attributes\.[A-Za-z0-9_-]{1,64}$/', $field)) {
            return true;
        }

        return in_array($field, self::FIELDS[$type] ?? [], true);
    }

    /**
     * Resolve a field of this version, or throw (422) when it is not on the
     * whitelist or does not belong to the version.
     */
    public static function resolve(ProductVersion $version, string $type, string $key, string $field): Target
    {
        if (! self::allowed($type, $field) || self::isNewKey($key)) {
            throw AiException::invalid(__('ai.errors.target_not_allowed'));
        }

        $pack = app(ContentPack::class);
        $versionId = $version->getKey();

        switch ($type) {
            case 'product_version':
                if ($key !== (string) $versionId) {
                    break;
                }

                return $field === 'description'
                    ? new Target($version, 'product_version', $key, 'description', $version->product)
                    : new Target($version, 'product_version', $key, 'intended_use', $version);

            case 'product':
                if ($key !== (string) $version->product_id) {
                    break;
                }

                return new Target($version, 'product_version', (string) $versionId, 'description', $version->product);

            case 'inventory_item':
                $item = InventoryItem::query()->where('product_version_id', $versionId)->whereKey($key)->first();
                if (! $item) {
                    break;
                }
                $meta = [];
                if (str_starts_with($field, 'attributes.')) {
                    $meta = collect($pack->lists()[$item->list]['fields'] ?? [])->firstWhere('key', substr($field, 11));
                    if (! $meta) {
                        break;
                    }
                }

                return new Target($version, $type, $key, $field, $item, $meta);

            case 'evaluation':
                $evaluation = Evaluation::query()->where('product_version_id', $versionId)->whereKey($key)->first();
                if (! $evaluation) {
                    break;
                }

                return new Target($version, $type, $key, 'justification', $evaluation);

            case 'einfo_entry':
                if (! str_contains($key, '|')) {
                    // Keyed by the entry's id (an existing entry): use its canonical key.
                    $existing = EinfoEntry::query()->where('product_version_id', $versionId)->whereKey($key)->first();
                    if (! $existing) {
                        break;
                    }
                    $key = self::einfoKey($existing->einfo, $existing->inventory_item_id);
                }
                [$code, $itemId] = self::splitEinfoKey($key);
                $definition = $pack->einfo()[$code] ?? null;
                if (! $definition) {
                    break;
                }
                if ($itemId !== null) {
                    $item = InventoryItem::query()->where('product_version_id', $versionId)->whereKey($itemId)->first();
                    if (! $item || ($definition['list'] ?? null) !== $item->list) {
                        break;
                    }
                } elseif (($definition['list'] ?? null) !== null) {
                    break;
                }
                $entry = EinfoEntry::query()->where('product_version_id', $versionId)
                    ->where('einfo', $code)->where('inventory_item_id', $itemId)->first();

                return new Target($version, $type, self::einfoKey($code, $itemId), 'text', $entry, $definition);

            case 'risk_entry':
                $risk = RiskEntry::query()->where('product_version_id', $versionId)->whereKey($key)->first();
                if (! $risk) {
                    break;
                }

                return new Target($version, $type, $key, $field, $risk);
        }

        throw AiException::invalid(__('ai.errors.target_not_allowed'));
    }
}
