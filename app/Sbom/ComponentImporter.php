<?php

namespace App\Sbom;

use App\Assessment\InventoryFields;
use App\Content\ContentPack;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\SbomImport;
use App\Models\User;
use App\Vulnerabilities\ComponentObserver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compares the components of a bill of materials with the component list (preview), then
 * creates or updates the objects a person ticked.
 *
 * Matching, in order: same name and version (already present, or updated when the BOM brings
 * a supplier, purl, CPE or licence the object lacks); same package URL in another version; the
 * only object with that name (a version change). Anything else is new.
 */
final class ComponentImporter
{
    public function __construct(private readonly ContentPack $pack) {}

    /**
     * @param  list<Component>  $components
     * @return list<array{index: int, component: Component, status: string, item: InventoryItem|null, changes: array<string, array{0: string|null, 1: string|null}>}>
     */
    public function plan(ProductVersion $version, array $components): array
    {
        $items = $this->items($version);
        $byKey = $byPurl = $byName = [];
        foreach ($items as $item) {
            $byKey[Component::keyOf($item->name, (string) $item->attr('version'))] ??= $item;
            $purl = Identifiers::get($item, 'purl');
            if (is_string($purl)) {
                $byPurl[Identifiers::purlBase($purl)][] = $item;
            }
            $byName[mb_strtolower(trim((string) $item->name))][] = $item;
        }
        $namesInBom = array_count_values(array_map(fn (Component $c) => mb_strtolower($c->name), $components));

        $used = [];
        $rows = [];
        foreach ($components as $index => $component) {
            $item = $byKey[$component->key()] ?? null;
            if ($item === null && $component->purl !== null) {
                $item = collect($byPurl[Identifiers::purlBase($component->purl)] ?? [])->first(fn ($i) => ! isset($used[$i->id]));
            }
            $sameName = $byName[mb_strtolower($component->name)] ?? [];
            if ($item === null && count($sameName) === 1 && ($namesInBom[mb_strtolower($component->name)] ?? 0) === 1 && ! isset($used[$sameName[0]->id])) {
                $item = $sameName[0];
            }
            if ($item !== null && isset($used[$item->id])) {
                $item = null;
            }
            if ($item !== null) {
                $used[$item->id] = true;
            }
            $changes = $item ? $this->changes($item, $component) : [];
            $rows[] = [
                'index' => $index,
                'component' => $component,
                'status' => $item === null ? 'new' : ($changes === [] ? 'present' : 'updated'),
                'item' => $item,
                'changes' => $changes,
            ];
        }

        return $rows;
    }

    /**
     * What importing would change on an existing object: the version, and the details it lacks.
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public function changes(InventoryItem $item, Component $component): array
    {
        $changes = [];
        $version = $item->attr('version');
        if ($component->version !== null && Component::keyOf(null, $component->version) !== Component::keyOf(null, is_string($version) ? $version : null)) {
            $changes['version'] = [is_string($version) ? $version : null, $component->version];
        }
        if ($component->supplier !== null && blank($item->attr('reference'))) {
            $changes['supplier'] = [null, $component->supplier];
        }
        if (blank($item->attr('type'))) {
            $changes['type'] = [null, $component->type];
        }
        foreach (['purl', 'cpe', 'licence'] as $key) {
            $current = Identifiers::get($item, $key);
            if ($component->$key !== null && $current !== $component->$key) {
                $changes[$key] = [is_string($current) ? $current : null, $component->$key];
            }
        }

        return $changes;
    }

    /**
     * Create or update the ticked rows of an import.
     *
     * @param  list<int>  $selected  row indexes
     * @param  array<int, string>  $types  software | hardware per row (the preview lets people correct it)
     * @return array{created: int, updated: int, skipped: int, ids: list<string>}
     */
    public function apply(SbomImport $import, array $selected, array $types, User $user): array
    {
        $version = $import->version;
        $definition = $this->pack->lists()[SbomFeature::componentsList()];
        $components = array_map(fn ($row) => Component::fromArray($row), $import->components);
        $plan = $this->plan($version, $components);
        $selected = array_flip($selected);

        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'ids' => []];
        ComponentObserver::muted(function () use ($plan, $selected, $types, $version, $definition, $user, $import, &$summary) {
            DB::transaction(function () use ($plan, $selected, $types, $version, $definition, $user, $import, &$summary) {
                $codes = $version->inventoryItems()->where('list', $definition['key'])->pluck('code')->flip()->all();
                $position = (int) $version->inventoryItems()->where('list', $definition['key'])->max('position');
                foreach ($plan as $row) {
                    if (! isset($selected[$row['index']]) || $row['status'] === 'present') {
                        $summary['skipped']++;

                        continue;
                    }
                    $component = $row['component'];
                    $type = in_array($types[$row['index']] ?? null, ['software', 'hardware'], true) ? $types[$row['index']] : $component->type;

                    if ($row['item'] === null) {
                        $item = new InventoryItem([
                            'product_version_id' => $version->id,
                            'list' => $definition['key'],
                            'code' => $this->nextCode($definition, $codes),
                            'name' => $component->name,
                            'description' => $component->description,
                            'attributes' => InventoryFields::cast($definition, $this->attributes($definition, [
                                'type' => $type, 'version' => $component->version, 'reference' => $component->supplier,
                            ])),
                            'position' => ++$position,
                            'updated_by' => $user->id,
                        ]);
                        $summary['created']++;
                    } else {
                        $item = $row['item'];
                        $attributes = $item->getAttribute('attributes') ?? [];
                        $changes = $row['changes'];
                        if (isset($changes['version'])) {
                            $attributes['version'] = $component->version;
                        }
                        if (isset($changes['supplier'])) {
                            $attributes['reference'] = $component->supplier;
                        }
                        if (isset($changes['type']) || ($types[$row['index']] ?? null) !== null) {
                            $attributes['type'] = $type;
                        }
                        $item->fill([
                            'attributes' => $attributes,
                            'updated_by' => $user->id,
                        ]);
                        $summary['updated']++;
                    }
                    Identifiers::put($item, [
                        'purl' => $component->purl ?? Identifiers::get($item, 'purl'),
                        'cpe' => $component->cpe ?? Identifiers::get($item, 'cpe'),
                        'licence' => $component->licence ?? Identifiers::get($item, 'licence'),
                        'bom_ref' => $component->ref ?? Identifiers::get($item, 'bom_ref'),
                        'sbom_import' => $import->id,
                    ]);
                    $item->save();
                    $summary['ids'][] = $item->id;
                }
            });
        });

        return $summary;
    }

    /** @return Collection<int, InventoryItem> */
    private function items(ProductVersion $version): Collection
    {
        return $version->inventoryItems()->where('list', SbomFeature::componentsList())->get();
    }

    /**
     * Only the fields the pack's list really has.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function attributes(array $definition, array $values): array
    {
        $keys = array_column($definition['fields'] ?? [], 'key');

        return array_intersect_key($values, array_flip($keys));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, int>  $codes
     */
    private function nextCode(array $definition, array &$codes): string
    {
        $prefix = $definition['code_prefix'] ?? '';
        $n = count($codes) + 1;
        do {
            $code = $prefix.str_pad((string) $n++, 2, '0', STR_PAD_LEFT);
        } while (isset($codes[$code]));
        $codes[$code] = 1;

        return $code;
    }
}
