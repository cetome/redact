<?php

namespace App\Http\Controllers;

use App\Ai\Provenance;
use App\Ai\Targets;
use App\Assessment\Assessment;
use App\Assessment\EinfoPrefill;
use App\Assessment\SharedWalks;
use App\Content\Text;
use App\Enums\Origin;
use App\Models\EinfoEntry;
use App\Models\FieldOrigin;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Support\Links;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * E.Info: the documentation the standard requires for each object, derived
 * by the content pack rules from the inventory and the decision trees.
 *
 * The page shows one object at a time (/{object}: product or an item id), every object or the
 * incomplete ones only (/missing). The search across every object runs in the browser (einfo.js,
 * kept per page in sessionStorage): no address carries it.
 *
 * Fields the content pack fills in from the inventory (einfo-prefill.json, EinfoPrefill) start with
 * that text, marked "From inventory"; nothing is stored until a person saves, and until then the
 * field counts as missing. Saved unchanged, the text keeps the mark (a FieldOrigin, as for AI text);
 * changed, it is "Human Edited". When the inventory changes later, a saved, unchanged inventory text
 * is flagged as out of date and "Accept the pre-filled texts" replaces it: never silently.
 */
class EinfoController extends Controller
{
    public function index(Request $request, ProductVersion $version, string $standard): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canView(), 404);
        $assessment = Assessment::for($version);
        abort_unless(isset($assessment->pack->standards()[$standard]), 404);

        $q = '';
        $show = $request->route('show') === 'missing' ? 'missing' : 'all';
        $objects = $this->filter($this->objects($assessment, $version, $standard), $q, $show);
        $keys = array_keys($objects);

        // The object shown: the one asked for; else the first the filter shows that still has
        // fields to write, else the first shown, else the first.
        $asked = (string) $request->route('object');
        $shown = array_filter($objects, fn (array $o) => $o['visible']);
        $selected = isset($objects[$asked]) ? $asked
            : (array_key_first(array_filter($shown, fn (array $o) => $o['done'] < $o['total'])) ?? array_key_first($shown) ?? ($keys[0] ?? null));

        // Previous and next among the objects the filters show.
        $at = $selected === null ? false : array_search($selected, $keys, true);
        $previous = $next = null;
        if ($at !== false) {
            foreach (array_reverse(array_slice($keys, 0, $at)) as $key) {
                if ($objects[$key]['visible']) {
                    $previous = $key;
                    break;
                }
            }
            foreach (array_slice($keys, $at + 1) as $key) {
                if ($objects[$key]['visible']) {
                    $next = $key;
                    break;
                }
            }
        }

        return view('einfo.index', [
            'version' => $version,
            'access' => $access,
            'standard' => $standard,
            'standards' => $assessment->workingStandards(),
            'pack' => $assessment->pack,
            'objects' => $objects,
            'selected' => $selected === null ? null : $objects[$selected],
            'previous' => $previous,
            'next' => $next,
            'q' => $q,
            'show' => $show,
            'progress' => $assessment->einfoProgress($standard),
            'trees' => $selected === null ? [] : $this->trees($assessment, $standard, $objects[$selected]['item']),
            // A tree saved from its modal and refused (validation): that modal opens again.
            'openTree' => $request->session()->has('errors') ? (string) $request->session()->getOldInput('tree', '') : '',
        ]);
    }

    public function update(Request $request, ProductVersion $version, string $standard): RedirectResponse
    {
        $this->allow('edit', $version);
        $assessment = Assessment::for($version);

        $data = $request->validate([
            'entries' => ['required', 'array'],
            'entries.*' => ['array'],
            'entries.*.*' => ['nullable', 'string', 'max:20000'],
            'object' => ['nullable', 'string', 'max:26'],
            'q' => ['nullable', 'string', 'max:100'],
            'show' => ['nullable', 'in:all,missing'],
            'accept_prefill' => ['nullable', 'boolean'],
        ]);

        // Only (object, E.Info) pairs the rules currently place there (required or conditional) can be
        // written. Pairs not posted are left as they are: the page sends one object at a time.
        $allowed = [];
        foreach ($assessment->requiredEinfo($standard) as $row) {
            foreach ([...$row['codes'], ...$row['conditional']] as $code) {
                $allowed[($row['item']->id ?? 'product').'|'.$code] = $row['item'];
            }
        }
        $prefill = new EinfoPrefill($assessment->pack, $assessment->context());
        $provenance = app(Provenance::class);
        $accept = $request->boolean('accept_prefill');
        $accepted = 0;

        DB::transaction(function () use ($data, $allowed, $version, $request, $prefill, $provenance, $accept, &$accepted) {
            foreach ($data['entries'] as $itemKey => $codes) {
                foreach ($codes as $code => $text) {
                    $key = $itemKey.'|'.$code;
                    if (! array_key_exists($key, $allowed)) {
                        continue;
                    }
                    /** @var InventoryItem|null $item */
                    $item = $allowed[$key];
                    $entry = EinfoEntry::query()
                        ->where('product_version_id', $version->id)
                        ->where('einfo', $code)
                        ->where('inventory_item_id', $item?->id)
                        ->first();
                    $text = trim((string) $text);
                    $fromInventory = $prefill->text($code, $item);
                    $originKey = Targets::einfoKey($code, $item?->id);

                    // "Accept the pre-filled texts": an empty field takes the inventory text, and a saved
                    // inventory text nobody changed takes the inventory's current one.
                    if ($accept && $fromInventory !== null) {
                        $unchanged = $entry && filled($entry->text) && $text === trim((string) $entry->text)
                            && $provenance->origin('einfo_entry', $originKey, 'text', $entry->text) === Origin::FromInventory;
                        if ($text === '' || ($unchanged && $text !== $fromInventory) || (! $entry && $text === $fromInventory)) {
                            $accepted++;
                        }
                        if ($text === '' || $unchanged) {
                            $text = $fromInventory;
                        }
                    }

                    if (! $entry && $text === '') {
                        continue;
                    }
                    ($entry ?? new EinfoEntry([
                        'product_version_id' => $version->id,
                        'einfo' => $code,
                        'inventory_item_id' => $item?->id,
                    ]))->fill(['text' => $text === '' ? null : $text, 'updated_by' => $request->user()->id])->save();

                    // Saved as the inventory gave it: "From inventory" (any later change makes it "Human Edited").
                    if ($fromInventory !== null && $text !== '' && FieldOrigin::hashValue($text) === FieldOrigin::hashValue($fromInventory)) {
                        $provenance->mark('einfo_entry', $originKey, 'text', Origin::FromInventory, $text, null, $request->user());
                    }
                }
            }
        });

        $status = $accept ? trans_choice('einfo.prefill.accepted', $accepted, ['count' => $accepted]) : __('common.saved');
        $object = $data['object'] ?? null;
        if ($object === null) {
            return back()->with('status', $status);
        }

        $q = $this->query($data['q'] ?? null);
        $show = ($data['show'] ?? null) === 'missing' ? 'missing' : 'all';
        if ($request->boolean('next')) {
            $objects = $this->filter($this->objects($assessment, $version, $standard), $q, 'all');
            $next = $this->nextObject($objects, $object, $show);
            if ($next !== null) {
                $object = $next;
                $status = __('einfo.saved_next', ['object' => $objects[$next]['title']]);
            }
        }

        return redirect()->to(Links::einfo($version, $standard, $object, $show === 'missing'))->with('status', $status);
    }

    /** The object select of narrow screens, without script (einfo.js follows the option's address). */
    public function pick(Request $request, ProductVersion $version, string $standard): RedirectResponse
    {
        abort_unless($this->access($version->product)->canView(), 404);
        abort_unless(isset(Assessment::for($version)->pack->standards()[$standard]), 404);
        $data = $request->validate([
            'object' => ['required', 'string', 'regex:/^(product|[0-9a-z]{26})$/'],
            'show' => ['nullable', 'in:all,missing'],
        ]);

        return redirect()->to(Links::einfo($version, $standard, $data['object'], ($data['show'] ?? null) === 'missing'));
    }

    /**
     * Every object with E.Info to write, in the order of the rules (the product, then each list),
     * with its fields, their state and the text the search looks in.
     *
     * @return array<string, array<string, mixed>>
     */
    private function objects(Assessment $assessment, ProductVersion $version, string $standard): array
    {
        $catalogue = $assessment->pack->einfo();
        $lists = $assessment->pack->lists();
        $entries = $version->einfoEntries()->get()
            ->keyBy(fn (EinfoEntry $e) => $e->einfo.'|'.($e->inventory_item_id ?? 'product'));
        $prefill = new EinfoPrefill($assessment->pack, $assessment->context());
        $provenance = app(Provenance::class);

        $objects = [];
        foreach ($assessment->requiredEinfo($standard) as $row) {
            $item = $row['item'];
            $key = $item->id ?? 'product';
            $title = $item?->label() ?? __('einfo.product');
            $fields = [];
            // Required fields first, then the conditional ones (not counted: done/total are the required).
            foreach ([[$row['codes'], false], [$row['conditional'], true]] as [$codes, $conditional]) {
                foreach ($codes as $code) {
                    $entry = $entries[$code.'|'.$key] ?? null;
                    $label = Text::of($catalogue[$code]['label'] ?? '');
                    $saved = filled($entry?->text);
                    $fromInventory = $prefill->text($code, $item);
                    // A saved inventory text nobody changed, and the inventory says something else now.
                    $outdated = $saved && $fromInventory !== null
                        && FieldOrigin::hashValue($fromInventory) !== FieldOrigin::hashValue($entry->text)
                        && $provenance->origin('einfo_entry', Targets::einfoKey($code, $item?->id), 'text', $entry->text) === Origin::FromInventory;
                    $fields[] = [
                        'code' => $code,
                        'requirement' => (string) ($catalogue[$code]['requirement'] ?? ''),
                        'label' => $label,
                        'entry' => $entry,
                        // Put back to re-check by a significant change (SignificantChange): not done until confirmed.
                        'done' => $saved && ! $entry->recheck,
                        'recheck' => $saved && $entry->recheck,
                        'conditional' => $conditional,
                        'condition' => $conditional ? $this->condition($assessment, $standard, $code) : null,
                        // Shown in the empty field, not saved: the field still counts as missing.
                        'prefill' => $saved ? null : $fromInventory,
                        'refresh' => $outdated ? $fromInventory : null,
                        'search' => $this->normalise($code.' '.$label.' '.$title.' '.Str::limit((string) ($entry->text ?? $fromInventory), 1000, '')),
                    ];
                }
            }
            $required = array_filter($fields, fn (array $f) => ! $f['conditional']);
            $done = count(array_filter($required, fn (array $f) => $f['done']));
            $objects[$key] = [
                'key' => $key,
                'item' => $item,
                'title' => $title,
                'kind' => $item->list ?? 'product',
                'group' => $item ? Text::of($lists[$item->list]['label'] ?? $item->list) : __('einfo.product_group'),
                'singular' => $item ? Text::of($lists[$item->list]['singular'] ?? $item->list) : __('einfo.product_group'),
                'fields' => $fields,
                'done' => $done,
                'total' => count($required),
                'conditional' => count($fields) - count($required),
                'prefilled' => count(array_filter($fields, fn (array $f) => $f['prefill'] !== null)),
                'outdated' => count(array_filter($fields, fn (array $f) => $f['refresh'] !== null)),
                'state' => $done === count($required) ? 'complete' : ($done > 0 ? 'partial' : 'empty'),
            ];
        }

        return $objects;
    }

    /**
     * The decision trees evaluated for one object (the requirements of the standard it is a target of),
     * in the standard's order: what the E.Info page shows next to the fields, and edits in a modal.
     *
     * @return array<string, array<string, mixed>>
     */
    private function trees(Assessment $assessment, string $standard, ?InventoryItem $item): array
    {
        $key = $item->id ?? 'product';
        $shared = SharedWalks::for($assessment);
        $trees = [];
        foreach ($assessment->pack->requirementsFor($standard) as $code => $requirement) {
            $code = (string) $code;
            $target = collect($assessment->targets($standard, $code))->contains(fn (?InventoryItem $t) => ($t->id ?? 'product') === $key);
            if (! $target) {
                continue;
            }
            $tree = $assessment->pack->tree($standard, $code);
            $evaluation = $assessment->evaluation($standard, $code, $item);
            $trees[$code] = [
                'code' => $code,
                'title' => $tree?->title() ?: Text::of($requirement['title'] ?? ''),
                'tree' => $tree,
                'evaluation' => $evaluation,
                'status' => $assessment->status($evaluation, $tree),
                'shared' => $shared->state($standard, $code, $item, $evaluation),
                'check' => $evaluation ? $assessment->justification()->check($evaluation) : null,
            ];
        }

        return $trees;
    }

    /** The standard's condition of a conditional item ("if ..."), from the pack's assessment criteria. */
    private function condition(Assessment $assessment, string $standard, string $code): string
    {
        $requirement = (string) ($assessment->pack->einfo()[$code]['requirement'] ?? '');
        foreach ($assessment->pack->assessment($standard, $requirement)['required_information'] ?? [] as $entry) {
            if (($entry['id'] ?? null) === $code && ! empty($entry['condition'])) {
                return Text::of($entry['condition']);
            }
        }

        return '';
    }

    /**
     * Applies the search (a field matches when it holds every word: field.hit, object.matches)
     * and the filter (object.visible: listed on the left).
     *
     * @param  array<string, array<string, mixed>>  $objects
     * @return array<string, array<string, mixed>>
     */
    private function filter(array $objects, string $q, string $show): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', $this->normalise($q)) ?: [], fn (string $w) => $w !== ''));
        foreach ($objects as &$object) {
            $matches = 0;
            foreach ($object['fields'] as &$field) {
                $field['hit'] = $words === [] || collect($words)->every(fn (string $w) => str_contains($field['search'], $w));
                $matches += $field['hit'] ? 1 : 0;
            }
            unset($field);
            $object['matches'] = $words === [] ? null : $matches;
            $object['visible'] = ($show === 'all' || $object['done'] < $object['total']) && ($words === [] || $matches > 0);
        }
        unset($object);

        return $objects;
    }

    /**
     * After "Save and next": the next object (after this one, then from the start) that still has
     * fields to write, else the next object the filters show.
     *
     * @param  array<string, array<string, mixed>>  $objects
     */
    private function nextObject(array $objects, string $current, string $show): ?string
    {
        $keys = array_keys($objects);
        $at = array_search($current, $keys, true);
        if ($at === false) {
            return null;
        }
        foreach (array_merge(array_slice($keys, $at + 1), array_slice($keys, 0, $at)) as $key) {
            if ($objects[$key]['visible'] && $objects[$key]['done'] < $objects[$key]['total']) {
                return $key;
            }
        }
        if ($show === 'all') {
            foreach (array_slice($keys, $at + 1) as $key) {
                if ($objects[$key]['visible']) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function query(mixed $q): string
    {
        return is_string($q) ? mb_substr(trim($q), 0, 100) : '';
    }

    /** Lower case without accents: what the search compares (einfo.js does the same). */
    private function normalise(string $text): string
    {
        return mb_strtolower(Str::ascii($text));
    }
}
