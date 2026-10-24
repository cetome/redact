<?php

namespace App\Ai;

use App\Content\ContentPack;
use App\Content\Text;
use App\Models\EinfoEntry;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\RiskEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One human text field the AI may propose a value for, resolved and checked
 * against a strict whitelist (Targets::resolve). Verdicts, decision nodes and
 * tree paths are never targets: the AI never decides them.
 */
final class Target
{
    /**
     * @param  array<string, mixed>  $meta  list field definition, E.Info definition, ...
     */
    public function __construct(
        public readonly ProductVersion $version,
        public readonly string $type,
        public readonly string $key,
        public readonly string $field,
        private ?Model $record,
        private readonly array $meta = [],
    ) {}

    /** Where the FieldOrigin is stored (the product description belongs to the product, not the version). */
    public function originType(): string
    {
        return $this->record instanceof Product ? 'product' : $this->type;
    }

    public function originKey(): string
    {
        return $this->record instanceof Product ? (string) $this->record->getKey() : $this->key;
    }

    public function record(): ?Model
    {
        return $this->record;
    }

    public function current(): ?string
    {
        if (! $this->record) {
            return null;
        }
        if (str_starts_with($this->field, 'attributes.')) {
            $value = data_get($this->record->getAttribute('attributes') ?? [], substr($this->field, 11));

            return $value === null ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        $value = $this->record->getAttribute($this->field);

        return $value === null ? null : (string) $value;
    }

    /** A free text a person writes (and the AI may reword), as opposed to a choice. */
    public function isFreeText(): bool
    {
        if (str_starts_with($this->field, 'attributes.')) {
            return in_array($this->meta['type'] ?? 'text', ['text', 'textarea', 'string'], true);
        }

        return true;
    }

    public function maxLength(): int
    {
        return $this->field === 'name' ? 200 : 20000;
    }

    /** Normalise and check a proposed value: null when it cannot be stored in this field. */
    public function accepts(mixed $value): ?string
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $this->maxLength()) {
            return null;
        }

        if (str_starts_with($this->field, 'attributes.')) {
            $type = $this->meta['type'] ?? 'text';
            if ($type === 'select') {
                $allowed = array_map(fn (array $o) => (string) $o['value'], $this->meta['options'] ?? []);

                return in_array($value, $allowed, true) ? $value : null;
            }
            if ($type === 'boolean') {
                return in_array(strtolower($value), ['true', 'false'], true) ? strtolower($value) : null;
            }
        }

        return $value;
    }

    /** Copy the accepted value into the human field (creating the E.Info entry when missing). */
    public function write(string $value, User $by): void
    {
        if ($this->record === null && $this->type === 'einfo_entry') {
            [$code, $itemId] = Targets::splitEinfoKey($this->key);
            $this->record = EinfoEntry::firstOrNew([
                'product_version_id' => $this->version->getKey(),
                'einfo' => $code,
                'inventory_item_id' => $itemId,
            ]);
        }
        if ($this->record === null) {
            throw AiException::invalid(__('ai.errors.target_gone'));
        }

        if (str_starts_with($this->field, 'attributes.')) {
            $attributes = $this->record->getAttribute('attributes') ?? [];
            $stored = ($this->meta['type'] ?? null) === 'boolean' ? $value === 'true' : $value;
            data_set($attributes, substr($this->field, 11), $stored);
            $this->record->setAttribute('attributes', $attributes);
        } else {
            $this->record->setAttribute($this->field, $value);
        }

        if (! $this->record instanceof Product && ! $this->record instanceof ProductVersion) {
            $this->record->setAttribute('updated_by', $by->getKey());
        }
        $this->record->save();
    }

    /** What a person reads above the proposal: "Asset AS-1 · Name". */
    public function label(): string
    {
        $pack = app(ContentPack::class);

        return match (true) {
            $this->record instanceof Product => __('ai.targets.product_description'),
            $this->type === 'product_version' => __('ai.targets.intended_use'),
            $this->record instanceof InventoryItem => $this->itemLabel($pack, $this->record),
            $this->record instanceof Evaluation => __('ai.targets.justification', ['requirement' => $this->record->requirement]),
            $this->record instanceof RiskEntry => __('ai.targets.risk', ['threat' => $this->record->threat, 'field' => __('ai.fields.'.$this->field)]),
            default => __('ai.targets.einfo', ['code' => Targets::splitEinfoKey($this->key)[0]]),
        };
    }

    private function itemLabel(ContentPack $pack, InventoryItem $item): string
    {
        $list = $pack->lists()[$item->list] ?? [];
        $field = str_starts_with($this->field, 'attributes.')
            ? Text::of($this->meta['label'] ?? substr($this->field, 11))
            : __('ai.fields.'.$this->field);

        return Text::of($list['singular'] ?? $item->list).' '.$item->label().' · '.$field;
    }
}
