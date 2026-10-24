<?php

namespace App\Assessment;

use App\Content\ScopingResult;
use App\Enums\ConformityRoute;
use App\Support\Links;

/**
 * The end of the assessment: can the manufacturer self-assess (Module A), or
 * is a Notified Body mandatory (Module B + C, or H)?
 *
 * Self-assessment needs every applicable decision tree walked with no FAIL
 * ("EN 18031 fully implemented", computed here, never asked) and every RED
 * restriction met (the pack's conformity questions: blank passwords,
 * parental control, secure update...). A single FAIL makes a Notified Body
 * mandatory.
 */
class Conformity
{
    /** Conformity question group => the restriction key of ScopingResult, where they differ. */
    public const RESTRICTION_OF = ['fraud_update' => 'fraud'];

    private ?ScopingResult $result = null;

    /** @var array<string, bool|string|null>|null */
    private ?array $states = null;

    /** @var array<string, array{total: int, done: int, missing: int, incomplete: int, invalid: int, unjustified: int, inconclusive: int, to_check: int, pass: int, fail: int, na: int, follows: int, unlinked: int}>|null */
    private ?array $progress = null;

    /** @var array<string, array{value: bool|null, settled: bool, because: list<array{text: string, href: string|null}>}>|null */
    private ?array $suggestions = null;

    public function __construct(private readonly Assessment $assessment) {}

    /**
     * Decision-tree outcome per standard in scope.
     *
     * @return array<string, array{total: int, done: int, missing: int, incomplete: int, invalid: int, unjustified: int, inconclusive: int, to_check: int, pass: int, fail: int, na: int, follows: int, unlinked: int}>
     */
    public function progress(): array
    {
        if ($this->progress === null) {
            $this->progress = [];
            foreach ($this->assessment->standards() as $standard) {
                $this->progress[$standard] = $this->assessment->evaluationProgress($standard);
            }
        }

        return $this->progress;
    }

    /** true: all walked, no FAIL. false: at least one FAIL. null: trees still to walk (or nothing in scope yet). */
    public function en18031Fully(): ?bool
    {
        $progress = $this->progress();
        if ($progress === []) {
            return null;
        }
        if (array_sum(array_column($progress, 'fail')) > 0) {
            return false;
        }
        foreach ($progress as $p) {
            // Walked with a valid verdict is enough here; a justification still to complete
            // (unjustified) blocks the submission for review, not the conformity route. An
            // INCONCLUSIVE evaluation is still pending (null), never a FAIL.
            // A walk the inventory does not list yet (unlinked, e.g. the secure communication
            // mechanism of an asset) is still to walk too.
            if ($p['total'] === 0 || $p['total'] > $p['done'] + $p['unjustified'] || $p['unlinked'] > 0) {
                return null;
            }
        }

        return true;
    }

    public function result(): ScopingResult
    {
        if ($this->result === null) {
            $pack = $this->assessment->pack;
            $answers = $this->assessment->version->scopingAnswers()->pluck('value', 'question')->all();
            $answers['_en18031_full'] = $this->en18031Fully();
            $result = $pack->rules()->scoping($answers, $pack);
            // The suggestions need the articles and standards in scope; a restriction they settle
            // (group "settled") then counts as met: the rules get those groups as _settled.
            $this->suggestions = $this->normalise($pack->rules()->restrictionSuggestions($this->assessment->context(), $result));
            $settled = array_keys(array_filter($this->suggestions, fn (array $suggestion) => $suggestion['settled']));
            $this->result = $settled === [] ? $result : $pack->rules()->scoping($answers + ['_settled' => $settled], $pack);
        }

        return $this->result;
    }

    /**
     * Conformity groups whose restriction the data settles by itself (PackRules::restrictionSuggestions
     * "settled"): their questions are not asked.
     *
     * @return list<string> group keys
     */
    public function settledGroups(): array
    {
        $this->result();

        return array_keys(array_filter($this->suggestions ?? [], fn (array $s) => $s['settled']));
    }

    /** @return list<string> the questions of the settled groups (not asked, not stored) */
    public function settledQuestions(): array
    {
        $settled = $this->settledGroups();
        $keys = [];
        foreach ($this->assessment->pack->scoping()['sections'] ?? [] as $section) {
            foreach ($section['groups'] ?? [] as $group) {
                if (in_array($group['key'] ?? null, $settled, true)) {
                    array_push($keys, ...array_column($group['questions'] ?? [], 'key'));
                }
            }
        }

        return $keys;
    }

    /**
     * Each restriction as the Conformity step shows it (and the reports print it): true met, false
     * not met, null still to answer (a visible question of its card not saved yet, or decision trees
     * still to complete), 'na' when the article it depends on is not in scope. A restriction the
     * data settles (no secure update to protect) is met without questions.
     *
     * @return array<string, bool|string|null> restriction key => state, "en18031" first
     */
    public function restrictionStates(): array
    {
        if ($this->states !== null) {
            return $this->states;
        }
        $result = $this->result();
        $form = new ScopingForm($this->assessment->pack);
        $answers = $this->assessment->version->scopingAnswers()->pluck('value', 'question')->all();
        $settled = $this->settledGroups();

        $states = ['en18031' => match (true) {
            $this->progress() === [] => null,
            $this->en18031Fully() === true => true,
            $this->failCount() > 0 => false,
            default => null,
        }];
        foreach ($result->restrictions as $key => $met) {
            if (! array_key_exists($key, $states)) {
                $states[$key] = $met;
            }
        }
        foreach ($form->sections(ScopingForm::CONFORMITY) as $section) {
            foreach ($section['groups'] ?? [] as $group) {
                $questions = array_values($group['questions'] ?? []);
                if ($questions === []) {
                    continue;
                }
                $key = self::RESTRICTION_OF[$group['key']] ?? $group['key'];
                if (! $form->isVisible($questions[0], $answers, $result)) {
                    $states[$key] = 'na';

                    continue;
                }
                $complete = in_array($group['key'], $settled, true) || array_filter($questions,
                    fn (array $q) => $form->isVisible($q, $answers, $result) && ! array_key_exists($q['key'], $answers)) === [];
                $states[$key] = $complete ? ($result->restrictions[$key] ?? null) : null;
            }
        }

        return $this->states = $states;
    }

    /** true: Module A allowed. false: a Notified Body is mandatory. null: not known yet. */
    public function selfAssessmentPossible(): ?bool
    {
        return $this->result()->selfAssessment;
    }

    public function allows(ConformityRoute $route): bool
    {
        return $route !== ConformityRoute::ModuleA || $this->selfAssessmentPossible() !== false;
    }

    /**
     * Routes the person may choose now.
     *
     * @return list<ConformityRoute>
     */
    public function allowedRoutes(): array
    {
        return array_values(array_filter(ConformityRoute::cases(), fn (ConformityRoute $r) => $this->allows($r)));
    }

    public function failCount(): int
    {
        return array_sum(array_column($this->progress(), 'fail'));
    }

    public function remainingCount(): int
    {
        return array_sum(array_map(fn (array $p) => $p['total'] - $p['done'] + $p['unlinked'], $this->progress()));
    }

    /**
     * The pack's suggested answers to the conformity questions (scoping, inventory and decision
     * trees), each reason with the URL of its source. Only a form default: the route is decided by
     * the saved answers (result()).
     *
     * @return array<string, array{value: bool|null, settled: bool, because: list<array{text: string, href: string|null}>}>
     */
    public function suggestions(): array
    {
        $this->result();

        return $this->suggestions ?? [];
    }

    /**
     * @param  array<string, array{value: bool|null, settled?: bool, because: list<array{text: string, link?: array<string, string|null>}>}>  $raw
     * @return array<string, array{value: bool|null, settled: bool, because: list<array{text: string, href: string|null}>}>
     */
    private function normalise(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $suggestion) {
            $because = [];
            foreach ($suggestion['because'] as $reason) {
                if (trim($reason['text']) !== '') {
                    $because[] = ['text' => trim($reason['text']), 'href' => $this->href($reason['link'] ?? [])];
                }
            }
            $out[$key] = ['value' => $suggestion['value'], 'settled' => ($suggestion['settled'] ?? false) === true, 'because' => $because];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $link  ['step' => scoping|inventory|evaluate, ...] (PackRules::restrictionSuggestions);
     *                                      an evaluation link with "item" => null is the product's, without "item" the requirement's page
     */
    private function href(array $link): ?string
    {
        $version = $this->assessment->version;
        $item = isset($link['item']) && is_string($link['item']) ? $link['item'] : null;

        return match ($link['step'] ?? null) {
            'scoping' => route('versions.scoping', $version),
            'inventory' => is_string($link['list'] ?? null)
                ? ($item ? route('versions.inventory.edit', [$version, $link['list'], $item]) : route('versions.inventory', [$version, $link['list']]))
                : null,
            'evaluate' => is_string($link['standard'] ?? null) && is_string($link['requirement'] ?? null)
                ? (array_key_exists('item', $link)
                    ? Links::walk($version, $link['standard'], $link['requirement'], $item)
                    : route('versions.evaluate.requirement', [$version, $link['standard'], $link['requirement']]))
                : null,
            default => null,
        };
    }

    /** The chosen route contradicts the results (self-assessment with a FAIL or an unmet restriction). */
    public function conflict(): bool
    {
        $route = $this->assessment->version->conformity_route;

        return $route !== null && ! $this->allows($route);
    }
}
