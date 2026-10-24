<?php

namespace App\Assessment;

use App\Content\ContentPack;
use App\Content\Text;
use App\Content\VersionContext;
use App\Models\InventoryItem;

/**
 * The text an E.Info field starts with when the content pack fills it from the inventory
 * (einfo-prefill.json, e.g. the asset's description, the entities linked to it). Nothing is
 * stored here: the E.Info page shows the text, marked "From inventory", and a person saves it.
 *
 * Rules: {"from": "description"} = the object's own description; {"from": "links", "list": L,
 * "where": {field: value}} = the objects of list L linked to it through any relation of the pack
 * (either direction), filtered by attribute values, one "CODE · Name" per line. An optional
 * "claim" ({"en": …, "fr": …}, ":item" = the object's "CODE · Name") opens the text, so that what the
 * page offers reads as a statement about the product rather than a bare list.
 */
final class EinfoPrefill
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $rules = null;

    public function __construct(
        private readonly ContentPack $pack,
        private readonly VersionContext $context,
    ) {}

    /** Can this E.Info item be pre-filled at all? */
    public function has(string $code): bool
    {
        return isset($this->rules()[$code]);
    }

    /** The pre-filled text of one field, or null when the pack has no rule or the inventory nothing to give. */
    public function text(string $code, ?InventoryItem $item): ?string
    {
        $rule = $this->rules()[$code] ?? null;

        return $rule && $item ? $this->render($rule, $item) : null;
    }

    /** @param  array<string, mixed>  $rule */
    public function render(array $rule, InventoryItem $item): ?string
    {
        $text = match ($rule['from'] ?? null) {
            'description' => (string) $item->description,
            'links' => implode("\n", array_map(fn (InventoryItem $other) => $other->label(), $this->links($item, (string) ($rule['list'] ?? ''), (array) ($rule['where'] ?? [])))),
            default => '',
        };
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $claim = trim(Text::of($rule['claim'] ?? ''));

        return $claim === '' ? $text : strtr($claim, [':item' => $item->label()])."\n".$text;
    }

    /**
     * @param  array<string, mixed>  $where
     * @return list<InventoryItem>
     */
    private function links(InventoryItem $item, string $list, array $where): array
    {
        $found = [];
        foreach ($this->pack->relations() as $key => $relation) {
            $linked = match (true) {
                $relation['from'] === $item->list && $relation['to'] === $list => $this->context->linked($item, $key),
                $relation['to'] === $item->list && $relation['from'] === $list => $this->context->linked($item, $key, true),
                default => collect(),
            };
            foreach ($linked as $other) {
                if ($this->matches($other, $where)) {
                    $found[$other->id] = $other;
                }
            }
        }
        $found = array_values($found);
        usort($found, fn (InventoryItem $a, InventoryItem $b) => [$a->position, $a->code] <=> [$b->position, $b->code]);

        return $found;
    }

    /** @param  array<string, mixed>  $where */
    private function matches(InventoryItem $item, array $where): bool
    {
        foreach ($where as $field => $expected) {
            $value = $item->attr((string) $field);
            if (is_array($value) ? ! in_array($expected, $value, true) : $value !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, array<string, mixed>> */
    private function rules(): array
    {
        return $this->rules ??= $this->pack->einfoPrefill();
    }
}
