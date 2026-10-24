<?php

namespace App\Assessment;

use App\Content\ContentPack;
use App\Content\Text;
use App\Content\Tree;
use App\Enums\Verdict;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Support\Links;

/**
 * Is a verdict JUSTIFIED before it counts? The standard's assessment criteria (content pack,
 * assessments/<standard>/<REQ>.json) say, per decision node, what the justification must be based
 * on ("if a decision from DN-1 results in NOT APPLICABLE, the justification is based on
 * E.Info.X or …"). For one walked evaluation this finds:
 *
 *  - the rules that apply to its path: a rule of a node on the path with no outcome, or with the
 *    outcome the walk reached at that node (only the last node of a walk reaches an outcome; when
 *    the tree shows the node cannot reach that outcome directly, the outcome of the walk counts);
 *  - for each E.Info id of a rule, whether it is documented for that object or for the product
 *    (E.Info entries are keyed by object, null = the product), missing (the pack rules place it on
 *    the object or the product and it is empty) or not required (placed nowhere for this object:
 *    it does not count). E.Info.DT.<REQ> is the walked path, E.Just.DT.<REQ> the justification;
 *  - a rule is met when any (match "any") or all (match "all") of its counted ids are documented;
 *  - the justification text itself: present and at least MIN_LENGTH characters.
 *
 * Justified = walked, text long enough, every applicable rule met. One instance per Assessment.
 */
class Justification
{
    /** The shortest justification accepted, in characters (after trimming). */
    public const MIN_LENGTH = 20;

    /** @var array<string, list<string>> "standard|item id or -" => E.Info codes the rules place there */
    private array $placed = [];

    /** @var array<string, true>|null "code|item id or -" => written */
    private ?array $documented = null;

    /** @var array<string, JustificationCheck> */
    private array $checks = [];

    public function __construct(private readonly Assessment $assessment) {}

    /** The tree label of a verdict, as the standard prints it in the criteria ("NOT APPLICABLE"). */
    public static function outcomeOf(?Verdict $verdict): ?string
    {
        return match ($verdict) {
            Verdict::Pass => 'PASS',
            Verdict::Fail => 'FAIL',
            Verdict::NotApplicable => 'NOT APPLICABLE',
            Verdict::Inconclusive => 'INCONCLUSIVE',
            null => null,
        };
    }

    /**
     * The justification rules of one requirement (empty when the pack has no criteria for it).
     *
     * @return list<array<string, mixed>>
     */
    public function rules(string $standard, string $requirement): array
    {
        $criteria = $this->assessment->pack->assessment($standard, $requirement);

        return array_values(array_filter($criteria['justification'] ?? [], 'is_array'));
    }

    public function check(Evaluation $evaluation): JustificationCheck
    {
        $key = $evaluation->id.'|'.$evaluation->fingerprint().'|'.json_encode($evaluation->path);

        return $this->checks[$key] ??= $this->compute($evaluation);
    }

    private function compute(Evaluation $evaluation): JustificationCheck
    {
        // INCONCLUSIVE is its own state: not a walk's outcome, never justified, the rules of the
        // nodes reached so far are still shown.
        $inconclusive = $evaluation->verdict === Verdict::Inconclusive;
        $walked = $evaluation->decision_node !== null && $evaluation->verdict !== null && ! $inconclusive;
        $text = trim((string) $evaluation->justification);
        $length = mb_strlen($text);
        $longEnough = $length >= self::MIN_LENGTH;

        $criteria = $this->assessment->pack->assessment($evaluation->standard, $evaluation->requirement);
        $item = $evaluation->inventory_item_id ? $this->assessment->context()->item($evaluation->inventory_item_id) : null;

        // The decision nodes of the walk; an expert entry only records its last node.
        $path = array_column($evaluation->path ?? [], 'node');
        if ($evaluation->decision_node !== null && ! in_array($evaluation->decision_node, $path, true)) {
            $path[] = $evaluation->decision_node;
        }
        $outcome = self::outcomeOf($evaluation->verdict);
        $tree = $this->assessment->pack->tree($evaluation->standard, $evaluation->requirement);

        $rules = [];
        if ($walked || ($inconclusive && $evaluation->decision_node !== null)) {
            foreach ($this->rules($evaluation->standard, $evaluation->requirement) as $rule) {
                $printed = (string) ($rule['node'] ?? '');
                // The standard's E.Just lists sometimes number nodes unlike its tree: tree_node maps
                // the printed node onto the tree (null = cannot be mapped: shown, never checked).
                $mapped = array_key_exists('tree_node', $rule);
                $node = $mapped ? $rule['tree_node'] : $printed;
                $wanted = $rule['outcome'] ?? null;
                $basedOn = array_map('strval', (array) ($rule['based_on'] ?? []));
                // Informative only: unmapped node, flagged by the pack, or a condition on an
                // implementation category (IC.…) the app cannot evaluate from the inventory.
                $informative = ($mapped && $node === null) || ! empty($rule['informative'])
                    || collect($basedOn)->contains(fn (string $id) => str_starts_with($id, 'IC.'));

                if (! $informative) {
                    if (! in_array($node, $path, true)) {
                        continue;
                    }
                    if ($wanted !== null && ($wanted !== $outcome || ($node !== $evaluation->decision_node && $this->leadsTo($tree, (string) $node, $wanted)))) {
                        continue;
                    }
                } elseif ($node !== null && ! in_array($node, $path, true)) {
                    continue;
                }

                $ids = [];
                foreach ($basedOn as $id) {
                    $ids[] = $this->state($id, $evaluation, $item, $longEnough);
                }
                $counted = array_filter($ids, fn (array $id) => in_array($id['state'], ['documented', 'missing'], true));
                $documented = array_filter($counted, fn (array $id) => $id['state'] === 'documented');
                $match = ($rule['match'] ?? 'any') === 'all' ? 'all' : 'any';
                // Nothing the app can check for this object (no id placed, even as the object's own
                // variant): never a silent pass, a person checks it (not blocking).
                $check = ! $informative && $counted === [];
                $met = $informative || $check || ($match === 'all' ? count($documented) === count($counted) : $documented !== []);

                $rules[] = [
                    'node' => $printed,
                    'tree_node' => $node,
                    'outcome' => $wanted,
                    'match' => $match,
                    'text' => Text::of($rule['text'] ?? ''),
                    'informative' => $informative,
                    'check' => $check,
                    'met' => $met,
                    'ids' => $ids,
                ];
            }
        }

        $reasons = [];
        if ($inconclusive) {
            $reasons[] = __('assessment.reasons.inconclusive');
        } elseif (! $walked) {
            $reasons[] = __('assessment.reasons.not_walked');
        } elseif ($length === 0) {
            $reasons[] = __('assessment.reasons.no_text');
        } elseif (! $longEnough) {
            $reasons[] = __('assessment.reasons.short_text', ['min' => self::MIN_LENGTH]);
        }
        $missing = [];
        foreach ($rules as $rule) {
            if (! $rule['met']) {
                foreach ($rule['ids'] as $id) {
                    if ($id['state'] === 'missing') {
                        $missing[$id['id']] = true;
                    }
                }
            }
        }
        if ($missing !== []) {
            $reasons[] = trans_choice('assessment.reasons.missing_einfo', count($missing), ['ids' => implode(', ', array_keys($missing))]);
        }

        $justified = $walked && $longEnough && collect($rules)->every(fn (array $rule) => $rule['met']);

        return new JustificationCheck($walked, $length, $longEnough, $criteria !== null, $rules, $justified, $reasons, $inconclusive);
    }

    /**
     * Can the node lead to the outcome in one branch? Usually yes for an outcome-specific rule; when
     * the standard names an outcome the node only reaches through later nodes, the rule applies
     * to every walk through the node that ends with that outcome.
     */
    private function leadsTo(?Tree $tree, string $node, string $outcome): bool
    {
        if (! $tree || ! $tree->has($node)) {
            return true;
        }

        return in_array($outcome, array_map(fn (Verdict $v) => self::outcomeOf($v), $tree->verdictsFrom($node)), true);
    }

    /**
     * @return array{id: string, mapped: string|null, state: string, object: string|null, href: string|null}
     */
    private function state(string $id, Evaluation $evaluation, ?InventoryItem $item, bool $longEnough): array
    {
        if (str_starts_with($id, 'E.Info.DT.')) {
            return ['id' => $id, 'mapped' => null, 'state' => $evaluation->decision_node ? 'documented' : 'missing', 'object' => null, 'href' => null];
        }
        if (str_starts_with($id, 'E.Just.DT.')) {
            return ['id' => $id, 'mapped' => null, 'state' => $longEnough ? 'documented' : 'missing', 'object' => null, 'href' => null];
        }
        if (! str_starts_with($id, 'E.Info.')) {
            // IC.… (implementation category) and other printed ids: not E.Info the app tracks.
            return ['id' => $id, 'mapped' => null, 'state' => 'untracked', 'object' => null, 'href' => null];
        }

        // The id as printed, then (no vacuous pass) its variants for the object's own type:
        // an id printed for one kind of asset read for the object's own kind (einfo-variants.json).
        // Placement always comes from the pack rules (requiredEinfo), never from here.
        foreach ([$id, ...self::variants($id, $this->assessment->pack->einfoVariants())] as $candidate) {
            $found = $this->locate($candidate, $evaluation, $item);
            if ($found !== null) {
                return ['id' => $id, 'mapped' => $candidate === $id ? null : $candidate] + $found;
            }
        }

        return ['id' => $id, 'mapped' => null, 'state' => 'not_required', 'object' => null, 'href' => null];
    }

    /**
     * Documented or placed for this object (or the product)? Null when neither.
     *
     * @return array{state: string, object: string, href: string}|null
     */
    private function locate(string $code, Evaluation $evaluation, ?InventoryItem $item): ?array
    {
        $documented = $this->documented();
        $href = fn (string $key) => Links::einfo($this->assessment->version, $evaluation->standard, $key).'#e-'.md5($key.$code);

        if ($item && isset($documented[$code.'|'.$item->id])) {
            return ['state' => 'documented', 'object' => $item->id, 'href' => $href($item->id)];
        }
        if (isset($documented[$code.'|-'])) {
            return ['state' => 'documented', 'object' => 'product', 'href' => $href('product')];
        }
        if ($item && in_array($code, $this->placed($evaluation->standard, $item), true)) {
            return ['state' => 'missing', 'object' => $item->id, 'href' => $href($item->id)];
        }
        if (in_array($code, $this->placed($evaluation->standard, null), true)) {
            return ['state' => 'missing', 'object' => 'product', 'href' => $href('product')];
        }
        // Placed by the pack rules on objects linked to the walked one, or, for a product-level walk,
        // on objects of a list (e.g. GEC-1.SoftwareDocumentation on the component of a vulnerability).
        $rules = $this->assessment->pack->rules();
        if (method_exists($rules, 'relatedEinfoObjects')) {
            $related = $rules->relatedEinfoObjects($evaluation->standard, $code, $item, $this->assessment->context());
            foreach ($related as $other) {
                if (isset($documented[$code.'|'.$other['item']->id])) {
                    return ['state' => 'documented', 'object' => $other['item']->id, 'href' => $href($other['item']->id)];
                }
            }
            foreach ($related as $other) {
                if ($other['state'] === 'required') {
                    return ['state' => 'missing', 'object' => $other['item']->id, 'href' => $href($other['item']->id)];
                }
            }
        }

        return null;
    }

    /**
     * The same id with another word of the same set in one of its segments (the pack's
     * einfo-variants.json, e.g. another kind of asset or interface): the criteria print one
     * variant, the object may use another. Segments after "E.Info." are read; the first variant whose pattern matches a segment applies, its named group "v" is
     * swapped for each other value, in the pack's order. No variants without the pack's file.
     *
     * @param  list<array{pattern: string, values: list<string>}>|null  $definitions  null: the pack's
     * @return list<string>
     */
    public static function variants(string $id, ?array $definitions = null): array
    {
        $definitions ??= app(ContentPack::class)->einfoVariants();
        if ($definitions === []) {
            return [];
        }
        $parts = explode('.', $id);
        $variants = [];
        foreach ($parts as $i => $segment) {
            if ($i < 2) {
                continue;
            }
            foreach ($definitions as $definition) {
                if (@preg_match($definition['pattern'], $segment, $m, PREG_OFFSET_CAPTURE) !== 1 || ! isset($m['v']) || $m['v'][1] < 0) {
                    continue;
                }
                [$word, $offset] = $m['v'];
                foreach ($definition['values'] as $value) {
                    if ($value !== $word) {
                        $variants[] = implode('.', array_replace($parts, [$i => substr($segment, 0, $offset).$value.substr($segment, $offset + strlen($word))]));
                    }
                }
                break;
            }
        }

        return $variants;
    }

    /** @return list<string> */
    private function placed(string $standard, ?InventoryItem $item): array
    {
        $key = $standard.'|'.($item->id ?? '-');

        return $this->placed[$key] ??= $this->assessment->pack->rules()->requiredEinfo($standard, $item, $this->assessment->context());
    }

    /** @return array<string, true> */
    private function documented(): array
    {
        if ($this->documented === null) {
            $this->documented = [];
            foreach ($this->assessment->version->einfoEntries()->whereNotNull('text')->get(['einfo', 'inventory_item_id', 'text']) as $entry) {
                if (trim((string) $entry->text) !== '') {
                    $this->documented[$entry->einfo.'|'.($entry->inventory_item_id ?? '-')] = true;
                }
            }
        }

        return $this->documented;
    }
}
