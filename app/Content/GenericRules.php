<?php

namespace App\Content;

use App\Content\Contracts\PackRules;
use App\Models\InventoryItem;

/**
 * Data-driven default rules, enough for the demo pack:
 *  - a scoping question marked "articles": [...] brings those articles into scope when true;
 *  - a standard is in scope when its "article" is;
 *  - a requirement is evaluated for every item of its list (or once for the product);
 *  - an E.Info item is required for every item of its list once the requirement has a node
 *    (nothing is conditional).
 *
 * Real packs extend this class and override what they need.
 */
class GenericRules implements PackRules
{
    public function scoping(array $answers, ContentPack $pack): ScopingResult
    {
        $articles = [];
        foreach ($this->questions($pack) as $question) {
            foreach ($question['articles'] ?? [] as $article) {
                $articles[$article] = ($articles[$article] ?? false) || ($answers[$question['key']] ?? false) === true;
            }
        }

        $standards = [];
        foreach ($pack->standards() as $code => $standard) {
            if ($articles[$standard['article'] ?? ''] ?? false) {
                $standards[] = $code;
            }
        }

        // The engine passes the decision-tree outcome as _en18031_full (true: all walked,
        // no FAIL; false: a FAIL; null: still to walk). A FAIL rules out self-assessment.
        $full = $answers['_en18031_full'] ?? null;
        $self = $full === null ? null : (bool) $full;

        return new ScopingResult($articles, $standards, ['en18031' => $full === true], $self);
    }

    public function evaluationTargets(string $standard, string $requirement, VersionContext $context): array
    {
        $definition = app(ContentPack::class)->requirement($requirement);
        if (! $definition || ! in_array($standard, $definition['standards'], true)) {
            return [];
        }
        if (empty($definition['list'])) {
            return [null];
        }

        return $context->items($definition['list'])->values()->all();
    }

    public function requiredEinfo(string $standard, ?InventoryItem $item, VersionContext $context): array
    {
        $required = [];
        foreach (app(ContentPack::class)->einfo() as $code => $einfo) {
            if (! in_array($standard, $einfo['standards'] ?? [], true)) {
                continue;
            }
            if (($einfo['list'] ?? null) !== $item?->list) {
                continue;
            }
            if ($context->node($standard, $einfo['requirement'], $item) !== null) {
                $required[] = $code;
            }
        }

        return $required;
    }

    public function conditionalEinfo(string $standard, ?InventoryItem $item, VersionContext $context): array
    {
        return [];
    }

    /**
     * requirements.json "follows_from": [{"requirement": "X-1", "verdicts": ["FAIL"]}]: every walk
     * of X-1 that ended with one of those verdicts settles this requirement for its object.
     */
    public function followsFrom(string $standard, string $requirement, VersionContext $context): array
    {
        $definition = app(ContentPack::class)->requirement($requirement);
        if (! $definition || ! in_array($standard, $definition['standards'], true)) {
            return [];
        }

        $out = [];
        foreach ($definition['follows_from'] ?? [] as $rule) {
            $source = (string) ($rule['requirement'] ?? '');
            $verdicts = (array) ($rule['verdicts'] ?? []);
            foreach ($this->evaluationTargets($standard, $source, $context) as $item) {
                $verdict = $context->evaluation($standard, $source, $item)?->verdict?->value;
                if ($verdict !== null && in_array($verdict, $verdicts, true)) {
                    $out[] = ['item' => $item, 'requirement' => $source, 'state' => 'fail'];
                }
            }
        }

        return $out;
    }

    public function innerTargets(string $standard, string $requirement, ?InventoryItem $item, VersionContext $context): array
    {
        return [];
    }

    /** Nothing is derived by default: the conformity questions start unanswered. */
    public function restrictionSuggestions(VersionContext $context, ScopingResult $scoping): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> every question of every section and group */
    protected function questions(ContentPack $pack): array
    {
        $questions = [];
        foreach ($pack->scoping()['sections'] ?? [] as $section) {
            foreach ($section['groups'] ?? [] as $group) {
                foreach ($group['questions'] ?? [] as $question) {
                    $questions[] = $question;
                }
            }
        }

        return $questions;
    }
}
