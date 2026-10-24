<?php

namespace App\Assessment;

use App\Content\Text;
use App\Enums\Verdict;
use App\Models\AssessmentCaseResult;
use App\Models\Evaluation;
use App\Models\User;
use App\Support\Links;
use App\Support\ProductAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * The assessment cases of the standard (conceptual, functional completeness, functional
 * sufficiency) for every requirement that applies to a version: the "assessment units" the
 * manufacturer provides in their own step (AssessmentUnitController), each with its evidence, and
 * reviewers validate in the Review step (agree / disagree / question, AssessmentCaseReview).
 *
 * For the conceptual case a verdict is PROPOSED from the decision trees (the standard's rule):
 * FAIL when a path ends FAIL or a justification is missing or not justified, PASS when at least
 * one path ends PASS and every path is justified, NOT APPLICABLE otherwise. A person records it.
 *
 * Independence: a result is independent when recorded by an assessor of the product (external, e.g.
 * a test laboratory engineer: ProductRole::Assessor), or by a reviewer of the product who did not
 * last update any evaluation of that requirement (results from before reviewers only validated);
 * otherwise it is "not independent", which every result the manufacturer provides is.
 */
class AssessmentCases
{
    /** @var Collection<string, AssessmentCaseResult>|null "standard|REQ|case" => result */
    private ?Collection $results = null;

    public function __construct(private readonly Assessment $assessment) {}

    public static function for(Assessment $assessment): self
    {
        return new self($assessment);
    }

    /** @return Collection<string, AssessmentCaseResult> */
    public function results(): Collection
    {
        return $this->results ??= $this->assessment->version->caseResults()
            ->with(['recorder', 'document', 'reviews.reviewer'])
            ->get()
            ->each(fn (AssessmentCaseResult $r) => $r->reviews->each(fn ($review) => $review->setRelation('result', $r)))
            ->keyBy(fn (AssessmentCaseResult $r) => $r->standard.'|'.$r->requirement.'|'.$r->case_key);
    }

    /**
     * The cases of one requirement, as the pack defines them (empty without criteria): the known
     * keys in the standard's order, then any other key in pack order.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(string $standard, string $requirement): array
    {
        $criteria = $this->assessment->pack->assessment($standard, $requirement);
        $cases = [];
        foreach ($criteria['cases'] ?? [] as $case) {
            if (is_array($case) && is_string($case['key'] ?? null) && $case['key'] !== '') {
                $cases[$case['key']] = $case;
            }
        }
        $order = array_flip(AssessmentCaseResult::CASES);
        uksort($cases, fn (string $a, string $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return array_values($cases);
    }

    /**
     * A case the standard only states ("None.", "Not applicable.", "covered by the functional
     * sufficiency assessment"): a text, no purpose, units or verdict. Nothing to record.
     *
     * @param  array<string, mixed>  $case
     */
    public static function informative(array $case): bool
    {
        return empty($case['purpose']) && empty($case['preconditions']) && empty($case['units']) && empty($case['verdict']);
    }

    /** The id of one unit on the pages (its row, its form's error bag): "case-en18031-1-ACM-1-conceptual". */
    public static function anchor(string $standard, string $requirement, string $case): string
    {
        return 'case-'.$standard.'-'.$requirement.'-'.$case;
    }

    /**
     * The step's figures without the per-requirement texts (sidebar, overview, submit): units with a
     * verdict to give, recorded, INCONCLUSIVE, FAIL.
     *
     * @return array{total: int, recorded: int, inconclusive: int, fail: int}
     */
    public function progress(): array
    {
        $counts = ['total' => 0, 'recorded' => 0, 'inconclusive' => 0, 'fail' => 0];
        foreach ($this->units() as $unit) {
            $counts['total']++;
            $result = $unit['result'];
            if ($result) {
                $counts['recorded']++;
                $counts['inconclusive'] += $result->verdict === Verdict::Inconclusive ? 1 : 0;
                $counts['fail'] += $result->verdict === Verdict::Fail ? 1 : 0;
            }
        }

        return $counts;
    }

    /**
     * Performed outside REDact (an independent test laboratory, product_versions.units_external):
     * approval does not wait for missing units, and the units' results are not reported.
     */
    public function external(): bool
    {
        return (bool) $this->assessment->version->units_external;
    }

    /**
     * The units still to provide (no result yet), in pack order, each with a label and a link to the
     * step. Submitting warns about them, approving waits for them. None outside REDact.
     *
     * @return list<array{label: string, href: string}>
     */
    public function missingItems(): array
    {
        if ($this->external()) {
            return [];
        }
        $items = [];
        foreach ($this->units() as $unit) {
            if ($unit['result'] === null) {
                $items[] = [
                    'label' => $unit['requirement'].' · '.self::label($unit['case']),
                    'href' => route('versions.units', $this->assessment->version).'#'.self::anchor($unit['standard'], $unit['requirement'], $unit['case']),
                ];
            }
        }

        return $items;
    }

    /**
     * Every unit with a verdict to give (informative cases left out), with its result or null.
     *
     * @return list<array{standard: string, requirement: string, case: string, result: AssessmentCaseResult|null}>
     */
    public function units(): array
    {
        $units = [];
        foreach ($this->assessment->workingStandards() as $standard) {
            foreach (array_keys($this->assessment->applicableRequirements($standard)) as $code) {
                $code = (string) $code;
                foreach ($this->definitions($standard, $code) as $case) {
                    if (! self::informative($case)) {
                        $units[] = ['standard' => $standard, 'requirement' => $code, 'case' => $case['key'],
                            'result' => $this->results()->get($standard.'|'.$code.'|'.$case['key'])];
                    }
                }
            }
        }

        return $units;
    }

    /** "Conceptual assessment", or the key humanised for a case the app does not name. */
    public static function label(string $key): string
    {
        return Lang::has('assessment.cases.'.$key) ? __('assessment.cases.'.$key) : Str::ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * The verdicts a case can give: its "assignment of verdict" (all three when the pack lists
     * none), then INCONCLUSIVE, which a person can always record while evidence is missing.
     *
     * @param  array<string, mixed>  $case
     * @return list<Verdict>
     */
    public static function verdictsOf(array $case): array
    {
        $verdicts = [];
        foreach (array_keys((array) ($case['verdict'] ?? [])) as $label) {
            try {
                $verdicts[] = Verdict::fromTreeResult((string) $label);
            } catch (\InvalidArgumentException) {
            }
        }

        return [...($verdicts === [] ? Verdict::standard() : $verdicts), Verdict::Inconclusive];
    }

    /**
     * Everything the "Assessment units" step, the Review step's "Assessment units" view and the reports show.
     *
     * @return array{standards: list<array<string, mixed>>, counts: array{total: int, recorded: int, fail: int, inconclusive: int, self: int, stale: int}}
     */
    public function overview(): array
    {
        $standards = [];
        $counts = ['total' => 0, 'recorded' => 0, 'fail' => 0, 'inconclusive' => 0, 'self' => 0, 'stale' => 0];
        $pack = $this->assessment->pack;

        foreach ($this->assessment->workingStandards() as $standard) {
            $requirements = [];
            foreach ($this->assessment->applicableRequirements($standard) as $code => $definition) {
                $code = (string) $code;
                $tree = $pack->tree($standard, $code);
                $criteria = $pack->assessment($standard, $code);
                $evaluations = $this->evaluationsOf($standard, $code);
                $summary = $this->summary($standard, $code);
                $lastChange = collect($evaluations)->max(fn (Evaluation $e) => $e->updated_at);

                $cases = [];
                foreach ($this->definitions($standard, $code) as $case) {
                    $informative = self::informative($case);
                    $result = $informative ? null : $this->results()->get($standard.'|'.$code.'|'.$case['key']);
                    $stale = $result && $lastChange && $result->updated_at && $lastChange->greaterThan($result->updated_at);
                    $cases[] = [
                        'key' => $case['key'],
                        'label' => self::label($case['key']),
                        'informative' => $informative,
                        'text' => $case['text'] ?? null,
                        'clause' => (string) ($case['clause'] ?? ''),
                        'purpose' => $case['purpose'] ?? null,
                        'preconditions' => $case['preconditions'] ?? null,
                        'units' => $case['units'] ?? null,
                        'rules' => (array) ($case['verdict'] ?? []),
                        'verdicts' => self::verdictsOf($case),
                        'result' => $result,
                        'stale' => (bool) $stale,
                        'suggestion' => $case['key'] === 'conceptual' && ! $informative ? $this->suggestion($summary) : null,
                    ];
                    if ($informative) {
                        continue;
                    }
                    $counts['total']++;
                    if ($result) {
                        $counts['recorded']++;
                        $counts['fail'] += $result->verdict === Verdict::Fail ? 1 : 0;
                        $counts['self'] += $result->independent ? 0 : 1;
                        $counts['stale'] += $stale ? 1 : 0;
                        $counts['inconclusive'] += $result->verdict === Verdict::Inconclusive ? 1 : 0;
                    }
                }

                $requirements[] = [
                    'code' => $code,
                    'title' => $tree?->title() ?: Text::of($definition['title'] ?? $code),
                    'clause' => (string) ($criteria['clause'] ?? $tree?->clause() ?? ''),
                    'criteria' => $criteria,
                    'summary' => $summary,
                    'cases' => $cases,
                ];
            }
            if ($requirements !== []) {
                $standards[] = ['code' => $standard, 'name' => $pack->standardName($standard), 'requirements' => $requirements];
            }
        }

        return ['standards' => $standards, 'counts' => $counts];
    }

    /**
     * The decision trees of one requirement in figures: what it is walked for (assets,
     * interfaces, the product...), verdicts, not justified, still to walk ("pending_label":
     * "2 assets", named by TargetNames).
     *
     * @return array{total: int, pass: int, fail: int, na: int, unjustified: int, inconclusive: int, pending: int, pending_label: string}
     */
    public function summary(string $standard, string $requirement): array
    {
        $tree = $this->assessment->pack->tree($standard, $requirement);
        $summary = ['total' => 0, 'pass' => 0, 'fail' => 0, 'na' => 0, 'unjustified' => 0, 'inconclusive' => 0, 'pending' => 0, 'pending_label' => ''];
        $pending = [];
        foreach ($this->assessment->targets($standard, $requirement) as $item) {
            $evaluation = $this->assessment->evaluation($standard, $requirement, $item);
            $status = $this->assessment->status($evaluation, $tree);
            $summary['total']++;
            if (in_array($status, ['missing', 'incomplete', 'invalid'], true)) {
                $summary['pending']++;
                $pending[] = $item;

                continue;
            }
            if ($status === 'inconclusive') {
                $summary['inconclusive']++;

                continue;
            }
            $summary['unjustified'] += $status === 'unjustified' ? 1 : 0;
            $summary[match ($evaluation?->verdict) {
                Verdict::Pass => 'pass', Verdict::Fail => 'fail', default => 'na',
            }]++;
        }
        $summary['pending_label'] = (new TargetNames($this->assessment->pack))->count($pending, $this->assessment->pack->requirement($requirement));

        return $summary;
    }

    /**
     * The conceptual verdict the decision trees propose, with its reasons. A path ending FAIL makes it
     * FAIL whatever the rest; otherwise INCONCLUSIVE while an evaluation is inconclusive or still to
     * walk (or ends at an impossible outcome); null when nothing applies.
     *
     * @param  array{total: int, pass: int, fail: int, na: int, unjustified: int, inconclusive: int, pending: int, pending_label?: string}  $summary
     * @return array{verdict: Verdict|null, reasons: list<string>}
     */
    public function suggestion(array $summary): array
    {
        if ($summary['total'] === 0) {
            return ['verdict' => null, 'reasons' => [trans_choice('assessment.suggest.pending', 0, ['what' => ''])]];
        }
        if ($summary['fail'] === 0 && ($summary['pending'] > 0 || $summary['inconclusive'] > 0)) {
            $reasons = [];
            if ($summary['inconclusive'] > 0) {
                $reasons[] = trans_choice('assessment.suggest.inconclusive', $summary['inconclusive'], ['count' => $summary['inconclusive']]);
            }
            if ($summary['pending'] > 0) {
                $reasons[] = trans_choice('assessment.suggest.pending', $summary['pending'], ['what' => $summary['pending_label'] ?? (string) $summary['pending']]);
            }

            return ['verdict' => Verdict::Inconclusive, 'reasons' => $reasons];
        }
        $reasons = [];
        if ($summary['fail'] > 0) {
            $reasons[] = trans_choice('assessment.suggest.fail_paths', $summary['fail'], ['count' => $summary['fail']]);
        }
        if ($summary['unjustified'] > 0) {
            $reasons[] = trans_choice('assessment.suggest.unjustified', $summary['unjustified'], ['count' => $summary['unjustified']]);
        }
        if ($reasons !== []) {
            return ['verdict' => Verdict::Fail, 'reasons' => $reasons];
        }
        if ($summary['pass'] > 0) {
            return ['verdict' => Verdict::Pass, 'reasons' => [trans_choice('assessment.suggest.pass', $summary['pass'], ['count' => $summary['pass'], 'total' => $summary['total']])]];
        }

        return ['verdict' => Verdict::NotApplicable, 'reasons' => [trans_choice('assessment.suggest.na', $summary['total'], ['count' => $summary['total']])]];
    }

    /**
     * Everything still INCONCLUSIVE in the version: evaluations, then assessment case results (not
     * outside REDact), each with a label and a link. Submitting warns about them, approving waits for them.
     *
     * @return list<array{label: string, href: string}>
     */
    public function inconclusiveItems(): array
    {
        $version = $this->assessment->version;
        $items = [];
        foreach ($this->assessment->inconclusive() as $row) {
            $items[] = [
                'label' => $row['requirement'].' · '.($row['item']?->label() ?? __('evaluations.product')),
                'href' => Links::walk($version, $row['standard'], $row['requirement'], $row['item']),
            ];
        }
        foreach ($this->external() ? [] : $this->results() as $result) {
            if ($result->verdict === Verdict::Inconclusive) {
                $items[] = [
                    'label' => $result->requirement.' · '.self::label($result->case_key),
                    'href' => route('versions.units', $version).'#'.self::anchor($result->standard, $result->requirement, $result->case_key),
                ];
            }
        }

        return $items;
    }

    /**
     * Recorded by an assessor of the product (external, never edits the evaluations), or by a
     * reviewer who did not last update an evaluation of the requirement (data from before reviewers
     * only validated)?
     */
    public function independent(User $user, ProductAccess $access, string $standard, string $requirement): bool
    {
        if ($access->isAssessor()) {
            return true;
        }
        if (! $access->canReview()) {
            return false;
        }

        return ! collect($this->evaluationsOf($standard, $requirement))->contains(fn (Evaluation $e) => $e->updated_by === $user->id);
    }

    /** @return list<Evaluation> */
    private function evaluationsOf(string $standard, string $requirement): array
    {
        return array_values(array_filter(
            $this->assessment->context()->allEvaluations(),
            fn (Evaluation $e) => $e->standard === $standard && $e->requirement === $requirement,
        ));
    }
}
