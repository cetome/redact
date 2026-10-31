<?php

namespace App\Reports;

use App\Assessment\Assessment;
use App\Assessment\AssessmentCases;
use App\Assessment\ScopingForm;
use App\Assessment\TargetNames;
use App\Content\ContentPack;
use App\Content\Text;
use App\Content\Tree;
use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Models\Evaluation;
use App\Models\EvaluationReview;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Vulnerabilities\SetAsideReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the Word and Excel reports of one version show, read once and
 * already turned into text (labels of the content pack, dates, verdicts).
 *
 * Only the human fields are read: scoping answers, risks, inventory,
 * evaluations, E.Info and reviews as people saved or accepted them. AI
 * suggestions that nobody accepted live elsewhere and never reach a report.
 */
final readonly class ReportData
{
    /** The verdict keys, in the order the reports show them. */
    public const VERDICTS = ['PASS', 'FAIL', 'NOT_APPLICABLE'];

    /** Every evaluation falls in exactly one bucket. */
    public const BUCKETS = ['pass', 'fail', 'na', 'inconclusive', 'missing', 'invalid'];

    /**
     * @param  array<string, mixed>  $product  name, reference, description, organisation, category, models
     * @param  array<string, mixed>  $version  label, title, status, author, completion, content_pack, intended_use, hardware_version, software_version
     * @param  list<array{name: string, role: string, external: bool}>  $team
     * @param  array<string, mixed>  $scoping  sections, articles, standards, restrictions, self_assessment, messages
     * @param  array<string, mixed>  $risks  levels, matrix, entries
     * @param  list<array<string, mixed>>  $inventory  one entry per list
     * @param  list<array<string, mixed>>  $relations  one entry per relation
     * @param  list<array<string, mixed>>  $standards  one entry per standard reported
     * @param  array<string, list<array<string, mixed>>>  $einfo  standard => rows
     * @param  array<string, mixed>  $reviews  overall, counts
     * @param  list<array<string, mixed>>  $cases  assessment cases per standard and requirement (AssessmentCases)
     * @param  bool  $unitsExternal  the assessment units are performed outside REDact (independent test laboratory): not reported
     * @param  array{previous: string, decision: string, justification: string, decided: string, rechecks: int, signs: list<array{kind: string, what: string, detail: string}>}|null  $change  significant change since the earlier version (SignificantChange), null for a first version
     */
    public function __construct(
        public array $product,
        public array $version,
        public array $team,
        public array $scoping,
        public array $risks,
        public array $inventory,
        public array $relations,
        public array $standards,
        public array $einfo,
        public array $reviews,
        public array $cases,
        public string $exportedBy,
        public string $exportedAt,
        public string $exportedOn,
        public bool $unitsExternal = false,
        public ?array $change = null,
    ) {}

    public static function for(ProductVersion $version, User $by): self
    {
        $version->loadMissing('product.organisation', 'product.owner', 'product.models');
        $assessment = Assessment::for($version);
        $pack = $assessment->pack;
        $now = Carbon::now($by->timezone ?: config('app.timezone'))->locale(app()->getLocale());

        $reviewsByEvaluation = EvaluationReview::query()
            ->whereIn('evaluation_id', $version->evaluations()->select('id'))
            ->with('reviewer')
            ->orderBy('created_at')
            ->get()
            ->groupBy('evaluation_id');

        $standards = self::standards($assessment, $reviewsByEvaluation->all());

        return new self(
            product: self::product($version),
            version: self::version($version),
            team: self::team($version),
            scoping: self::scoping($assessment),
            risks: self::risks($version, $pack),
            inventory: self::inventory($assessment),
            relations: self::relations($version, $assessment),
            standards: $standards,
            einfo: self::einfo($assessment, array_column($standards, 'code')),
            reviews: self::reviews($version, $standards),
            cases: $version->units_external ? [] : self::cases($assessment),
            exportedBy: $by->name,
            exportedAt: $now->isoFormat('D MMMM YYYY, HH:mm'),
            exportedOn: $now->isoFormat('D MMMM YYYY'),
            unitsExternal: (bool) $version->units_external,
            change: self::change($assessment),
        );
    }

    /**
     * The significant change since the version this one was made from: the signs, the decision with
     * its justification, who took it, and what is still to re-check (SignificantChange).
     *
     * @return array{previous: string, decision: string, justification: string, decided: string, rechecks: int, signs: list<array{kind: string, what: string, detail: string}>}|null
     */
    private static function change(Assessment $assessment): ?array
    {
        $changes = $assessment->changes();
        if ($changes === null) {
            return null;
        }
        $version = $assessment->version;
        $state = $changes->state();
        $by = (string) $version->changeDecider()->value('name');
        $on = $version->change_decided_at?->locale(app()->getLocale())->isoFormat('D MMMM YYYY') ?? '';

        return [
            'previous' => $changes->previous->label,
            'decision' => $version->change_decision === null
                ? (string) __('changes.state_short.'.$state)
                : __('changes.decisions.'.$version->change_decision).($state === 'outdated' ? ' ('.__('changes.state_short.outdated').')' : ''),
            'justification' => (string) $version->change_justification,
            'decided' => trim($by.($by !== '' && $on !== '' ? ' · ' : '').$on),
            'rechecks' => $changes->significant() ? count($changes->rechecks()) : 0,
            'signs' => array_map(fn (array $sign) => [
                'kind' => (string) __('changes.kind.'.$sign['kind']),
                'what' => $sign['label'],
                'detail' => $sign['detail'] !== '' ? $sign['detail'] : (string) __('changes.detail.'.$sign['kind']),
            ], $changes->signs()),
        ];
    }

    /** "Smart lock · version 2.1", the way CRAted names a product in its documents. */
    public function title(): string
    {
        return $this->product['name'].' · '.__('reports.version_word', ['label' => $this->version['label']]);
    }

    /** @return array<string, int> bucket => count, over every standard */
    public function totals(): array
    {
        $totals = array_fill_keys(self::BUCKETS, 0);
        foreach ($this->standards as $standard) {
            foreach ($standard['counts'] as $bucket => $count) {
                $totals[$bucket] += $count;
            }
        }

        return $totals;
    }

    public static function date(?CarbonInterface $date): string
    {
        return $date ? $date->copy()->locale(app()->getLocale())->isoFormat('D MMMM YYYY') : '';
    }

    /** The bucket of a verdict key: pass, fail or na. */
    public static function bucketOf(string $verdict): string
    {
        return match ($verdict) {
            'PASS' => 'pass',
            'FAIL' => 'fail',
            'INCONCLUSIVE' => 'inconclusive',
            default => 'na',
        };
    }

    // ---- Parts ---------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function product(ProductVersion $version): array
    {
        $product = $version->product;

        return [
            'name' => (string) $product->name,
            'reference' => (string) $product->reference,
            'description' => (string) $product->description,
            'organisation' => (string) $product->organisation->name,
            'category' => (string) $product->category,
            // The product's models, one per line ("SL-100 (EU variant)").
            'models' => $product->models->map(fn ($m) => $m->value.(filled($m->notes) ? ' ('.$m->notes.')' : ''))->implode("\n"),
        ];
    }

    /** @return array<string, mixed> */
    private static function version(ProductVersion $version): array
    {
        return [
            'label' => (string) $version->label,
            // The demo product (App\Guest\DemoProduct) says so wherever the status is printed.
            'status' => $version->status->label().($version->product->is_demo ? ' · '.__('declaration.demo.watermark') : ''),
            'author' => (string) $version->author_name,
            'completion' => self::date($version->completion_date),
            'content_pack' => (string) $version->content_pack,
            'intended_use' => (string) $version->intended_use,
            // The version of the product being assessed (Product step, "Product version").
            'hardware_version' => (string) $version->hardware_version,
            'software_version' => (string) $version->software_version,
        ];
    }

    /** @return list<array{name: string, role: string, external: bool}> */
    private static function team(ProductVersion $version): array
    {
        $product = $version->product;
        $team = [];
        $seen = [];
        $team[] = ['name' => $product->owner->name, 'role' => ProductRole::Owner->label(), 'external' => false];
        $seen[$product->owner->id] = true;
        $members = $product->members()
            ->with('user')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('created_at')
            ->get();
        foreach ($members as $member) {
            if (isset($seen[$member->user_id])) {
                continue;
            }
            $seen[$member->user_id] = true;
            $team[] = ['name' => $member->user->name, 'role' => $member->role->label(), 'external' => (bool) $member->external];
        }

        return $team;
    }

    /** @return array<string, mixed> */
    private static function scoping(Assessment $assessment): array
    {
        $pack = $assessment->pack;
        $answers = $assessment->version->scopingAnswers()->pluck('value', 'question')->all();
        // The Conformity step's own result (saved answers, EN 18031 from the trees, restrictions the
        // data settles): a report never disagrees with the page.
        $conformity = $assessment->conformity();
        $result = $conformity->result();
        $form = new ScopingForm($pack);

        $sections = [];
        foreach ($pack->scoping()['sections'] ?? [] as $section) {
            $groups = [];
            foreach ($section['groups'] ?? [] as $group) {
                $questions = [];
                foreach ($group['questions'] ?? [] as $question) {
                    if (! $form->isVisible($question, $answers, $result)) {
                        continue;
                    }
                    $questions[] = [
                        'key' => $question['key'],
                        'label' => Text::of($question['label'] ?? $question['key']),
                        'answer' => self::answerText($question, $answers[$question['key']] ?? null),
                        'answered' => ($answers[$question['key']] ?? null) !== null,
                    ];
                }
                if ($questions !== []) {
                    $groups[] = ['title' => Text::of($group['title'] ?? ''), 'questions' => $questions];
                }
            }
            if ($groups !== []) {
                $sections[] = ['title' => Text::of($section['title'] ?? ''), 'groups' => $groups];
            }
        }

        $articles = [];
        foreach ($pack->articles() as $code => $name) {
            $articles[] = ['code' => (string) $code, 'name' => $name, 'in_scope' => (bool) ($result->articles[$code] ?? false)];
        }
        // Articles the rules know but the pack does not name
        foreach ($result->articles as $code => $inScope) {
            if (! array_key_exists($code, $pack->articles())) {
                $articles[] = ['code' => (string) $code, 'name' => (string) $code, 'in_scope' => (bool) $inScope];
            }
        }

        $restrictions = [];
        $labels = $pack->scoping()['restriction_labels'] ?? [];
        foreach ($conformity->restrictionStates() as $key => $state) {
            $restrictions[] = [
                'key' => (string) $key,
                'label' => isset($labels[$key]) ? Text::of($labels[$key]) : (string) $key,
                'state' => match ($state) {
                    true => 'met', false => 'not_met', 'na' => 'na', default => 'open'
                },
                'satisfied' => $state === true,
            ];
        }

        return [
            'sections' => $sections,
            'articles' => $articles,
            'standards' => array_map(fn (string $code) => $pack->standardName($code), $assessment->standards()),
            'restrictions' => $restrictions,
            'self_assessment' => match ($result->selfAssessment) {
                true => __('reports.self_assessment.yes'),
                false => __('reports.self_assessment.no'),
                null => __('reports.self_assessment.unknown'),
            },
            'messages' => array_map('strval', $result->messages),
        ];
    }

    /**
     * An answer or an attribute as text, with the labels of its options.
     *
     * @param  array<string, mixed>  $field
     */
    public static function answerText(array $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        $options = [];
        foreach ($field['options'] ?? [] as $option) {
            $options[(string) $option['value']] = Text::of($option['label'] ?? $option['value']);
        }

        return match (true) {
            is_bool($value) => $value ? __('common.yes') : __('common.no'),
            ($field['type'] ?? null) === 'yesno' => match ($value) {
                'yes' => __('common.yes'),
                'no' => __('common.no'),
                'na' => __('reports.not_applicable'),
                default => (string) $value,
            },
            is_array($value) => implode(', ', array_map(fn ($v) => $options[(string) $v] ?? (string) $v, array_filter($value, 'is_scalar'))),
            is_scalar($value) => $options[(string) $value] ?? (string) $value,
            default => '',
        };
    }

    /** @return array<string, mixed> */
    private static function risks(ProductVersion $version, ContentPack $pack): array
    {
        $levels = [];
        foreach ($version->riskLevels()->get() as $level) {
            $levels[$level->rank] = ['rank' => $level->rank, 'name' => $level->name, 'color' => strtoupper(ltrim((string) $level->color, '#'))];
        }
        $cells = [];
        foreach ($version->riskMatrixCells()->get() as $cell) {
            $cells[$cell->impact][$cell->likelihood] = $cell->level_rank;
        }
        $scale = array_map('intval', $pack->risk()['scale'] ?? [1, 2, 3, 4, 5]);
        $impacts = $scale;
        rsort($impacts);

        $stride = $pack->risk()['stride'] ?? [];
        $levelOf = fn (?int $rank) => $rank !== null ? ($levels[$rank] ?? null) : null;

        $entries = [];
        foreach ($version->riskEntries()->get() as $n => $entry) {
            $rank = ($entry->impact && $entry->likelihood) ? ($cells[$entry->impact][$entry->likelihood] ?? null) : null;
            $level = $levelOf($rank);
            $residual = $levelOf($entry->residual_rank);
            $entries[] = [
                'number' => $n + 1,
                'threat' => (string) $entry->threat,
                'description' => (string) $entry->description,
                'stride' => array_map(fn ($s) => Text::of($stride[$s] ?? $s), (array) $entry->stride),
                'stride_letters' => implode('', (array) $entry->stride),
                'risk' => (string) $entry->risk,
                'impact' => $entry->impact,
                'likelihood' => $entry->likelihood,
                'score' => $entry->score(),
                'level' => $level,
                'articles' => array_map('strval', (array) $entry->articles),
                'requirements' => array_map('strval', (array) $entry->requirements),
                'remediation' => (string) $entry->remediation,
                'implementation' => (string) $entry->implementation,
                'residual' => $residual,
                // Rank 1 is the most severe: a residual rank below the initial one is worse
                'residual_worse' => $level !== null && $residual !== null && $residual['rank'] < $level['rank'],
            ];
        }

        return [
            'levels' => array_values($levels),
            'matrix' => ['impacts' => $impacts, 'likelihoods' => $scale, 'cells' => $cells, 'levels' => $levels],
            'entries' => $entries,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function inventory(Assessment $assessment): array
    {
        $lists = [];
        foreach ($assessment->pack->lists() as $key => $list) {
            $fields = [];
            foreach ($list['fields'] ?? [] as $field) {
                $fields[] = ['key' => $field['key'], 'label' => Text::of($field['label'] ?? $field['key'])];
            }
            $items = [];
            foreach ($assessment->context()->items($key) as $item) {
                $values = [];
                foreach ($list['fields'] ?? [] as $field) {
                    $values[$field['key']] = self::answerText($field, $item->attr($field['key']));
                }
                // A group is one object: its members are listed under its name.
                $members = $item->memberNames();
                $items[] = [
                    'code' => (string) $item->code,
                    'name' => (string) $item->name.($members === [] ? ''
                        : "\n".__('inventory.group_of', ['count' => count($members)]).': '.implode(', ', $members)),
                    'members' => $members,
                    'description' => (string) $item->description,
                    'values' => $values,
                ];
            }
            // Vulnerabilities set aside, each with its justification (App\Vulnerabilities\SetAsideReport).
            $items = array_merge($items, SetAsideReport::items($assessment->version, (string) $key, $list));
            $lists[] = [
                'key' => (string) $key,
                'label' => Text::of($list['label'] ?? $key),
                'fields' => $fields,
                'items' => $items,
            ];
        }

        return $lists;
    }

    /** @return list<array<string, mixed>> */
    private static function relations(ProductVersion $version, Assessment $assessment): array
    {
        $pack = $assessment->pack;
        $context = $assessment->context();
        $links = $version->inventoryLinks()->orderBy('id')->get()->groupBy('relation');
        $relations = [];
        foreach ($pack->relations() as $key => $relation) {
            $pairs = [];
            foreach ($links->get($key, collect()) as $link) {
                $from = $context->item($link->from_item_id);
                $to = $context->item($link->to_item_id);
                if ($from && $to) {
                    $pairs[] = ['from' => $from->label(), 'to' => $to->label()];
                }
            }
            $relations[] = [
                'key' => (string) $key,
                'label' => Text::of($relation['label'] ?? $key),
                'from' => Text::of($pack->lists()[$relation['from']]['label'] ?? $relation['from']),
                'to' => Text::of($pack->lists()[$relation['to']]['label'] ?? $relation['to']),
                'pairs' => $pairs,
            ];
        }

        return $relations;
    }

    /**
     * The standards in scope (and any other standard that already has evaluations), in pack order.
     *
     * @param  array<string, Collection<int, EvaluationReview>>  $reviews
     * @return list<array<string, mixed>>
     */
    private static function standards(Assessment $assessment, array $reviews): array
    {
        $pack = $assessment->pack;
        $evaluated = array_unique(array_map(fn (Evaluation $e) => $e->standard, $assessment->context()->allEvaluations()));
        $codes = array_values(array_filter(
            array_keys($pack->standards()),
            fn (string $code) => $assessment->hasStandard($code) || in_array($code, $evaluated, true),
        ));

        $standards = [];
        $names = new TargetNames($pack);
        foreach ($codes as $code) {
            $requirements = [];
            $counts = array_fill_keys(self::BUCKETS, 0);
            $follows = 0;
            $families = [];
            foreach ($pack->requirementsFor($code) as $requirementCode => $definition) {
                $targets = $assessment->targets($code, $requirementCode);
                // Walks settled by another walk (e.g. SCM-2 where SCM-1 ends FAIL): listed, not walked,
                // counted once apart ("follows"), never in the buckets.
                $followRows = array_map(fn (array $f) => [
                    'object' => $f['item']?->label() ?? __('reports.product_target'),
                    'source' => $f['requirement'],
                    'state' => $f['state'],
                ], $assessment->followsFrom($code, (string) $requirementCode));
                if ($targets === [] && $followRows === []) {
                    continue;
                }
                $follows += count(array_filter($followRows, fn (array $f) => $f['state'] === 'fail'));
                $tree = $pack->tree($code, $requirementCode);
                $family = (string) ($definition['family'] ?? '');
                $families[$family] ??= [
                    'code' => $family,
                    'name' => Text::of($pack->families()[$family]['name'] ?? $family),
                    'counts' => array_fill_keys(self::BUCKETS, 0),
                ];

                $rows = [];
                foreach ($targets as $item) {
                    $evaluation = $assessment->evaluation($code, $requirementCode, $item);
                    $row = self::evaluationRow($assessment, $tree, $requirementCode, $item, $evaluation, $reviews);
                    $rows[] = $row;
                    $counts[$row['bucket']]++;
                    $families[$family]['counts'][$row['bucket']]++;
                }

                $requirements[] = [
                    'code' => (string) $requirementCode,
                    'title' => $tree?->title() ?: Text::of($definition['title'] ?? $requirementCode),
                    'clause' => (string) ($tree?->clause() ?? ''),
                    'family' => $family,
                    'family_name' => $families[$family]['name'],
                    // What the tree is walked for, named as in the pack: "Assets", "The product"...
                    'targets_label' => $names->heading($definition, $targets),
                    'rows' => $rows,
                    'follows' => $followRows,
                    'summary' => self::treeSummary($tree, $requirementCode, $rows),
                ];
            }

            $standards[] = [
                'code' => $code,
                'name' => $pack->standardName($code),
                'in_scope' => $assessment->hasStandard($code),
                'requirements' => $requirements,
                'counts' => $counts,
                'follows' => $follows,
                'families' => array_values($families),
            ];
        }

        return $standards;
    }

    /**
     * @param  array<string, Collection<int, EvaluationReview>>  $reviews
     * @return array<string, mixed>
     */
    private static function evaluationRow(Assessment $assessment, ?Tree $tree, string $requirement, ?InventoryItem $item, ?Evaluation $evaluation, array $reviews): array
    {
        $status = $assessment->status($evaluation, $tree);
        $verdict = $evaluation?->verdict;
        $node = $evaluation?->decision_node;
        // INCONCLUSIVE is no outcome of the tree: neither valid nor invalid.
        $inconclusive = $verdict === Verdict::Inconclusive;
        $valid = $node && $verdict && $tree && ! $inconclusive ? $tree->allows($node, $verdict) : null;
        $bucket = match (true) {
            $inconclusive => 'inconclusive',
            $status === 'invalid' || $valid === false => 'invalid',
            $verdict === null || ! $node => 'missing',
            default => self::bucketOf($verdict->value),
        };

        $rowReviews = [];
        if ($evaluation) {
            $fingerprint = $evaluation->fingerprint();
            foreach ($reviews[$evaluation->id] ?? [] as $review) {
                $rowReviews[] = [
                    'reviewer' => (string) $review->reviewer->name.($review->self_review ? ' · '.__('review.self_reviewed') : ''),
                    'verdict' => (string) $review->verdict,
                    'label' => __('reports.review_verdicts.'.$review->verdict),
                    'comment' => (string) $review->comment,
                    'stale' => $review->fingerprint !== $fingerprint,
                    'date' => self::date($review->updated_at),
                ];
            }
        }

        // Walked: is the verdict justified the way the standard asks (Justification)?
        $check = $evaluation && $node && $verdict && ! $inconclusive ? $assessment->justification()->check($evaluation) : null;
        $justified = $check?->justified;

        return [
            'object' => $item?->label() ?? __('reports.product_target'),
            'list' => $item?->list,
            'justified' => $justified,
            'to_check' => $check !== null && $check->toCheck() > 0,
            'justified_label' => $justified === null ? '' : ($justified ? ($check->toCheck() > 0 ? __('assessment.report.justified_to_check') : __('assessment.report.justified')) : __('assessment.report.not_justified')),
            'node' => $node,
            'reference' => $evaluation?->nodeReference() ?? '',
            'verdict' => $verdict?->value,
            'verdict_label' => $verdict?->label() ?? '',
            'justification' => (string) $evaluation?->justification,
            'valid' => $valid,
            'status' => $status,
            'bucket' => $bucket,
            'reviews' => $rowReviews,
        ];
    }

    /**
     * The "Summary of Decision Trees": one row per decision node, the objects whose walk
     * ended there under the verdict they reached, and which verdicts the node can lead to.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{node: string, reference: string, cells: array<string, array{allowed: bool, objects: list<string>}>}>
     */
    private static function treeSummary(?Tree $tree, string $requirement, array $rows): array
    {
        if (! $tree) {
            return [];
        }
        $summary = [];
        foreach ($tree->decisionNodes() as $node) {
            $cells = [];
            foreach (self::VERDICTS as $verdict) {
                $objects = [];
                foreach ($rows as $row) {
                    if ($row['node'] === $node && $row['verdict'] === $verdict) {
                        $objects[] = $row['object'];
                    }
                }
                $cells[$verdict] = ['allowed' => $tree->allows($node, Verdict::from($verdict)), 'objects' => $objects];
            }
            $summary[] = ['node' => $node, 'reference' => 'DT.'.$requirement.'.'.$node, 'cells' => $cells];
        }

        return $summary;
    }

    /**
     * @param  list<string>  $standards
     * @return array<string, list<array<string, mixed>>>
     */
    private static function einfo(Assessment $assessment, array $standards): array
    {
        $catalogue = $assessment->pack->einfo();
        $texts = [];
        foreach ($assessment->version->einfoEntries()->get() as $entry) {
            $texts[$entry->einfo.'|'.($entry->inventory_item_id ?? '-')] = trim((string) $entry->text);
        }

        $out = [];
        foreach ($standards as $standard) {
            $rows = [];
            foreach ($assessment->requiredEinfo($standard) as $required) {
                $item = $required['item'];
                // Conditional items appear once documented (their condition holds); never as missing.
                $documented = array_filter($required['conditional'], fn (string $code) => ($texts[$code.'|'.($item->id ?? '-')] ?? '') !== '');
                foreach ([...$required['codes'], ...$documented] as $code) {
                    $text = $texts[$code.'|'.($item->id ?? '-')] ?? '';
                    $rows[] = [
                        'object' => $item?->label() ?? __('reports.product_target'),
                        'code' => (string) $code,
                        'label' => Text::of($catalogue[$code]['label'] ?? ''),
                        'requirement' => (string) ($catalogue[$code]['requirement'] ?? ''),
                        'text' => $text,
                        'missing' => $text === '',
                    ];
                }
            }
            $out[$standard] = $rows;
        }

        return $out;
    }

    /**
     * The assessment units provided in their step, per standard and requirement (informative
     * cases of the standard, which have no verdict, are left out).
     *
     * @return list<array{code: string, name: string, requirements: list<array{code: string, title: string, cases: list<array<string, mixed>>}>}>
     */
    private static function cases(Assessment $assessment): array
    {
        $out = [];
        foreach (AssessmentCases::for($assessment)->overview()['standards'] as $standard) {
            $requirements = [];
            foreach ($standard['requirements'] as $requirement) {
                $cases = [];
                foreach ($requirement['cases'] as $case) {
                    if ($case['informative']) {
                        continue;
                    }
                    $result = $case['result'];
                    $cases[] = [
                        'key' => $case['key'],
                        'label' => $case['label'],
                        'clause' => $case['clause'],
                        'verdict' => $result?->verdict->value,
                        'verdict_label' => $result?->verdict->label() ?? '',
                        'evidence' => (string) $result?->evidence,
                        'document' => (string) $result?->document?->original_name,
                        'recorded_by' => (string) $result?->recorder?->name,
                        'date' => self::date($result?->updated_at),
                        'independent' => $result?->independent,
                        'stale' => $case['stale'],
                    ];
                }
                if ($cases !== []) {
                    $requirements[] = ['code' => $requirement['code'], 'title' => $requirement['title'], 'cases' => $cases];
                }
            }
            if ($requirements !== []) {
                $out[] = ['code' => $standard['code'], 'name' => $standard['name'], 'requirements' => $requirements];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $standards
     * @return array<string, mixed>
     */
    private static function reviews(ProductVersion $version, array $standards): array
    {
        $overall = [];
        foreach ($version->reviews()->with('reviewer')->orderBy('created_at')->get() as $review) {
            $overall[] = [
                'reviewer' => (string) $review->reviewer->name.($review->self_review ? ' · '.__('review.self_reviewed') : ''),
                'verdict' => (string) $review->verdict,
                'label' => __('reports.version_verdicts.'.$review->verdict),
                'comment' => (string) $review->comment,
                'date' => self::date($review->updated_at),
            ];
        }

        $counts = ['agree' => 0, 'disagree' => 0, 'question' => 0, 'none' => 0, 'stale' => 0];
        foreach ($standards as $standard) {
            foreach ($standard['requirements'] as $requirement) {
                foreach ($requirement['rows'] as $row) {
                    if ($row['reviews'] === []) {
                        $counts['none']++;
                    }
                    foreach ($row['reviews'] as $review) {
                        if (isset($counts[$review['verdict']])) {
                            $counts[$review['verdict']]++;
                        }
                        if ($review['stale']) {
                            $counts['stale']++;
                        }
                    }
                }
            }
        }

        return ['overall' => $overall, 'counts' => $counts];
    }
}
