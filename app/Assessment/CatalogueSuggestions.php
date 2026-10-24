<?php

namespace App\Assessment;

use App\Content\Text;

/**
 * Which catalogue items to pre-select for a version, from its scoping, and in which order to
 * show them. The pack decides what relates to what; this class only applies it.
 *
 * Suggestion:
 *  - an item is suggested when one of its EN 18031 parts ("standards") is in scope,
 *    and, if the pack gives it "suggest_when" (question keys), when one of those questions was
 *    answered yes. A question of a later step (the conformity restrictions) that is not answered
 *    yet does not hold the item back: it is then suggested for its parts only;
 *  - items already in the inventory (same name, any language, also as a member of a group) are
 *    flagged, not suggested.
 *
 * Two strengths, so the picker does not pre-tick half the catalogue:
 *  - "answer": linked to an answer given yes (pre-ticked);
 *  - "standard": only usual for a part of EN 18031 in scope (listed, not ticked).
 *
 * Order: the pack decides it (catalogue.json "ranking": {"order": [...], "presence": {...}}), the
 * engine applies it. "order" lists the keys compared in turn, best first: "tier" (linked to your
 * answers, then usual for the parts in scope, then the rest), "yes" (more yes answers first),
 * "presence" (the pack's presence rank of the item) and "parts" (more in-scope parts citing the
 * item first); the catalogue order always settles the rest. Without a ranking the engine's
 * neutral default applies: tier, then presence, then catalogue order.
 *
 * Groups: the pack's catalogue "groups" name sets of items with the same key attributes.
 * proposals() offers, from the items the picker puts forward, one group per set of at least two
 * items that carry exactly the same attributes and match the same named group.
 */
class CatalogueSuggestions
{
    /** Default rank of the "presence" values (higher first); a pack ranking may set its own. */
    public const PRESENCE = ['typical' => 2, 'common' => 1];

    /** Keys a ranking may compare, and the engine's default order. */
    public const ORDER_KEYS = ['tier', 'yes', 'presence', 'parts'];

    public const DEFAULT_ORDER = ['tier', 'presence'];

    private const TIERS = ['answer' => 2, 'standard' => 1, 'other' => 0];

    public function __construct(private readonly Assessment $assessment) {}

    /**
     * Catalogue rows of one list, best first. "index" is the item's position in the pack's
     * catalogue (the form value).
     *
     * @return list<array{index: int, item: array<string, mixed>, name: string, suggested: bool, strength: 'answer'|'standard'|null, tier: 'answer'|'standard'|'other', presence: 'typical'|'common'|null, yes: int, parts: int, reasons: list<string>, added: bool, complete: bool, group: string|null, group_name: string|null}>
     */
    public function forList(string $list): array
    {
        $pack = $this->assessment->pack;
        $definition = $pack->lists()[$list] ?? null;
        if (! $definition) {
            return [];
        }

        $inScope = $this->assessment->standards();
        $answers = $this->assessment->version->scopingAnswers()->pluck('value', 'question')->all();
        $scopingKeys = array_flip(array_keys(app(ScopingForm::class)->questions(ScopingForm::SCOPING)));
        $labels = $this->questionLabels();
        $existing = $this->existingNames($list);
        $required = collect($definition['fields'] ?? [])->filter(fn ($f) => ! empty($f['required']))->pluck('key')->all();
        $groups = $pack->catalogueGroups($list);

        $rows = [];
        foreach ($pack->catalogue($list) as $index => $item) {
            $names = is_array($item['name'] ?? null) ? array_values($item['name']) : [(string) ($item['name'] ?? '')];
            $added = collect($names)->contains(fn ($n) => isset($existing[mb_strtolower(trim((string) $n))]));

            $reasons = [];
            $standards = array_values(array_intersect($item['standards'] ?? [], $inScope));
            $byStandard = $standards !== [] || ($item['standards'] ?? []) === [];
            foreach ($standards as $standard) {
                $reasons[] = __('inventory.reason_standard', ['standard' => $pack->standardName($standard)]);
            }

            $when = $item['suggest_when'] ?? [];
            $yes = array_values(array_filter($when, fn ($q) => ($answers[$q] ?? false) === true));
            // Held back only when no linked question is yes and none of a later step is still open.
            $pending = array_filter($when, fn ($q) => ! isset($scopingKeys[$q]) && ! array_key_exists($q, $answers));
            $byAnswer = $when === [] || $yes !== [] || $pending !== [];
            foreach ($yes as $q) {
                $reasons[] = __('inventory.reason_answer', ['answer' => $labels[$q] ?? $q]);
            }

            $attributes = $item['attributes'] ?? [];
            $fits = $inScope !== [] && $byStandard && $byAnswer;
            $tier = $fits ? ($yes !== [] ? 'answer' : 'standard') : 'other';
            $suggested = ! $added && $fits;
            $presence = in_array($item['presence'] ?? null, array_keys(self::PRESENCE), true) ? $item['presence'] : null;
            $group = self::groupFor($groups, $attributes);
            $rows[] = [
                'index' => $index,
                'item' => $item,
                'name' => Text::of($item['name'] ?? ''),
                'suggested' => $suggested,
                'strength' => $suggested ? ($tier === 'answer' ? 'answer' : 'standard') : null,
                'tier' => $tier,
                'presence' => $presence,
                'yes' => count($yes),
                'parts' => count($standards),
                'reasons' => $reasons,
                'added' => $added,
                'complete' => collect($required)->every(fn ($k) => isset($attributes[$k]) && $attributes[$k] !== ''),
                'group' => $group['key'] ?? null,
                'group_name' => $group ? Text::of($group['name'] ?? $group['key']) : null,
            ];
        }

        [$order, $presenceRank] = $this->ranking();
        $score = fn (array $row) => array_map(fn (string $key) => match ($key) {
            'tier' => self::TIERS[$row['tier']],
            'yes' => $row['yes'],
            'presence' => $presenceRank[$row['presence']] ?? 0,
            'parts' => $row['parts'],
            default => 0,
        }, $order);
        usort($rows, fn (array $a, array $b) => [...$score($b), $a['index']] <=> [...$score($a), $b['index']]);

        return $rows;
    }

    /**
     * The pack's ranking, checked: the keys to compare (known keys only, once each) and the rank
     * of each presence value (only the engine's presence values, numbers).
     *
     * @return array{0: list<string>, 1: array<string, int|float>}
     */
    private function ranking(): array
    {
        $ranking = $this->assessment->pack->catalogueRanking();

        $order = self::DEFAULT_ORDER;
        if (is_array($ranking['order'] ?? null)) {
            $order = array_values(array_unique(array_intersect(array_map('strval', $ranking['order']), self::ORDER_KEYS)));
        }

        $presence = self::PRESENCE;
        if (is_array($ranking['presence'] ?? null)) {
            $presence = array_filter(array_intersect_key($ranking['presence'], self::PRESENCE), 'is_numeric');
        }

        return [$order, $presence];
    }

    /**
     * Groups to propose in the picker: among the items put forward (pre-ticked, or suggested and
     * typical/common), those with exactly the same attributes and the same named group, two or more.
     * Exactly the same attributes, so that accepting a proposal never loses a value.
     *
     * @param  list<array<string, mixed>>  $rows  the rows of forList()
     * @return list<array{key: string, name: string, indexes: list<int>, names: list<string>}>
     */
    public static function proposals(array $rows): array
    {
        $clusters = [];
        foreach ($rows as $row) {
            if (! $row['suggested'] || $row['group'] === null || ($row['strength'] !== 'answer' && $row['presence'] === null)) {
                continue;
            }
            $attributes = $row['item']['attributes'] ?? [];
            ksort($attributes);
            $key = $row['group'].'|'.json_encode($attributes);
            $clusters[$key] ??= ['key' => (string) $row['group'], 'name' => (string) $row['group_name'], 'indexes' => [], 'names' => []];
            $clusters[$key]['indexes'][] = (int) $row['index'];
            $clusters[$key]['names'][] = (string) $row['name'];
        }

        return array_values(array_filter($clusters, fn (array $c) => count($c['indexes']) >= 2));
    }

    /**
     * The first named group whose "match" values the attributes all carry.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public static function groupFor(array $groups, array $attributes): ?array
    {
        foreach ($groups as $group) {
            $match = $group['match'] ?? [];
            if ($match !== [] && collect($match)->every(fn ($value, $key) => array_key_exists($key, $attributes) && $attributes[$key] === $value)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The attribute values every set shares (same key, same value): what a group of them keeps.
     *
     * @param  list<array<string, mixed>>  $sets
     * @return array<string, mixed>
     */
    public static function commonAttributes(array $sets): array
    {
        if ($sets === []) {
            return [];
        }
        $common = array_shift($sets);
        foreach ($sets as $set) {
            $common = array_filter($common, fn ($value, $key) => array_key_exists($key, $set) && $set[$key] === $value, ARRAY_FILTER_USE_BOTH);
        }

        return array_filter($common, fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /** @return array<string, true> lower-case names of the list's items and of their group members */
    private function existingNames(string $list): array
    {
        $names = [];
        foreach ($this->assessment->version->inventoryItems()->where('list', $list)->get(['name', 'members']) as $item) {
            foreach ([$item->name, ...array_column($item->memberList(), 'name')] as $name) {
                $name = mb_strtolower(trim((string) $name));
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        return $names;
    }

    /**
     * Readable labels of the questions "suggest_when" may name. A restriction asked under a lead-in
     * ("Secure update is protected…") is read with it ("… with digital signatures").
     *
     * @return array<string, string>
     */
    private function questionLabels(): array
    {
        $labels = [];
        foreach ($this->assessment->pack->scoping()['sections'] ?? [] as $section) {
            foreach ($section['groups'] ?? [] as $group) {
                $title = rtrim(Text::of($group['title'] ?? ''));
                $leadIn = str_ends_with($title, '…') ? rtrim(mb_substr($title, 0, -1)) : null;
                foreach ($group['questions'] ?? [] as $question) {
                    $label = Text::of($question['label'] ?? $question['key']);
                    $labels[$question['key']] = $leadIn !== null ? $leadIn.' '.mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1) : $label;
                }
            }
        }

        return $labels;
    }
}
