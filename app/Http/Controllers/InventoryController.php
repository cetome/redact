<?php

namespace App\Http\Controllers;

use App\Assessment\Assessment;
use App\Assessment\CatalogueSuggestions;
use App\Assessment\InventoryFields;
use App\Assessment\InventoryGuide;
use App\Content\ContentPack;
use App\Content\Text;
use App\Models\EinfoEntry;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Inventory root lists (assets, interfaces, entities, ...) and the mappings
 * between them, which replace the Excel "Mapping_*" matrices.
 */
class InventoryController extends Controller
{
    /**
     * The list, or with a panel open: /new (add), /prefill ("Prefill everything"), /mappings[/{relation}]
     * (the mapping view) and /{item} (edit(): one record's side panel). Path segments, never a query.
     */
    public function index(Request $request, ProductVersion $version, string $list, ContentPack $pack, ?InventoryItem $open = null): View
    {
        $panel = (string) $request->route('panel');
        $access = $this->access($version->product);
        abort_unless($access->canView(), 404);
        $definition = $pack->lists()[$list] ?? abort(404);
        $assessment = Assessment::for($version);
        $guide = new InventoryGuide($assessment, $list);

        $items = $version->inventoryItems()->where('list', $list)->get();
        $counts = $version->inventoryItems()->selectRaw('list, count(*) as n')->groupBy('list')->pluck('n', 'list');
        $links = $version->inventoryLinks()->get(['relation', 'from_item_id', 'to_item_id']);

        // Relations touching this list: how many links each item has, for the table.
        $relations = [];
        foreach ($guide->relations() as $key => $relation) {
            if ($relation['from'] !== $list && $relation['to'] !== $list) {
                continue;
            }
            $reverse = $relation['from'] !== $list;
            $relations[$key] = [
                'definition' => $relation,
                'reverse' => $reverse,
                'other' => $pack->lists()[$reverse ? $relation['from'] : $relation['to']],
                'counts' => $links->where('relation', $key)->countBy($reverse ? 'to_item_id' : 'from_item_id')->all(),
            ];
        }

        // The mapping view: one relation as a matrix, rows = its "from" list, columns = its "to" list.
        $view = $panel === 'mappings' ? 'mappings' : 'list';
        $matrix = null;
        if ($view === 'mappings' && $guide->relations() === []) {
            $view = 'list';
        }
        if ($view === 'mappings') {
            $relationKey = array_key_exists((string) $request->route('relation'), $guide->relations())
                ? (string) $request->route('relation') : (string) array_key_first($guide->relations());
            $relation = $guide->relations()[$relationKey];
            $set = [];
            foreach ($links->where('relation', $relationKey) as $link) {
                $set[$link->from_item_id][$link->to_item_id] = true;
            }
            $matrix = [
                'key' => $relationKey,
                'definition' => $relation,
                'from' => $pack->lists()[$relation['from']],
                'to' => $pack->lists()[$relation['to']],
                'rows' => $version->inventoryItems()->where('list', $relation['from'])->get(),
                'columns' => $version->inventoryItems()->where('list', $relation['to'])->get(),
                'links' => $set,
                'counts' => $links->countBy('relation')->all(),
            ];
        }

        // The record open in the side panel (/{item}), or the one whose save just failed.
        $form = (string) old('_form');
        $editId = $form !== '' && $form !== 'add' ? $form : $open?->id;
        $editing = is_string($editId) ? $items->firstWhere('id', $editId) : null;

        return view('inventory.index', [
            'version' => $version,
            'access' => $access,
            'lists' => $pack->lists(),
            'list' => $definition,
            'items' => $items,
            'counts' => $counts,
            'relations' => $relations,
            'allRelations' => $guide->relations(),
            'guide' => $guide,
            'none' => $items->isEmpty() && in_array($list, $version->noneLists(), true),
            // The asset type cards grey the types whose parts are out of scope (once the scoping is answered).
            'scopeStandards' => $version->scopingAnswers()->exists() ? $assessment->standards() : null,
            'standardNames' => array_map(fn (array $s) => Text::of($s['name'] ?? $s['code']), $pack->standards()),
            'linkTotal' => $links->count(),
            'view' => $view,
            'matrix' => $matrix,
            'editing' => $editing,
            'adding' => $form === 'add' || $panel === 'new',
            'catalogueCount' => count($pack->catalogue($list)),
            'newIds' => (array) session('inventory.new', []),
            'nextCode' => $this->nextCode($version, $definition),
        ]);
    }

    /** One record in the side panel of its list (without script; data-dialog-fetch loads it in place). */
    public function edit(Request $request, ProductVersion $version, string $list, InventoryItem $item, ContentPack $pack): View
    {
        abort_unless($item->product_version_id === $version->id && $item->list === $list, 404);

        return $this->index($request, $version, $list, $pack, $item);
    }

    /**
     * The catalogue picker of one list: typical objects from the pack, the ones that fit the
     * scoping pre-ticked, grouped by the list's main choice. Posts to store() (catalogue[]).
     */
    public function pick(ProductVersion $version, string $list, ContentPack $pack): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canEdit($version), 404);
        $definition = $pack->lists()[$list] ?? abort(404);

        $assessment = Assessment::for($version);
        $rows = (new CatalogueSuggestions($assessment))->forList($list);
        $guide = new InventoryGuide($assessment, $list);

        return view('inventory.pick', [
            'guide' => $guide,
            'continue' => $guide->continueTarget($list),
            'version' => $version,
            'access' => $access,
            'list' => $definition,
            'rows' => $rows,
            'proposals' => CatalogueSuggestions::proposals($rows),
            'groupTemplate' => $this->defaultGroupName($definition, ':count'),
        ]);
    }

    public function store(Request $request, ProductVersion $version, string $list, ContentPack $pack): RedirectResponse
    {
        $this->allow('edit', $version);
        $definition = $pack->lists()[$list] ?? abort(404);

        if ($request->has('catalogue')) {
            return $this->storeFromCatalogue($request, $version, $definition, $pack);
        }
        // The picker sent with nothing ticked: "Skip to <next list>", or nothing to add.
        if ($request->boolean('picker')) {
            return $request->boolean('continue')
                ? redirect()->to((new InventoryGuide(Assessment::for($version), $list))->continueUrl($list, picker: true))
                : back()->with('error', __('inventory.pick_nothing'));
        }

        $data = $this->validated($request, $version, $definition);
        $version->inventoryItems()->create([
            'list' => $list,
            'code' => $data['code'],
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'attributes' => InventoryFields::cast($definition, $data['attributes'] ?? []),
            'position' => (int) $version->inventoryItems()->where('list', $list)->max('position') + 1,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('versions.inventory', [$version, $list])
            ->with('status', __('inventory.added', ['item' => Text::of($definition['singular'] ?? $list)]));
    }

    public function update(Request $request, ProductVersion $version, string $list, InventoryItem $item, ContentPack $pack): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($item->product_version_id === $version->id && $item->list === $list, 404);
        $definition = $pack->lists()[$list] ?? abort(404);

        $data = $this->validated($request, $version, $definition, $item);
        $changes = [
            'code' => $data['code'],
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'attributes' => InventoryFields::cast($definition, $data['attributes'] ?? []),
            'updated_by' => $request->user()->id,
        ];
        // A group's members, one name per line: the ones kept keep what they carried.
        if ($request->has('members_text')) {
            $request->validate(['members_text' => ['nullable', 'string', 'max:20000']]);
            $kept = collect($item->memberList())->keyBy(fn (array $m) => mb_strtolower(trim($m['name'])));
            $members = [];
            foreach (preg_split('/\R/u', (string) $request->input('members_text')) ?: [] as $line) {
                $name = mb_substr(trim($line), 0, 200);
                $key = mb_strtolower($name);
                if ($name !== '' && ! isset($members[$key])) {
                    $members[$key] = ['name' => $name] + ($kept[$key] ?? []);
                }
            }
            $changes['members'] = $members === [] ? null : array_values($members);
        }
        $item->update($changes);

        return redirect()->to(route('versions.inventory', [$version, $list]).'#item-'.$item->id)
            ->with('status', __('common.saved'));
    }

    public function destroy(ProductVersion $version, string $list, InventoryItem $item): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($item->product_version_id === $version->id && $item->list === $list, 404);

        // Evaluations, E.Info texts and links of the item go with it (database cascades).
        $item->delete();

        return redirect()->route('versions.inventory', [$version, $list])->with('status', __('inventory.deleted'));
    }

    /**
     * Turn several objects of a list into one group (EN 18031: same use case and protection
     * level, handled as one). The group carries the values they share and the union of their
     * mappings; each member keeps its name, description, code and attributes, so ungrouping gives
     * them back. Refused while any of them has decision-tree walks or E.Info texts: those were
     * written for one object and cannot be merged without losing someone's work.
     */
    public function group(Request $request, ProductVersion $version, string $list, ContentPack $pack): RedirectResponse
    {
        $this->allow('edit', $version);
        $definition = $pack->lists()[$list] ?? abort(404);
        $data = $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*' => ['string', 'max:40'],
            'name' => ['nullable', 'string', 'max:200'],
        ]);

        $items = $version->inventoryItems()->where('list', $list)->whereIn('id', $data['items'])->orderBy('position')->orderBy('code')->get();
        $back = redirect()->route('versions.inventory', [$version, $list]);
        if ($items->count() < 2) {
            return $back->with('error', __('inventory.group_needs_two'));
        }
        if (($busy = $this->withWork($items)) !== '') {
            return $back->with('error', __('inventory.group_refused', ['items' => $busy]));
        }

        $members = [];
        foreach ($items as $item) {
            array_push($members, ...($item->isGroup() ? $item->memberList() : [$this->memberOf($item)]));
        }
        $sets = $items->map(fn (InventoryItem $i) => (array) $i->getAttribute('attributes'))->values()->all();
        $name = trim((string) ($data['name'] ?? '')) ?: $this->proposedGroupName($pack, $definition, $sets);

        $group = DB::transaction(function () use ($version, $list, $definition, $items, $members, $sets, $name, $request) {
            $ids = $items->pluck('id')->all();
            $links = $this->linksOf($version, $ids);
            $first = $items->first();
            $position = (int) $items->min('position');
            // Deleting first frees the code; their links go with them (cascade) and are rebuilt below.
            $items->each->delete();
            $group = $version->inventoryItems()->create([
                'list' => $list,
                'code' => (string) $first->code,
                'name' => $name,
                'attributes' => InventoryFields::cast($definition, CatalogueSuggestions::commonAttributes($sets)),
                'members' => $members,
                'position' => $position,
                'updated_by' => $request->user()->id,
            ]);
            $this->relink($version, $links, array_fill_keys($ids, [$group->id]));

            activity('inventory')->performedOn($group)->causedBy($request->user())
                ->withProperties(['members' => $items->map(fn (InventoryItem $i) => $i->label())->all(), 'links' => count($links)])
                ->log('grouped');

            return $group;
        });

        return redirect()->to(route('versions.inventory', [$version, $list]).'#item-'.$group->id)
            ->with('status', __('inventory.grouped', ['name' => $name, 'count' => count($members)]))->with('inventory.new', [$group->id]);
    }

    /**
     * Split a group back into one object per member. Each gets its former code when still free,
     * the group's values (completed by the member's own where the group leaves a value empty)
     * and a copy of every mapping of the group. Refused while the group has decision-tree walks
     * or E.Info texts.
     */
    public function ungroup(Request $request, ProductVersion $version, string $list, InventoryItem $item, ContentPack $pack): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($item->product_version_id === $version->id && $item->list === $list, 404);
        $definition = $pack->lists()[$list] ?? abort(404);
        $back = redirect()->route('versions.inventory', [$version, $list]);
        if (! $item->isGroup()) {
            return $back->with('error', __('inventory.not_a_group'));
        }
        if ($this->withWork(collect([$item])) !== '') {
            return $back->with('error', __('inventory.ungroup_refused', ['item' => $item->code]));
        }

        $ids = DB::transaction(function () use ($version, $list, $definition, $item, $request) {
            $links = $this->linksOf($version, [$item->id]);
            $members = $item->memberList();
            $groupValues = (array) $item->getAttribute('attributes');
            $version->inventoryItems()->where('list', $list)->where('position', '>', $item->position)->increment('position', count($members) - 1);
            $item->delete();

            $ids = [];
            foreach ($members as $n => $member) {
                $values = $groupValues;
                foreach ((array) ($member['attributes'] ?? []) as $key => $value) {
                    if (in_array($values[$key] ?? null, [null, '', [], false], true)) {
                        $values[$key] = $value;
                    }
                }
                $code = (string) ($member['code'] ?? '');
                $taken = $code === '' || $version->inventoryItems()->where('list', $list)->where('code', $code)->exists();
                $ids[] = $version->inventoryItems()->create([
                    'list' => $list,
                    'code' => $taken ? $this->nextCode($version, $definition) : $code,
                    'name' => $member['name'],
                    'description' => ($member['description'] ?? null) ?: null,
                    'attributes' => InventoryFields::cast($definition, $values),
                    'position' => $item->position + $n,
                    'updated_by' => $request->user()->id,
                ])->id;
            }
            $this->relink($version, $links, [$item->id => $ids]);

            activity('inventory')->causedBy($request->user())
                ->withProperties(['group' => $item->label(), 'members' => array_column($members, 'name'), 'links' => count($links)])
                ->log('ungrouped');

            return $ids;
        });

        return $back->with('status', trans_choice('inventory.ungrouped', count($ids), ['count' => count($ids)]))->with('inventory.new', $ids);
    }

    /**
     * Replace the links of one relation for the items shown on the page.
     * Input: links[<item on this page>][] = <item on the other side>.
     */
    public function updateLinks(Request $request, ProductVersion $version, string $relation, ContentPack $pack): RedirectResponse|JsonResponse
    {
        $this->allow('edit', $version);
        $definition = $pack->relations()[$relation] ?? abort(404);

        $data = $request->validate([
            'side' => ['required', Rule::in(['from', 'to'])],
            'rows' => ['array'],
            'rows.*' => ['string'],
            'links' => ['array'],
            'links.*' => ['array'],
            'links.*.*' => ['string'],
        ]);

        $rowList = $data['side'] === 'from' ? $definition['from'] : $definition['to'];
        $otherList = $data['side'] === 'from' ? $definition['to'] : $definition['from'];
        $rowIds = $version->inventoryItems()->where('list', $rowList)->whereIn('id', $data['rows'] ?? [])->pluck('id')->all();
        $otherIds = $version->inventoryItems()->where('list', $otherList)->pluck('id')->flip();

        DB::transaction(function () use ($version, $relation, $data, $rowIds, $otherIds) {
            $column = $data['side'] === 'from' ? 'from_item_id' : 'to_item_id';
            $version->inventoryLinks()->where('relation', $relation)->whereIn($column, $rowIds)->delete();

            foreach ($rowIds as $rowId) {
                foreach (array_unique($data['links'][$rowId] ?? []) as $otherId) {
                    if (! isset($otherIds[$otherId])) {
                        continue;
                    }
                    $version->inventoryLinks()->create([
                        'relation' => $relation,
                        'from_item_id' => $data['side'] === 'from' ? $rowId : $otherId,
                        'to_item_id' => $data['side'] === 'from' ? $otherId : $rowId,
                    ]);
                }
            }
        });

        if ($request->expectsJson()) {
            return response()->json(['saved' => true, 'message' => __('inventory.links_saved')]);
        }

        return back()->with('status', __('inventory.links_saved'));
    }

    /**
     * Add several objects of the pack's catalogue at once. An entry without a required choice is
     * added all the same and shows "To complete" in the list.
     *
     * @param  array<string, mixed>  $definition
     */
    private function storeFromCatalogue(Request $request, ProductVersion $version, array $definition, ContentPack $pack): RedirectResponse
    {
        $catalogue = $pack->catalogue($definition['key']);
        $data = $request->validate([
            'catalogue' => ['required', 'array', 'max:300'],
            'catalogue.*' => ['integer', 'min:0', 'max:'.max(count($catalogue) - 1, 0)],
            'as_group' => ['nullable', 'boolean'],
            'group_name' => ['nullable', 'string', 'max:200'],
            'return' => ['nullable', Rule::in(['pick'])],
            'continue' => ['nullable', 'boolean'],
        ]);
        $required = collect($definition['fields'] ?? [])->filter(fn (array $f) => ! empty($f['required']))->pluck('key')->all();
        if ($request->boolean('as_group')) {
            return $this->storeCatalogueGroup($request, $version, $definition, $pack, $data, $required);
        }

        $ids = [];
        $incomplete = 0;
        DB::transaction(function () use ($data, $catalogue, $definition, $required, $version, $request, &$ids, &$incomplete) {
            $position = (int) $version->inventoryItems()->where('list', $definition['key'])->max('position');
            foreach (array_unique($data['catalogue']) as $index) {
                $entry = $catalogue[(int) $index];
                $attributes = InventoryFields::cast($definition, $entry['attributes'] ?? []);
                if (array_filter($required, fn (string $key) => in_array($attributes[$key] ?? null, [null, '', []], true)) !== []) {
                    $incomplete++;
                }
                $ids[] = $version->inventoryItems()->create([
                    'list' => $definition['key'],
                    'code' => $this->nextCode($version, $definition),
                    'name' => Text::of($entry['name'] ?? ''),
                    'description' => Text::of($entry['description'] ?? '') ?: null,
                    'attributes' => $attributes,
                    'position' => ++$position,
                    'updated_by' => $request->user()->id,
                ])->id;
            }
        });

        $added = count($ids);
        $message = trans_choice('inventory.added_many', $added, ['count' => $added]);
        if ($incomplete > 0) {
            $message .= ' '.trans_choice('inventory.added_incomplete', $incomplete, ['count' => $incomplete]);
        }

        // "Add and continue": straight on to the next list (its picker while it is still empty).
        if ($request->boolean('continue')) {
            return redirect()->to((new InventoryGuide(Assessment::for($version), $definition['key']))->continueUrl($definition['key'], picker: true))
                ->with('status', $message);
        }

        return redirect()->route('versions.inventory', [$version, $definition['key']])
            ->with('status', $message)->with('inventory.new', $ids);
    }

    /**
     * "This product has none" (no logged events, no known vulnerabilities…): the list counts as
     * done for this version. Refused while the list has objects. Goes on to the next sub-step.
     */
    public function markNone(Request $request, ProductVersion $version, string $list, ContentPack $pack): RedirectResponse
    {
        $this->allow('edit', $version);
        $definition = $pack->lists()[$list] ?? abort(404);
        if ($version->inventoryItems()->where('list', $list)->exists()) {
            return redirect()->route('versions.inventory', [$version, $list])->with('error', __('inventory.none_has_items'));
        }

        if (! in_array($list, $version->noneLists(), true)) {
            $version->inventory_none = [...$version->noneLists(), $list];
            $version->save();
            activity('inventory')->performedOn($version)->causedBy($request->user())->withProperties(['list' => $list])->log('marked none');
        }
        $guide = new InventoryGuide(Assessment::for($version), $list);

        return redirect()->to($guide->continueUrl($list))
            ->with('status', __('inventory.none_marked', ['list' => mb_strtolower(Text::of($definition['label'] ?? $list))]));
    }

    /**
     * "Prefill everything": in one go, for every list of the sequence not marked none, the
     * catalogue items linked to the scoping answers (what each picker pre-ticks) that are not in
     * the inventory yet. Plain items, no groups. Recomputed here, never taken from the browser.
     */
    public function prefill(Request $request, ProductVersion $version, ContentPack $pack): RedirectResponse
    {
        $this->allow('edit', $version);
        $guide = new InventoryGuide(Assessment::for($version));
        $plan = $guide->prefillPlan();
        $first = route('versions.inventory', [$version, $guide->listKeys()[0] ?? array_key_first($pack->lists())]);
        if ($plan['total'] === 0) {
            return redirect()->to($first)->with('status', __('inventory.prefill_nothing'));
        }

        // Only the items the person kept chosen ("list:index" of a proposal), nothing else.
        $proposed = [];
        foreach ($plan['lists'] as $key => $entry) {
            foreach ($entry['rows'] as $row) {
                $proposed[] = $key.':'.$row['index'];
            }
        }
        $chosen = $request->validate([
            'items' => ['nullable', 'array', 'max:2000'],
            'items.*' => ['string', Rule::in($proposed)],
        ])['items'] ?? [];
        if ($chosen === []) {
            return back()->with('error', __('inventory.prefill_nothing_chosen'));
        }
        $chosen = array_flip($chosen);

        $added = DB::transaction(function () use ($version, $pack, $plan, $request, $chosen, $proposed) {
            $added = [];
            foreach ($plan['lists'] as $key => $entry) {
                $definition = $pack->lists()[$key];
                $position = (int) $version->inventoryItems()->where('list', $key)->max('position');
                foreach ($entry['rows'] as $row) {
                    if (! isset($chosen[$key.':'.$row['index']])) {
                        continue;
                    }
                    $item = $row['item'];
                    $version->inventoryItems()->create([
                        'list' => $key,
                        'code' => $this->nextCode($version, $definition),
                        'name' => Text::of($item['name'] ?? ''),
                        'description' => Text::of($item['description'] ?? '') ?: null,
                        'attributes' => InventoryFields::cast($definition, $item['attributes'] ?? []),
                        'position' => ++$position,
                        'updated_by' => $request->user()->id,
                    ]);
                    $added[$key] = ($added[$key] ?? 0) + 1;
                }
            }
            activity('inventory')->performedOn($version)->causedBy($request->user())
                ->withProperties(['added' => $added, 'left_out' => count($proposed) - count($chosen), 'skipped_none' => $plan['skipped']])->log('prefilled');

            return $added;
        });

        $total = array_sum($added);
        $message = trans_choice('inventory.prefill_done', $total, ['count' => $total, 'lists' => trans_choice('inventory.prefill_lists', count($added), ['count' => count($added)])]);
        if ($plan['skipped'] !== []) {
            $message .= ' '.__('inventory.prefill_skipped', ['lists' => mb_strtolower(implode(', ', $plan['skipped']))]);
        }

        return redirect()->to($first)->with('status', $message);
    }

    /** Undo "This product has none": the list is to fill again. */
    public function undoNone(Request $request, ProductVersion $version, string $list, ContentPack $pack): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless(isset($pack->lists()[$list]), 404);

        if (in_array($list, $version->noneLists(), true)) {
            $version->inventory_none = array_values(array_diff($version->noneLists(), [$list])) ?: null;
            $version->save();
            activity('inventory')->performedOn($version)->causedBy($request->user())->withProperties(['list' => $list])->log('none undone');
        }

        return redirect()->route('versions.inventory', [$version, $list])->with('status', __('inventory.none_undone'));
    }

    /**
     * Several catalogue entries added as ONE group: the values they share, each entry a member.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $data
     * @param  list<string>  $required
     */
    private function storeCatalogueGroup(Request $request, ProductVersion $version, array $definition, ContentPack $pack, array $data, array $required): RedirectResponse
    {
        $catalogue = $pack->catalogue($definition['key']);
        $indexes = array_values(array_unique(array_map('intval', $data['catalogue'])));
        if (count($indexes) < 2) {
            return back()->with('error', __('inventory.group_needs_two'));
        }

        $members = [];
        $sets = [];
        foreach ($indexes as $index) {
            $entry = $catalogue[$index];
            $sets[] = (array) ($entry['attributes'] ?? []);
            $members[] = array_filter([
                'name' => Text::of($entry['name'] ?? ''),
                'description' => Text::of($entry['description'] ?? '') ?: null,
                'attributes' => self::meaningful(InventoryFields::cast($definition, $entry['attributes'] ?? [])),
            ], fn ($v) => $v !== null && $v !== []);
        }
        $attributes = InventoryFields::cast($definition, CatalogueSuggestions::commonAttributes($sets));
        $name = trim((string) ($data['group_name'] ?? '')) ?: $this->proposedGroupName($pack, $definition, $sets);

        $group = $version->inventoryItems()->create([
            'list' => $definition['key'],
            'code' => $this->nextCode($version, $definition),
            'name' => $name,
            'attributes' => $attributes,
            'members' => $members,
            'position' => (int) $version->inventoryItems()->where('list', $definition['key'])->max('position') + 1,
            'updated_by' => $request->user()->id,
        ]);

        $message = __('inventory.group_added', ['name' => $name, 'count' => count($members)]);
        if (array_filter($required, fn (string $key) => in_array($attributes[$key] ?? null, [null, '', []], true)) !== []) {
            $message .= ' '.trans_choice('inventory.added_incomplete', 1, ['count' => 1]);
        }
        $route = ($data['return'] ?? null) === 'pick' ? 'versions.inventory.pick' : 'versions.inventory';

        return redirect()->route($route, [$version, $definition['key']])->with('status', $message)->with('inventory.new', [$group->id]);
    }

    /**
     * A name for a group: the pack's name when every object falls in the same named group
     * (e.g. "Security functions"), else "Group of 3 assets".
     *
     * @param  array<string, mixed>  $definition
     * @param  list<array<string, mixed>>  $sets  attributes of the objects
     */
    private function proposedGroupName(ContentPack $pack, array $definition, array $sets): string
    {
        $groups = $pack->catalogueGroups($definition['key']);
        $named = array_map(fn (array $set) => CatalogueSuggestions::groupFor($groups, $set), $sets);
        $keys = array_unique(array_map(fn (?array $g) => $g['key'] ?? '', $named));
        if (count($keys) === 1 && $named[0] !== null) {
            return Text::of($named[0]['name'] ?? $named[0]['key']);
        }

        return $this->defaultGroupName($definition, (string) count($sets));
    }

    /** @param  array<string, mixed>  $definition */
    private function defaultGroupName(array $definition, string $count): string
    {
        return __('inventory.group_default', ['count' => $count, 'list' => mb_strtolower(Text::of($definition['label'] ?? $definition['key']))]);
    }

    /**
     * What a member keeps of an object, so that ungrouping gives it back.
     *
     * @return array<string, mixed>
     */
    private function memberOf(InventoryItem $item): array
    {
        return array_filter([
            'name' => (string) ($item->name ?: $item->code),
            'description' => $item->description ?: null,
            'code' => (string) $item->code,
            'attributes' => self::meaningful((array) $item->getAttribute('attributes')),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> the values actually given (no empty, no unticked)
     */
    private static function meaningful(array $values): array
    {
        return array_filter($values, fn ($v) => ! in_array($v, [null, '', [], false], true));
    }

    /**
     * Codes of the items that already have decision-tree walks or E.Info texts ('' when none).
     *
     * @param  Collection<int, InventoryItem>  $items
     */
    private function withWork(Collection $items): string
    {
        $ids = $items->pluck('id')->all();
        $busy = array_unique([
            ...Evaluation::query()->whereIn('inventory_item_id', $ids)->pluck('inventory_item_id')->all(),
            ...EinfoEntry::query()->whereIn('inventory_item_id', $ids)->pluck('inventory_item_id')->all(),
        ]);

        return $items->whereIn('id', $busy)->pluck('code')->implode(', ');
    }

    /**
     * The links touching some items, as plain rows to rebuild once the items are replaced.
     *
     * @param  list<string>  $ids
     * @return list<array{relation: string, from: string, to: string}>
     */
    private function linksOf(ProductVersion $version, array $ids): array
    {
        return $version->inventoryLinks()->where(fn ($q) => $q->whereIn('from_item_id', $ids)->orWhereIn('to_item_id', $ids))
            ->get(['relation', 'from_item_id', 'to_item_id'])
            ->map(fn ($l) => ['relation' => (string) $l->relation, 'from' => (string) $l->from_item_id, 'to' => (string) $l->to_item_id])
            ->values()->all();
    }

    /**
     * Recreate links with old item ids replaced (old id => new ids), without duplicates or
     * links of an object to itself.
     *
     * @param  list<array{relation: string, from: string, to: string}>  $links
     * @param  array<string, list<string>>  $map
     */
    private function relink(ProductVersion $version, array $links, array $map): void
    {
        $done = [];
        foreach ($links as $link) {
            foreach ($map[$link['from']] ?? [$link['from']] as $from) {
                foreach ($map[$link['to']] ?? [$link['to']] as $to) {
                    $key = $link['relation'].'|'.$from.'|'.$to;
                    if ($from === $to || isset($done[$key])) {
                        continue;
                    }
                    $done[$key] = true;
                    $version->inventoryLinks()->create(['relation' => $link['relation'], 'from_item_id' => $from, 'to_item_id' => $to]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validated(Request $request, ProductVersion $version, array $definition, ?InventoryItem $item = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[\pL\pN._\-\/ ]+$/u',
                Rule::unique('inventory_items', 'code')
                    ->where('product_version_id', $version->id)
                    ->where('list', $definition['key'])
                    ->ignore($item?->id)],
            'name' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            ...InventoryFields::rules($definition),
        ]);
    }

    /** @param  array<string, mixed>  $definition */
    private function nextCode(ProductVersion $version, array $definition): string
    {
        $prefix = $definition['code_prefix'] ?? '';
        $n = $version->inventoryItems()->where('list', $definition['key'])->count() + 1;
        do {
            $code = $prefix.str_pad((string) $n++, 2, '0', STR_PAD_LEFT);
        } while ($version->inventoryItems()->where('list', $definition['key'])->where('code', $code)->exists());

        return $code;
    }
}
