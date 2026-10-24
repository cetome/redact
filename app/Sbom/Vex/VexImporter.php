<?php

namespace App\Sbom\Vex;

use App\Ai\Provenance;
use App\Assessment\InventoryFields;
use App\Content\ContentPack;
use App\Enums\Origin;
use App\Models\InventoryItem;
use App\Models\InventoryLink;
use App\Models\ProductVersion;
use App\Models\User;
use App\Models\VexImport;
use App\Models\VexStatement;
use App\Models\VulnerabilityFinding;
use App\Sbom\Component;
use App\Sbom\Identifiers;
use App\Sbom\SbomFeature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compares VEX statements with the version (preview), then records the ticked ones.
 *
 * Component, in order: same package URL (with its version; a purl without version means every
 * version), same name and version, same bom-ref (a CycloneDX file's own reference, kept on the
 * components it imported). No match: the statement is about the product as a whole.
 * Vulnerability: the Known vulnerabilities object with one of its ids, created when there is
 * none (source "VEX: <file>"). Recording links it to the component, puts the matching findings
 * of the look-ups in the list, and fills an empty description with the VEX text ("From VEX")
 * when the manufacturer analysed it (not affected with a reason, or fixed).
 * Nothing else is decided: the decision trees stay as people answer them.
 */
final class VexImporter
{
    public function __construct(private readonly ContentPack $pack) {}

    /**
     * @param  list<array<string, mixed>>  $statements
     * @return list<array{key: string, statement: array<string, mixed>, component: InventoryItem|null, match: string|null, vulnerability: InventoryItem|null, previous: VexStatement|null, status: string}>
     */
    public function plan(ProductVersion $version, array $statements): array
    {
        $components = $version->inventoryItems()->where('list', SbomFeature::componentsList())->orderBy('position')->orderBy('code')->get();
        $vulnerabilities = $version->inventoryItems()->where('list', SbomFeature::vulnerabilitiesList())->get();
        $existing = VexStatement::query()->where('product_version_id', $version->id)->get();
        $index = $this->index($components);
        $added = VulnerabilityFinding::query()->where('product_version_id', $version->id)->whereNotNull('vulnerability_item_id')
            ->get(['key', 'aliases', 'vulnerability_item_id']);

        $rows = [];
        foreach ($statements as $i => $s) {
            [$matched, $how] = $this->components($index, (array) ($s['product'] ?? []));
            $ids = array_merge([(string) $s['id']], (array) ($s['aliases'] ?? []));
            $vulnerability = $this->vulnerability($vulnerabilities, $added, $ids);
            foreach ($matched ?: [null] as $component) {
                $previous = $existing->first(fn (VexStatement $v) => $v->inventory_item_id === $component?->id
                    && array_intersect(array_map('strtoupper', $v->ids()), array_map('strtoupper', $ids)) !== []);
                $same = $previous && $previous->status === $s['status'] && $previous->justification === ($s['justification'] ?? null)
                    && $previous->detail === ($s['detail'] ?? null) && $previous->action === ($s['action'] ?? null);
                $rows[] = [
                    'key' => $i.'-'.($component->id ?? 'p'),
                    'statement' => $s,
                    'component' => $component,
                    'match' => $component ? $how : null,
                    'vulnerability' => $vulnerability,
                    'previous' => $previous,
                    'status' => $previous === null ? 'new' : ($same ? 'same' : 'changed'),
                ];
            }
        }

        return $rows;
    }

    /**
     * Record the ticked rows of an import.
     *
     * @param  list<string>  $selected  row keys
     * @return array{recorded: int, created: int, linked: int, skipped: int}
     */
    public function apply(VexImport $import, array $selected, User $user): array
    {
        $version = $import->version;
        $plan = $this->plan($version, $import->statements);
        $selected = array_flip($selected);
        $definition = $this->pack->lists()[SbomFeature::vulnerabilitiesList()] ?? null;
        $summary = ['recorded' => 0, 'created' => 0, 'linked' => 0, 'skipped' => 0];
        if ($definition === null) {
            return $summary;
        }
        $provenance = app(Provenance::class);
        $fields = array_flip(array_column($definition['fields'] ?? [], 'key'));

        DB::transaction(function () use ($plan, $selected, $version, $definition, $fields, $user, $import, $provenance, &$summary) {
            $codes = $version->inventoryItems()->where('list', $definition['key'])->pluck('code')->flip()->all();
            $position = (int) $version->inventoryItems()->where('list', $definition['key'])->max('position');
            /** @var array<string, InventoryItem> $created */
            $created = [];
            /** @var array<string, InventoryItem> $touched */
            $touched = [];
            foreach ($plan as $row) {
                if (! isset($selected[$row['key']]) || $row['status'] === 'same') {
                    $summary['skipped']++;

                    continue;
                }
                $s = $row['statement'];
                $ids = array_values(array_unique(array_merge([(string) $s['id']], (array) ($s['aliases'] ?? []))));
                $item = $row['vulnerability'] ?? $created[$s['id']] ?? null;
                if ($item === null) {
                    $title = $s['title'] ?? null;
                    $item = new InventoryItem([
                        'product_version_id' => $version->id,
                        'list' => $definition['key'],
                        'code' => $this->nextCode($definition, $codes),
                        'name' => mb_substr($s['id'].(is_string($title) && mb_strlen($title) <= 120 ? ' · '.$title : ''), 0, 200),
                        'attributes' => InventoryFields::cast($definition, array_intersect_key([
                            'reference' => mb_substr(implode(', ', $ids), 0, 500),
                            'source' => mb_substr(__('sbom.vex.source', ['file' => $s['file']]), 0, 500),
                        ], $fields)),
                        'position' => ++$position,
                        'updated_by' => $user->id,
                    ]);
                    Identifiers::put($item, ['key' => $s['id'], 'ids' => $ids, 'sources' => ['vex']]);
                    $item->save();
                    $created[$s['id']] = $item;
                    $summary['created']++;
                }
                $touched[$item->id] = $item;

                $component = $row['component'];
                if ($component !== null) {
                    $link = InventoryLink::firstOrCreate([
                        'product_version_id' => $version->id,
                        'relation' => SbomFeature::relation(),
                        'from_item_id' => $item->id,
                        'to_item_id' => $component->id,
                    ]);
                    if ($link->wasRecentlyCreated) {
                        $summary['linked']++;
                    }
                    $this->addFindings($version, $component, $ids, $item, $user);
                }

                ($row['previous'] ?? new VexStatement)->fill([
                    'product_version_id' => $version->id,
                    'vulnerability_key' => $s['id'],
                    'aliases' => $s['aliases'] ?? [],
                    'vulnerability_item_id' => $item->id,
                    'inventory_item_id' => $component?->id,
                    'status' => $s['status'],
                    'justification' => $s['justification'] ?? null,
                    'detail' => $s['detail'] ?? null,
                    'action' => $s['action'] ?? null,
                    'format' => mb_substr((string) $s['format'], 0, 20),
                    'source_file' => mb_substr((string) $s['file'], 0, 200),
                    'vex_import_id' => $import->id,
                    'imported_by' => $user->id,
                    'imported_at' => now(),
                ])->save();
                $summary['recorded']++;
            }
            foreach ($touched as $item) {
                $this->describe($item, $provenance, $user);
            }
        });
        VexStatus::forget($version->id);

        return $summary;
    }

    /**
     * The description of a Known vulnerability from its VEX statements, when every one says
     * "not affected" with a reason or "fixed" (analysed): one line per component. A text a
     * person wrote or changed is never replaced; a VEX text nobody changed follows the VEX
     * (and goes when a statement says "affected" or "under investigation": still to do).
     */
    private function describe(InventoryItem $item, Provenance $provenance, User $user): void
    {
        $statements = VexStatement::query()->where('vulnerability_item_id', $item->id)->with('component:id,code,name')->orderBy('id')->get();
        $ours = filled($item->description) && $provenance->origin('inventory_item', $item->id, 'description', $item->description) === Origin::FromVex;
        if (filled($item->description) && ! $ours) {
            return;
        }
        $lines = [];
        if ($statements->isNotEmpty() && $statements->every(fn (VexStatement $s) => $s->analysed())) {
            foreach ($statements as $s) {
                $text = self::text(['status' => $s->status, 'justification' => $s->justification, 'detail' => $s->detail, 'action' => $s->action])
                    ?? __('sbom.vex.status.'.$s->status).'.';
                $lines[] = ($s->component && $statements->count() > 1 ? $s->component->code.' '.$s->component->name.': ' : '').$text;
            }
        }
        $text = $lines === [] ? null : mb_substr(implode("\n", array_unique($lines)), 0, 5000);
        if ($text === $item->description || ($text === null && ! $ours)) {
            return;
        }
        $item->forceFill(['description' => $text, 'updated_by' => $user->id])->save();
        if ($text !== null) {
            $provenance->mark('inventory_item', $item->id, 'description', Origin::FromVex, $text, null, $user);
        }
    }

    /**
     * The text a description takes from a statement: "Not affected: reason. Detail. Action."
     *
     * @param  array<string, mixed>  $s
     */
    public static function text(array $s): ?string
    {
        $reason = Statement::justificationLabel($s['justification'] ?? null);
        $parts = array_filter([$reason, $s['detail'] ?? null, $s['action'] ?? null], fn ($p) => is_string($p) && trim($p) !== '');
        if ($parts === []) {
            return null;
        }

        return __('sbom.vex.status.'.$s['status']).': '.implode("\n", array_map(fn ($p) => rtrim(trim($p), '.').'.', $parts));
    }

    /**
     * @param  Collection<int, InventoryItem>  $components
     * @return array{purl: array<string, list<InventoryItem>>, base: array<string, list<InventoryItem>>, key: array<string, list<InventoryItem>>, name: array<string, list<InventoryItem>>, ref: array<string, list<InventoryItem>>}
     */
    private function index(Collection $components): array
    {
        $index = ['purl' => [], 'base' => [], 'key' => [], 'name' => [], 'ref' => []];
        foreach ($components as $c) {
            $purl = Identifiers::get($c, 'purl');
            if (is_string($purl)) {
                $index['purl'][self::purlKey($purl)][] = $c;
                $index['base'][Identifiers::purlBase($purl)][] = $c;
            }
            $version = $c->attr('version');
            $index['key'][Component::keyOf($c->name, is_string($version) ? $version : null)][] = $c;
            $index['name'][Component::keyOf($c->name, null)][] = $c;
            $ref = Identifiers::get($c, 'bom_ref');
            if (is_string($ref)) {
                $index['ref'][$ref][] = $c;
            }
        }

        return $index;
    }

    /**
     * @param  array{purl: array<string, list<InventoryItem>>, base: array<string, list<InventoryItem>>, key: array<string, list<InventoryItem>>, name: array<string, list<InventoryItem>>, ref: array<string, list<InventoryItem>>}  $index
     * @param  array<string, mixed>  $product
     * @return array{0: list<InventoryItem>, 1: string|null}
     */
    private function components(array $index, array $product): array
    {
        $purl = is_string($product['purl'] ?? null) ? $product['purl'] : null;
        $version = is_string($product['version'] ?? null) ? $product['version'] : null;
        if ($purl !== null) {
            $found = $index['purl'][self::purlKey($purl)] ?? [];
            if ($found === []) {
                $same = $index['base'][Identifiers::purlBase($purl)] ?? [];
                $found = $version === null ? $same : array_values(array_filter($same, function (InventoryItem $c) use ($version) {
                    $own = $c->attr('version');

                    return Component::keyOf(null, is_string($own) ? $own : Identifiers::versionFromPurl((string) Identifiers::get($c, 'purl'))) === Component::keyOf(null, $version);
                }));
            }
            if ($found !== []) {
                return [$found, 'purl'];
            }
        }
        $name = is_string($product['name'] ?? null) ? $product['name'] : null;
        if ($name !== null) {
            $found = $version !== null ? ($index['key'][Component::keyOf($name, $version)] ?? []) : ($index['name'][Component::keyOf($name, null)] ?? []);
            if ($found !== []) {
                return [$found, 'name'];
            }
        }
        $ref = is_string($product['ref'] ?? null) ? $product['ref'] : null;
        if ($ref !== null && isset($index['ref'][$ref])) {
            return [$index['ref'][$ref], 'ref'];
        }

        return [[], null];
    }

    /** The purl without qualifiers or subpath, lower case: the same package and version. */
    private static function purlKey(string $purl): string
    {
        return strtolower((string) preg_replace('/[?#].*$/', '', $purl));
    }

    /**
     * The Known vulnerabilities object for these ids: added from a look-up, or with one of them
     * as its key or in its identifier or name.
     *
     * @param  Collection<int, InventoryItem>  $items
     * @param  Collection<int, VulnerabilityFinding>  $added
     * @param  list<string>  $ids
     */
    private function vulnerability(Collection $items, Collection $added, array $ids): ?InventoryItem
    {
        $ids = array_values(array_unique(array_map('strtoupper', array_filter($ids))));
        $finding = $added->first(fn (VulnerabilityFinding $f) => array_intersect(array_map('strtoupper', $f->ids()), $ids) !== []);
        if ($finding && ($item = $items->firstWhere('id', $finding->vulnerability_item_id))) {
            return $item;
        }

        return $items->first(function (InventoryItem $item) use ($ids) {
            $own = array_map('strtoupper', array_merge([(string) Identifiers::get($item, 'key')], (array) (Identifiers::get($item, 'ids') ?? [])));
            if (array_intersect($own, $ids) !== []) {
                return true;
            }
            $text = strtoupper((string) $item->attr('reference').' '.$item->name);
            foreach ($ids as $id) {
                if (preg_match('/(?<![A-Z0-9-])'.preg_quote($id, '/').'(?![0-9])/', $text)) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * The look-up findings of this component for this vulnerability go to the list with it.
     *
     * @param  list<string>  $ids
     */
    private function addFindings(ProductVersion $version, InventoryItem $component, array $ids, InventoryItem $item, User $user): void
    {
        $upper = array_map('strtoupper', $ids);
        $findings = VulnerabilityFinding::query()->where('product_version_id', $version->id)->where('inventory_item_id', $component->id)
            ->where('status', '!=', 'added')->get()
            ->filter(fn (VulnerabilityFinding $f) => array_intersect(array_map('strtoupper', $f->ids()), $upper) !== []);
        foreach ($findings as $finding) {
            $history = $finding->history ?? [];
            $history[] = ['status' => 'added', 'reason' => 'VEX', 'by' => $user->id, 'by_name' => $user->name, 'at' => now()->toIso8601String()];
            $finding->forceFill([
                'status' => 'added',
                'vulnerability_item_id' => $item->id,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'reason' => null,
                'history' => array_slice($history, -20),
            ])->save();
        }
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
