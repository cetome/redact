<?php

namespace App\Assessment;

use App\Content\ContentPack;
use App\Content\ScopingResult;
use App\Content\Text;
use App\Content\Tree;
use App\Content\VersionContext;
use App\Declaration\DeclarationData;
use App\Enums\Verdict;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;

/**
 * What applies to one product version and how far the work has gone.
 * Everything content-specific is delegated to the content pack rules;
 * this class only combines the answers with them. Build one per request.
 */
class Assessment
{
    private ?VersionContext $context = null;

    private ?ScopingResult $scoping = null;

    /** @var array<string, list<InventoryItem|null>> */
    private array $targets = [];

    /** @var array<string, list<array{item: InventoryItem|null, requirement: string, state: string}>> */
    private array $follows = [];

    private ?Justification $justification = null;

    private ?InventoryGuide $inventoryGuide = null;

    private ?Conformity $conformity = null;

    public function __construct(
        public readonly ProductVersion $version,
        public readonly ContentPack $pack,
    ) {}

    public static function for(ProductVersion $version): self
    {
        return new self($version, app(ContentPack::class));
    }

    public function context(): VersionContext
    {
        return $this->context ??= new VersionContext($this->version);
    }

    /** Forget cached data after a write in the same request. */
    public function refresh(): void
    {
        $this->context = null;
        $this->scoping = null;
        $this->targets = [];
        $this->follows = [];
        $this->justification = null;
        $this->inventoryGuide = null;
        $this->conformity = null;
    }

    /**
     * The conformity outcome (saved restriction answers, EN 18031 implementation from the trees,
     * restrictions the data settles, route): the one source of the Conformity step, the reports,
     * the declaration and the workbook export.
     */
    public function conformity(): Conformity
    {
        return $this->conformity ??= new Conformity($this);
    }

    /** The "Assets & interfaces" step as a sequence of lists (see InventoryGuide). */
    public function inventoryGuide(): InventoryGuide
    {
        return $this->inventoryGuide ??= new InventoryGuide($this);
    }

    /** Is each walked verdict justified the way the standard asks (see Justification)? */
    public function justification(): Justification
    {
        return $this->justification ??= new Justification($this);
    }

    public function scoping(): ScopingResult
    {
        return $this->scoping ??= $this->pack->rules()->scoping(
            $this->version->scopingAnswers()->pluck('value', 'question')->all(),
            $this->pack,
        );
    }

    /** @return list<string> standard codes in scope, in pack order */
    public function standards(): array
    {
        $inScope = $this->scoping()->standards;

        return array_values(array_filter(array_keys($this->pack->standards()), fn ($c) => in_array($c, $inScope, true)));
    }

    public function hasStandard(string $standard): bool
    {
        return in_array($standard, $this->standards(), true);
    }

    /** @return list<InventoryItem|null> */
    public function targets(string $standard, string $requirement): array
    {
        return $this->targets[$standard.'|'.$requirement] ??=
            $this->pack->rules()->evaluationTargets($standard, $requirement, $this->context());
    }

    /**
     * Walks of a requirement settled by another walk (PackRules::followsFrom): "fail" (fails with
     * the source walk, e.g. SCM-2 where SCM-1 ends FAIL: not walked, counted once as "follows
     * from") or "unlinked" (the source walk calls for an object the inventory does not link yet).
     *
     * @return list<array{item: InventoryItem|null, requirement: string, state: string}>
     */
    public function followsFrom(string $standard, string $requirement): array
    {
        return $this->follows[$standard.'|'.$requirement] ??=
            $this->pack->rules()->followsFrom($standard, $requirement, $this->context());
    }

    /**
     * The objects of the inner "For each" loop of one walk (e.g. the communicated assets a secure
     * communication mechanism protects), shown with the walk.
     *
     * @return list<InventoryItem>
     */
    public function innerTargets(string $standard, string $requirement, ?InventoryItem $item): array
    {
        return $this->pack->rules()->innerTargets($standard, $requirement, $item, $this->context());
    }

    /**
     * Requirements of a standard that currently have something to evaluate.
     *
     * @return array<string, array<string, mixed>>
     */
    public function applicableRequirements(string $standard): array
    {
        return array_filter(
            $this->pack->requirementsFor($standard),
            fn (array $r) => $this->targets($standard, $r['code']) !== [],
        );
    }

    public function evaluation(string $standard, string $requirement, ?InventoryItem $item): ?Evaluation
    {
        return $this->context()->evaluation($standard, $requirement, $item);
    }

    /**
     * missing | inconclusive (set by a person while evidence is missing, not a verdict of EN 18031)
     * | incomplete (node or verdict missing) | invalid (verdict not reachable)
     * | unjustified (walked, but the justification is missing, too short or not based on what the
     * standard requires, see Justification) | done
     */
    public function status(?Evaluation $evaluation, ?Tree $tree): string
    {
        if (! $evaluation || (! $evaluation->decision_node && ! $evaluation->verdict)) {
            return 'missing';
        }
        if ($evaluation->verdict === Verdict::Inconclusive) {
            return 'inconclusive';
        }
        if (! $evaluation->decision_node || ! $evaluation->verdict) {
            return 'incomplete';
        }
        if ($tree && ! $tree->allows($evaluation->decision_node, $evaluation->verdict)) {
            return 'invalid';
        }
        if (! $this->justification()->check($evaluation)->justified) {
            return 'unjustified';
        }

        return 'done';
    }

    /**
     * Evaluations of the working standards marked INCONCLUSIVE, in pack order. Submitting warns
     * about them; approving the version waits for them.
     *
     * @return list<array{standard: string, requirement: string, item: InventoryItem|null, evaluation: Evaluation}>
     */
    public function inconclusive(): array
    {
        $rows = [];
        foreach ($this->workingStandards() as $standard) {
            foreach (array_keys($this->applicableRequirements($standard)) as $code) {
                foreach ($this->targets($standard, (string) $code) as $item) {
                    $evaluation = $this->evaluation($standard, (string) $code, $item);
                    if ($evaluation?->verdict === Verdict::Inconclusive) {
                        $rows[] = ['standard' => $standard, 'requirement' => (string) $code, 'item' => $item, 'evaluation' => $evaluation];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Walked evaluations of the working standards whose justification does not meet the standard
     * (status "unjustified"), in pack order. Submitting a version for review waits for them.
     *
     * @return list<array{standard: string, requirement: string, item: InventoryItem|null, evaluation: Evaluation}>
     */
    public function unjustified(): array
    {
        $rows = [];
        foreach ($this->workingStandards() as $standard) {
            foreach (array_keys($this->applicableRequirements($standard)) as $code) {
                $tree = $this->pack->tree($standard, (string) $code);
                foreach ($this->targets($standard, (string) $code) as $item) {
                    $evaluation = $this->evaluation($standard, (string) $code, $item);
                    if ($evaluation && $this->status($evaluation, $tree) === 'unjustified') {
                        $rows[] = ['standard' => $standard, 'requirement' => (string) $code, 'item' => $item, 'evaluation' => $evaluation];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Progress of the decision trees of one standard.
     *
     * "follows": walks not walked because they fail with another walk (PackRules::followsFrom),
     * counted apart, never in total or fail (the source walk's FAIL already counts); "unlinked":
     * objects still to link before their walks can be listed (each counted once).
     *
     * @return array{total: int, done: int, missing: int, incomplete: int, invalid: int, unjustified: int, inconclusive: int, to_check: int, pass: int, fail: int, na: int, follows: int, unlinked: int}
     */
    public function evaluationProgress(string $standard): array
    {
        $counts = ['total' => 0, 'done' => 0, 'missing' => 0, 'incomplete' => 0, 'invalid' => 0, 'unjustified' => 0, 'inconclusive' => 0, 'to_check' => 0, 'pass' => 0, 'fail' => 0, 'na' => 0, 'follows' => 0, 'unlinked' => 0];

        $unlinked = [];
        foreach (array_keys($this->pack->requirementsFor($standard)) as $code) {
            foreach ($this->followsFrom($standard, (string) $code) as $entry) {
                if ($entry['state'] === 'fail') {
                    $counts['follows']++;
                } else {
                    // One per object to link, whatever the number of requirements waiting for it.
                    $unlinked[$entry['requirement'].'|'.($entry['item']->id ?? '-')] = true;
                }
            }
        }
        $counts['unlinked'] = count($unlinked);

        foreach ($this->applicableRequirements($standard) as $code => $requirement) {
            $tree = $this->pack->tree($standard, $code);
            foreach ($this->targets($standard, $code) as $item) {
                $evaluation = $this->evaluation($standard, $code, $item);
                $counts['total']++;
                $status = $this->status($evaluation, $tree);
                $counts[$status]++;
                // Done, but a rule of the standard the app cannot check for this object: a person does.
                if ($status === 'done' && $evaluation && $this->justification()->check($evaluation)->toCheck() > 0) {
                    $counts['to_check']++;
                }
                // Inconclusive is counted by its status above, not as a verdict.
                if ($evaluation?->verdict && $evaluation->verdict !== Verdict::Inconclusive) {
                    $counts[match ($evaluation->verdict) {
                        Verdict::Pass => 'pass', Verdict::Fail => 'fail', default => 'na',
                    }]++;
                }
            }
        }

        return $counts;
    }

    /**
     * E.Info per object for one standard: the required items ("codes", what progress counts) and
     * the conditional ones ("conditional": placed on the object, but the app cannot tell yet
     * whether their condition holds; shown, never counted as missing).
     *
     * @return list<array{item: InventoryItem|null, codes: list<string>, conditional: list<string>}>
     */
    public function requiredEinfo(string $standard): array
    {
        $rules = $this->pack->rules();
        $objects = [null];
        foreach (array_keys($this->pack->lists()) as $list) {
            foreach ($this->context()->items($list) as $item) {
                $objects[] = $item;
            }
        }

        $rows = [];
        foreach ($objects as $item) {
            $codes = $rules->requiredEinfo($standard, $item, $this->context());
            $conditional = array_values(array_diff($rules->conditionalEinfo($standard, $item, $this->context()), $codes));
            if ($codes !== [] || $conditional !== []) {
                $rows[] = ['item' => $item, 'codes' => $codes, 'conditional' => $conditional];
            }
        }

        return $rows;
    }

    /** @return array{total: int, done: int} */
    public function einfoProgress(string $standard): array
    {
        $texts = $this->version->einfoEntries()->whereNotNull('text')->where('text', '!=', '')
            ->get(['einfo', 'inventory_item_id'])
            ->map(fn ($e) => $e->einfo.'|'.($e->inventory_item_id ?? '-'))
            ->flip();

        $total = 0;
        $done = 0;
        foreach ($this->requiredEinfo($standard) as $row) {
            foreach ($row['codes'] as $code) {
                $total++;
                if (isset($texts[$code.'|'.($row['item']->id ?? '-')])) {
                    $done++;
                }
            }
        }

        return ['total' => $total, 'done' => $done];
    }

    /** The workflow steps in order: the keys of steps(), the sidebar, WorkflowSummary. */
    public const ORDER = ['description', 'scoping', 'risks', 'inventory', 'evaluate', 'einfo', 'units', 'conformity', 'review', 'declaration'];

    /**
     * The step after $key, for "Save and continue" buttons and "Next" links: its key, label and
     * URL on this version (decision trees, E.Info: the first working standard). Null after the
     * last step or for a key outside the order (ai, reports, team).
     *
     * @return array{key: string, label: string, href: string}|null
     */
    public function nextStepAfter(string $key): ?array
    {
        $at = array_search($key, self::ORDER, true);
        if ($at === false || ! isset(self::ORDER[$at + 1])) {
            return null;
        }
        $next = self::ORDER[$at + 1];

        return ['key' => $next, 'label' => __('workflow.steps.'.$next), 'href' => $this->stepUrl($next)];
    }

    /** The URL of one step on this version (per-standard steps: $standard, else the first working standard). */
    public function stepUrl(string $key, ?string $standard = null): string
    {
        $version = $this->version;

        return match ($key) {
            'description' => route('versions.edit', $version),
            'risks' => route('versions.risks', $version),
            'scoping' => route('versions.scoping', $version),
            'inventory' => route('versions.inventory', [$version, $this->inventoryGuide()->listKeys()[0] ?? array_key_first($this->pack->lists()) ?? '']),
            'evaluate' => route('versions.evaluate', [$version, $standard ?? $this->workingStandards()[0] ?? '']),
            'einfo' => route('versions.einfo', [$version, $standard ?? $this->workingStandards()[0] ?? '']),
            'units' => route('versions.units', $version),
            'conformity' => route('versions.conformity', $version),
            'review' => route('versions.review', $version),
            'declaration' => route('versions.declaration', $version),
            default => route('versions.show', $version),
        };
    }

    /**
     * The workflow steps with a simple completion state, for the version overview.
     *
     * @return list<array{key: string, route: string, params: array<string, string>, done: bool, detail: string, done_count?: int, total?: int}>
     */
    public function steps(): array
    {
        $version = $this->version;
        // A risk is complete when rated, tied to an article, with a remediation and a residual level
        // (the columns of the owner's risk assessment workbook); suggested threats added in one click
        // are not "done" until a person completes them (owner, 2026-10-23).
        $riskRows = $version->riskEntries()->get(['id', 'impact', 'likelihood', 'articles', 'remediation', 'residual_rank']);
        $risks = $riskRows->count();
        $risksOpen = $riskRows->filter(fn ($r) => ! $r->impact || ! $r->likelihood || (array) $r->articles === []
            || blank($r->remediation) || $r->residual_rank === null)->count();

        $counts = $version->inventoryItems()->selectRaw('list, count(*) as n')->groupBy('list')->pluck('n', 'list');
        $inventoryDetail = collect($this->pack->lists())
            ->map(fn (array $list, string $key) => (int) ($counts[$key] ?? 0).' '.mb_strtolower(Text::of($list['label'])))
            ->filter(fn (string $label, string $key) => ($counts[$key] ?? 0) > 0 || in_array($key, ['assets', 'interfaces'], true))
            ->implode(' · ');

        // Order (self::ORDER, owner's figures): the product, then the essential requirements (the
        // cetome.com RED scoping: articles 3.3 d/e/f and parts of EN 18031, confirmed), then the
        // product risks (each tagged with the articles it concerns and the EN 18031 requirements that
        // cover it, checked against that scope on the risk page), then the skeleton of the REDact
        // E.DT / E.Info workbooks: assets and interfaces, decision trees, E.Info. Then the conformity
        // route, which follows from the trees and the restrictions.
        $articles = new ArticlesCheck($this);
        $articlesState = $articles->state();
        // "To confirm · EN 18031-1, EN 18031-2" (state first, the parts would truncate it), "Questions to answer".
        $inScope = $this->standards() === [] ? __('scoping.none_short') : implode(', ', array_map(fn ($s) => $this->pack->standardName($s), $this->standards()));
        $articlesDetail = match ($articlesState) {
            'unanswered' => __('articles.state_short.unanswered'),
            'confirmed' => $inScope,
            default => __('articles.state_short.'.$articlesState).' · '.$inScope,
        };
        $lists = $this->inventoryGuide()->progress();
        $steps = [
            ['key' => 'description', 'route' => 'versions.edit', 'params' => [], 'done' => filled($version->product->description) && ($version->product->models()->exists() || $version->identifiers()->exists()),
                'detail' => ''],
            // Done once the scoping is answered and confirmed (ArticlesCheck).
            ['key' => 'scoping', 'route' => 'versions.scoping', 'params' => [], 'done' => $articlesState === 'confirmed',
                'detail' => $articlesDetail],
            // Done when every risk is complete and every article in scope has a risk (ArticlesCheck).
            ['key' => 'risks', 'route' => 'versions.risks', 'params' => [],
                'done' => $risks > 0 && $risksOpen === 0 && $articles->mismatches() === [],
                'detail' => trans_choice('workflow.risk_count', $risks, ['count' => $risks])
                    .($risksOpen > 0 ? ' · '.trans_choice('workflow.risk_open', $risksOpen, ['count' => $risksOpen]) : '')],
            // Done when every list of the sequence has objects or is marked "This product has none".
            ['key' => 'inventory', 'route' => 'versions.inventory', 'params' => ['list' => $this->inventoryGuide()->listKeys()[0] ?? array_key_first($this->pack->lists()) ?? ''],
                'done' => $lists['total'] > 0 && $lists['done'] === $lists['total'],
                'detail' => $inventoryDetail],
        ];

        foreach ($this->workingStandards() as $standard) {
            $p = $this->evaluationProgress($standard);
            $steps[] = ['key' => 'evaluate', 'route' => 'versions.evaluate', 'params' => ['standard' => $standard],
                'done' => $p['total'] > 0 && $p['done'] === $p['total'],
                'detail' => $this->pack->standardName($standard).' · '.__('common.progress', ['done' => $p['done'], 'total' => $p['total']])];
        }
        foreach ($this->workingStandards() as $standard) {
            $p = $this->einfoProgress($standard);
            $steps[] = ['key' => 'einfo', 'route' => 'versions.einfo', 'params' => ['standard' => $standard],
                'done' => $p['total'] > 0 && $p['done'] === $p['total'],
                'detail' => $this->pack->standardName($standard).' · '.__('common.progress', ['done' => $p['done'], 'total' => $p['total']])];
        }

        // The assessment units of the standard the manufacturer provides (AssessmentCases): done when
        // every unit with a verdict to give has its result and none is INCONCLUSIVE. Performed outside
        // REDact (an independent test laboratory, units_external): done, "Performed externally".
        if ($version->units_external) {
            $steps[] = ['key' => 'units', 'route' => 'versions.units', 'params' => [], 'done' => true, 'detail' => __('assessment.external_detail')];
        } else {
            $units = AssessmentCases::for($this)->progress();
            $steps[] = ['key' => 'units', 'route' => 'versions.units', 'params' => [],
                'done' => $units['total'] > 0 && $units['recorded'] === $units['total'] && $units['inconclusive'] === 0,
                'detail' => __('common.progress', ['done' => $units['recorded'], 'total' => $units['total']]),
                'done_count' => $units['recorded'], 'total' => $units['total']];
        }

        $conformity = $this->conformity();
        $steps[] = ['key' => 'conformity', 'route' => 'versions.conformity', 'params' => [],
            'done' => $version->conformity_route !== null && ! $conformity->conflict(),
            'detail' => $version->conformity_route?->label() ?? match ($conformity->selfAssessmentPossible()) {
                false => __('conformity.nb_mandatory_short'),
                true => __('conformity.self_possible_short'),
                null => __('conformity.pending_short'),
            }];
        $steps[] = ['key' => 'review', 'route' => 'versions.review', 'params' => [], 'done' => $version->status->value === 'approved',
            'detail' => $version->status->label().(ReviewRights::selfApproved($version) ? ' · '.__('review.self_reviewed') : '')];
        // Last: the EU declaration of conformity and the technical file (App\Declaration), done
        // once the version is approved and the declaration is ready to sign.
        $declaration = new DeclarationData($this);
        $steps[] = ['key' => 'declaration', 'route' => 'versions.declaration', 'params' => [], 'done' => $declaration->complete(),
            'detail' => match (true) {
                $declaration->complete() => __('declaration.state.ready'),
                $version->status->value !== 'approved' => __('declaration.state.after_review'),
                default => __('declaration.state.to_complete'),
            }];

        return $steps;
    }

    /**
     * The standards to work on: those in scope once the scoping is answered,
     * otherwise all of them, so the decision trees never wait for the scoping.
     *
     * @return list<string>
     */
    public function workingStandards(): array
    {
        return $this->version->scopingAnswers()->exists() && $this->standards() !== []
            ? $this->standards()
            : array_keys($this->pack->standards());
    }
}
