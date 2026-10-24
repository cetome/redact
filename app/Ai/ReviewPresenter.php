<?php

namespace App\Ai;

use App\Content\ContentPack;
use App\Content\Text;
use App\Enums\Origin;
use App\Models\AiSuggestion;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\RiskEntry;
use Throwable;

/**
 * What the AI review page shows: pending proposals grouped by the record they
 * are for, and the latest decisions with the origin their text has now.
 * Provider and model are never part of it.
 */
final class ReviewPresenter
{
    /**
     * @return list<array{type: string, key: string, label: string, kind: string, gone: bool, items: list<array<string, mixed>>}>
     */
    public static function pending(ProductVersion $version): array
    {
        $suggestions = AiSuggestion::query()->where('product_version_id', $version->getKey())
            ->where('status', 'pending')
            ->whereIn('feature', array_map(fn (Feature $f) => $f->value, Provenance::ACCEPTABLE))
            ->orderBy('created_at')->get();

        $groups = [];
        foreach ($suggestions as $s) {
            $id = $s->target_type.'|'.$s->target_key;
            if (! isset($groups[$id])) {
                $groups[$id] = self::group($version, $s);
            }
            $groups[$id]['items'][] = self::item($version, $s, $groups[$id]['gone']);
        }

        // Product first, then new records, then the rest in order of creation.
        $order = ['product_version' => 0, 'inventory_item' => 1, 'einfo_entry' => 2, 'risk_entry' => 3, 'evaluation' => 4];

        return collect($groups)->sortBy(fn ($g) => $order[$g['type']] ?? 9, SORT_REGULAR)->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public static function decided(ProductVersion $version, int $limit = 20): array
    {
        $provenance = app(Provenance::class);

        return AiSuggestion::query()->where('product_version_id', $version->getKey())
            ->whereIn('status', ['accepted', 'rejected'])
            ->whereIn('feature', array_map(fn (Feature $f) => $f->value, Provenance::ACCEPTABLE))
            ->with('decider:id,name')
            ->latest('decided_at')->limit($limit)->get()
            ->map(function (AiSuggestion $s) use ($version, $provenance) {
                $group = self::group($version, $s);
                $origin = null;
                if ($s->status === 'accepted') {
                    $origin = self::currentOrigin($version, $s, $provenance);
                }

                return [
                    'label' => $group['label'],
                    'field' => Targets::isNewKey($s->target_key) ? null : self::fieldLabel($version, $s),
                    'status' => $s->status,
                    'origin' => $origin,
                    'by' => $s->decider?->name,
                    'at' => $s->decided_at,
                ];
            })->all();
    }

    /** @return array{type: string, key: string, label: string, kind: string, gone: bool, items: list<array<string, mixed>>} */
    private static function group(ProductVersion $version, AiSuggestion $s): array
    {
        $pack = app(ContentPack::class);
        $payload = $s->payload ?? [];
        $kind = 'field';
        $gone = false;

        if (Targets::isNewKey($s->target_key)) {
            if ($s->target_type === 'inventory_item') {
                $kind = 'new_item';
                $list = $pack->lists()[$payload['list'] ?? ''] ?? [];
                $label = __('ai.groups.new_item', ['list' => Text::of($list['singular'] ?? ($payload['list'] ?? '')), 'name' => $payload['name'] ?? '']);
            } else {
                $kind = 'new_risk';
                $label = __('ai.groups.new_risk', ['threat' => $payload['threat'] ?? '']);
            }
        } else {
            $label = match ($s->target_type) {
                'product_version' => __('ai.groups.product', ['name' => $version->product->name]),
                'inventory_item' => self::itemLabel(InventoryItem::query()->where('product_version_id', $version->getKey())->find($s->target_key)),
                'risk_entry' => ($risk = RiskEntry::query()->where('product_version_id', $version->getKey())->find($s->target_key))
                    ? __('ai.groups.risk', ['threat' => $risk->threat]) : null,
                'einfo_entry' => self::einfoLabel($version, $s->target_key),
                default => null,
            };
            if ($label === null) {
                $gone = true;
                $label = __('ai.groups.gone');
            }
        }

        return ['type' => $s->target_type, 'key' => $s->target_key, 'label' => $label, 'kind' => $kind, 'gone' => $gone, 'items' => []];
    }

    /** @return array<string, mixed> */
    private static function item(ProductVersion $version, AiSuggestion $s, bool $gone): array
    {
        $payload = $s->payload ?? [];
        $current = null;
        if (! $gone && ! Targets::isNewKey($s->target_key)) {
            try {
                $current = Targets::resolve($version, $s->target_type, $s->target_key, $s->field)->current();
            } catch (Throwable) {
                $gone = true;
            }
        }

        $attributes = [];
        if (Targets::isNewKey($s->target_key) && $s->target_type === 'inventory_item') {
            $list = app(ContentPack::class)->lists()[$payload['list'] ?? ''] ?? [];
            foreach ($payload['attributes'] ?? [] as $key => $value) {
                $definition = collect($list['fields'] ?? [])->firstWhere('key', $key);
                if ($definition) {
                    $option = collect($definition['options'] ?? [])->firstWhere('value', $value);
                    $shown = $option ? Text::of($option['label'])
                        : (($definition['type'] ?? null) === 'boolean' ? __($value === 'true' ? 'common.yes' : 'common.no') : (string) $value);
                    $attributes[] = ['label' => Text::of($definition['label'] ?? $key), 'value' => $shown];
                }
            }
        }

        return [
            'suggestion' => $s,
            'origin' => $s->origin ?? Origin::AiGenerated,
            'field' => Targets::isNewKey($s->target_key) ? null : self::fieldLabel($version, $s),
            'value' => (string) ($payload['value'] ?? ''),
            'current' => $current,
            'name' => $payload['name'] ?? null,
            'threat' => $payload['threat'] ?? null,
            'description' => $payload['description'] ?? null,
            'stride' => $payload['stride'] ?? [],
            'attributes' => $attributes,
            'sources' => $payload['sources'] ?? [],
            'acceptable' => ! $gone,
        ];
    }

    private static function fieldLabel(ProductVersion $version, AiSuggestion $s): string
    {
        if (str_starts_with($s->field, 'attributes.')) {
            $item = InventoryItem::query()->where('product_version_id', $version->getKey())->find($s->target_key);
            $definition = $item ? collect(app(ContentPack::class)->lists()[$item->list]['fields'] ?? [])->firstWhere('key', substr($s->field, 11)) : null;

            return Text::of($definition['label'] ?? substr($s->field, 11));
        }

        return __('ai.fields.'.$s->field);
    }

    private static function itemLabel(?InventoryItem $item): ?string
    {
        if (! $item) {
            return null;
        }
        $list = app(ContentPack::class)->lists()[$item->list] ?? [];

        return Text::of($list['singular'] ?? $item->list).' '.$item->label();
    }

    private static function einfoLabel(ProductVersion $version, string $key): ?string
    {
        [$code, $itemId] = Targets::splitEinfoKey($key);
        if ($itemId === null) {
            return $code;
        }
        $item = InventoryItem::query()->where('product_version_id', $version->getKey())->find($itemId);

        return $item ? $code.' · '.$item->label() : null;
    }

    private static function currentOrigin(ProductVersion $version, AiSuggestion $s, Provenance $provenance): ?Origin
    {
        try {
            if (Targets::isNewKey($s->target_key)) {
                $id = $s->payload['created_id'] ?? null;
                $record = $s->target_type === 'inventory_item' ? InventoryItem::find($id) : RiskEntry::find($id);
                if (! $record) {
                    return null;
                }
                $field = $s->target_type === 'inventory_item' ? 'name' : 'threat';

                return $provenance->origin($s->target_type, $record->getKey(), $field, $record->getAttribute($field));
            }
            $target = Targets::resolve($version, $s->target_type, $s->target_key, $s->field);

            return $provenance->origin($target->originType(), $target->originKey(), $target->field, $target->current());
        } catch (Throwable) {
            return null;
        }
    }
}
