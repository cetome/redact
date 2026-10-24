<?php

namespace App\Ai;

use App\Content\ContentPack;
use App\Enums\Origin;
use App\Models\AiSuggestion;
use App\Models\EinfoEntry;
use App\Models\FieldOrigin;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\RiskEntry;
use App\Models\User;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

/**
 * Where a human field's text comes from, and the only way AI output reaches a
 * human field: a person accepts a suggestion (CRAted's rule: AI output is never
 * written into the human channel by the AI itself).
 *
 * The marks follow CRAted: "AI Generated" / "AI Enhanced" while the text equals
 * what was accepted; after a person changes it, an AI-written text becomes
 * "Human Edited" and an AI-reworded text becomes the person's again ("Human").
 *
 * Scoped: one instance per request, so a page full of marks loads each once.
 */
#[Scoped]
class Provenance
{
    /** Features whose suggestions carry a value a person can accept. */
    public const ACCEPTABLE = [Feature::ImproveText, Feature::DraftFromDocuments];

    /** @var array<string, ?FieldOrigin> */
    private array $memo = [];

    /** @var array<string, ?string> version id => product id */
    private array $products = [];

    public function origin(string $type, string $key, string $field, ?string $currentValue): Origin
    {
        if (trim((string) $currentValue) === '') {
            return Origin::Human;
        }

        $mark = $this->markOf($type, $key, $field);
        if (! $mark) {
            return Origin::Human;
        }

        $origin = $mark->originFor($currentValue);
        if ($origin === Origin::HumanEdited && $mark->origin === Origin::AiEnhanced) {
            // A person's own text the AI reworded, changed again by a person: theirs again.
            return Origin::Human;
        }

        return $origin;
    }

    /**
     * The origin recorded when the field's text was accepted (null when nothing was), whatever was
     * done to the text since: tells an inventory text a person changed from an AI text they changed.
     */
    public function recorded(string $type, string $key, string $field): ?Origin
    {
        return $this->markOf($type, $key, $field)?->origin;
    }

    private function markOf(string $type, string $key, string $field): ?FieldOrigin
    {
        [$type, $key] = $this->canonical($type, $key, $field);
        $memoKey = $type.'|'.$key.'|'.$field;
        if (! array_key_exists($memoKey, $this->memo)) {
            $this->memo[$memoKey] = FieldOrigin::query()
                ->where('target_type', $type)->where('target_key', $key)->where('field', $field)->first();
        }

        return $this->memo[$memoKey];
    }

    /**
     * Copy the suggestion's value into its human field, remember its origin,
     * and record who decided. Throws AiException (422) when it cannot be accepted.
     */
    public function accept(AiSuggestion $suggestion, User $by): AiSuggestion
    {
        return DB::transaction(function () use ($suggestion, $by) {
            /** @var AiSuggestion $s */
            $s = AiSuggestion::query()->whereKey($suggestion->getKey())->lockForUpdate()->firstOrFail();
            if (! $s->isPending()) {
                throw AiException::invalid(__('ai.errors.already_decided'));
            }
            $feature = Feature::tryFrom($s->feature);
            if (! in_array($feature, self::ACCEPTABLE, true)) {
                throw AiException::invalid(__('ai.errors.not_acceptable'));
            }

            $version = $s->version;
            $payload = $s->payload ?? [];

            if (Targets::isNewKey($s->target_key)) {
                $created = match ($s->target_type) {
                    'inventory_item' => $this->createItem($version, $payload, $s, $by),
                    'risk_entry' => $this->createRisk($version, $payload, $s, $by),
                    default => throw AiException::invalid(__('ai.errors.target_not_allowed')),
                };
                $payload['created_id'] = $created;
                $s->payload = $payload;
            } else {
                $target = Targets::resolve($version, $s->target_type, $s->target_key, $s->field);
                $value = $target->accepts($payload['value'] ?? null);
                if ($value === null) {
                    throw AiException::invalid(__('ai.errors.value_invalid'));
                }
                $target->write($value, $by);
                $this->mark($target->originType(), $target->originKey(), $target->field, $s->origin ?? Origin::AiGenerated, $value, $s, $by);

                // Other proposals for the same field are now out of date.
                AiSuggestion::query()->where('product_version_id', $s->product_version_id)
                    ->where('target_type', $s->target_type)->where('target_key', $s->target_key)->where('field', $s->field)
                    ->where('status', 'pending')->whereKeyNot($s->getKey())
                    ->update(['status' => 'superseded', 'updated_at' => now()]);
            }

            $s->status = 'accepted';
            $s->forceFill(['decided_by' => $by->getKey(), 'decided_at' => now()])->save();

            activity('ai')->causedBy($by)->performedOn($s)->withProperties([
                'feature' => $s->feature, 'target_type' => $s->target_type, 'target_key' => $s->target_key, 'field' => $s->field,
            ])->log('suggestion accepted');

            return $s;
        });
    }

    public function reject(AiSuggestion $suggestion, User $by): AiSuggestion
    {
        return DB::transaction(function () use ($suggestion, $by) {
            /** @var AiSuggestion $s */
            $s = AiSuggestion::query()->whereKey($suggestion->getKey())->lockForUpdate()->firstOrFail();
            if (! $s->isPending()) {
                throw AiException::invalid(__('ai.errors.already_decided'));
            }
            $s->status = 'rejected';
            $s->forceFill(['decided_by' => $by->getKey(), 'decided_at' => now()])->save();

            activity('ai')->causedBy($by)->performedOn($s)->withProperties([
                'feature' => $s->feature, 'target_type' => $s->target_type, 'target_key' => $s->target_key, 'field' => $s->field,
            ])->log('suggestion rejected');

            return $s;
        });
    }

    /**
     * Accept every pending suggestion of one target ("Accept all").
     *
     * @return array{accepted: int, failed: int}
     */
    public function acceptGroup(ProductVersion $version, string $type, string $key, User $by): array
    {
        $accepted = 0;
        $failed = 0;
        $ids = AiSuggestion::query()->where('product_version_id', $version->getKey())
            ->where('target_type', $type)->where('target_key', $key)->where('status', 'pending')
            ->whereIn('feature', array_map(fn (Feature $f) => $f->value, self::ACCEPTABLE))
            ->orderBy('created_at')->pluck('id');

        foreach ($ids as $id) {
            $s = AiSuggestion::find($id);
            if (! $s || ! $s->isPending()) {
                continue;
            }
            try {
                $this->accept($s, $by);
                $accepted++;
            } catch (AiException) {
                $failed++;
            }
        }

        return ['accepted' => $accepted, 'failed' => $failed];
    }

    /** Remember that a human field now holds this AI text (or, for E.Info, this text from the inventory). */
    public function mark(string $type, string $key, string $field, Origin $origin, string $value, ?AiSuggestion $s, User $by): void
    {
        FieldOrigin::query()->updateOrCreate(
            ['target_type' => $type, 'target_key' => $key, 'field' => $field],
            ['origin' => $origin, 'value_hash' => FieldOrigin::hashValue($value), 'ai_suggestion_id' => $s?->getKey(), 'accepted_by' => $by->getKey()],
        );
        unset($this->memo[$type.'|'.$key.'|'.$field]);
    }

    /** @return array{0: string, 1: string} */
    private function canonical(string $type, string $key, string $field): array
    {
        if ($type === 'product_version' && $field === 'description') {
            if (! array_key_exists($key, $this->products)) {
                $this->products[$key] = ProductVersion::query()->whereKey($key)->value('product_id');
            }

            return ['product', (string) $this->products[$key]];
        }
        if ($type === 'einfo_entry' && ! str_contains($key, '|')) {
            // An entry id: marks are kept under "<code>|<item>", which exists before the entry does.
            $entry = EinfoEntry::query()->whereKey($key)->first(['einfo', 'inventory_item_id']);

            return [$type, $entry ? Targets::einfoKey($entry->einfo, $entry->inventory_item_id) : $key];
        }

        return [$type, $key];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createItem(ProductVersion $version, array $payload, AiSuggestion $s, User $by): string
    {
        $pack = app(ContentPack::class);
        $listKey = (string) ($payload['list'] ?? '');
        $list = $pack->lists()[$listKey] ?? null;
        $name = trim((string) ($payload['name'] ?? ''));
        if (! $list || $name === '') {
            throw AiException::invalid(__('ai.errors.value_invalid'));
        }

        $attributes = [];
        foreach ($list['fields'] ?? [] as $definition) {
            $raw = $payload['attributes'][$definition['key']] ?? null;
            if ($raw === null) {
                continue;
            }
            $probe = new Target($version, 'inventory_item', 'new', 'attributes.'.$definition['key'], null, $definition);
            $value = $probe->accepts($raw);
            if ($value !== null) {
                $attributes[$definition['key']] = ($definition['type'] ?? null) === 'boolean' ? $value === 'true' : $value;
            }
        }

        $existing = InventoryItem::query()->where('product_version_id', $version->getKey())->where('list', $listKey);
        $prefix = (string) ($list['code_prefix'] ?? strtoupper(substr($listKey, 0, 2)).'-');
        $number = 0;
        foreach ((clone $existing)->pluck('code') as $code) {
            if (str_starts_with($code, $prefix) && ctype_digit(substr($code, strlen($prefix)))) {
                $number = max($number, (int) substr($code, strlen($prefix)));
            }
        }
        $code = $prefix.($number + 1);
        while ((clone $existing)->where('code', $code)->exists()) {
            $code = $prefix.(++$number + 1);
        }

        $description = trim((string) ($payload['description'] ?? '')) ?: null;
        $item = InventoryItem::create([
            'product_version_id' => $version->getKey(),
            'list' => $listKey,
            'code' => $code,
            'name' => mb_substr($name, 0, 200),
            'description' => $description ? mb_substr($description, 0, 20000) : null,
            'attributes' => $attributes ?: null,
            'position' => (int) (clone $existing)->max('position') + 1,
            'updated_by' => $by->getKey(),
        ]);

        $this->mark('inventory_item', $item->id, 'name', Origin::AiGenerated, $item->name, $s, $by);
        if ($item->description) {
            $this->mark('inventory_item', $item->id, 'description', Origin::AiGenerated, $item->description, $s, $by);
        }

        return $item->id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createRisk(ProductVersion $version, array $payload, AiSuggestion $s, User $by): string
    {
        $threat = trim((string) ($payload['threat'] ?? ''));
        if ($threat === '') {
            throw AiException::invalid(__('ai.errors.value_invalid'));
        }
        $stride = array_values(array_intersect(RiskEntry::STRIDE, array_map('strval', (array) ($payload['stride'] ?? []))));
        $description = trim((string) ($payload['description'] ?? '')) ?: null;

        $risk = RiskEntry::create([
            'product_version_id' => $version->getKey(),
            'position' => (int) RiskEntry::query()->where('product_version_id', $version->getKey())->max('position') + 1,
            'threat' => mb_substr($threat, 0, 200),
            'description' => $description ? mb_substr($description, 0, 20000) : null,
            'stride' => $stride ?: null,
            'updated_by' => $by->getKey(),
        ]);

        $this->mark('risk_entry', $risk->id, 'threat', Origin::AiGenerated, $risk->threat, $s, $by);
        if ($risk->description) {
            $this->mark('risk_entry', $risk->id, 'description', Origin::AiGenerated, $risk->description, $s, $by);
        }

        return $risk->id;
    }
}
