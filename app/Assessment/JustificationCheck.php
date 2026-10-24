<?php

namespace App\Assessment;

/**
 * Whether one evaluation is justified the way the standard asks (see Justification).
 *
 * A rule is one entry of the "justification" list of the pack's assessment criteria that
 * applies to the walked path ("node" as printed in the standard, "tree_node" the tree node it is
 * checked at). An informative rule (node the pack cannot map, implementation category condition) is
 * shown, never checked. Each of its E.Info ids has a state:
 *   documented    written for this object (or for the product), the path or the justification is there
 *   missing       the E.Info rules place it on this object (or on the product) and it is empty
 *   not_required  the E.Info rules do not place it on this object: it does not count
 *   untracked     not an E.Info id (IC.… implementation categories): it does not count
 * An id printed for another asset type or interface kind is read as the object's own variant
 * ("mapped"). A rule none of whose ids counts for the object "needs a human check": shown and
 * counted, never blocking, never silently met.
 */
final readonly class JustificationCheck
{
    /**
     * @param  list<array{node: string, tree_node: string|null, outcome: string|null, match: string, text: string, informative: bool, check: bool, met: bool,
     *     ids: list<array{id: string, mapped: string|null, state: string, object: string|null, href: string|null}>}>  $rules
     * @param  list<string>  $reasons  why it is not justified (translated)
     */
    public function __construct(
        public bool $walked,
        public int $textLength,
        public bool $textLongEnough,
        public bool $hasCriteria,
        public array $rules,
        public bool $justified,
        public array $reasons,
        public bool $inconclusive = false,
    ) {}

    /** Rules the app cannot check for this object: a person checks them (not blocking). */
    public function toCheck(): int
    {
        return count(array_filter($this->rules, fn (array $rule) => $rule['check']));
    }

    /** The rules that are not met. */
    public function unmetRules(): array
    {
        return array_values(array_filter($this->rules, fn (array $rule) => ! $rule['met']));
    }

    /**
     * The E.Info ids still to write, each once.
     *
     * @return list<array{id: string, mapped: string|null, state: string, object: string|null, href: string|null}>
     */
    public function missing(): array
    {
        $missing = [];
        foreach ($this->unmetRules() as $rule) {
            foreach ($rule['ids'] as $id) {
                if ($id['state'] === 'missing') {
                    $missing[$id['id']] = $id;
                }
            }
        }

        return array_values($missing);
    }
}
