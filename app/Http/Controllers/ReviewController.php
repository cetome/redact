<?php

namespace App\Http\Controllers;

use App\Assessment\Assessment;
use App\Assessment\AssessmentCases;
use App\Assessment\ReviewDiff;
use App\Assessment\ReviewRights;
use App\Content\Text;
use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Enums\VersionStatus;
use App\Models\AssessmentCaseResult;
use App\Models\AssessmentCaseReview;
use App\Models\Document;
use App\Models\Evaluation;
use App\Models\EvaluationReview;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\VersionReview;
use App\Sbom\SbomFeature;
use App\Sbom\Vex\VexStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Human review = view and validate, never edit (owner, 2026-10-23). Reviewers read each evaluation
 * in a read-only evidence panel (the path through the decision tree, the verdict, the justification,
 * the E.Info, the documents, VEX) and agree, disagree or ask a question; they validate each
 * assessment unit result the manufacturer provided the same way; then they give the final decision.
 * Without a reviewer on the product, its owner and the organisation's admins review it themselves
 * (self-review, ReviewRights). Reviews are people's only, never the AI's.
 */
class ReviewController extends Controller
{
    /** The Review page with the read-only evidence panel of one evaluation open (/review/evaluations/{evaluation}). */
    public function evidencePanel(Request $request, ProductVersion $version, Evaluation $evaluation, ReviewDiff $diff): View
    {
        abort_unless($evaluation->product_version_id === $version->id, 404);

        return $this->index($request, $version, $diff, null, $evaluation);
    }

    public function index(Request $request, ProductVersion $version, ReviewDiff $diff, ?string $view = null, ?Evaluation $evaluation = null): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canView(), 404);
        abort_unless($evaluation === null || $evaluation->product_version_id === $version->id, 404);
        $assessment = Assessment::for($version);
        $rights = ReviewRights::for($request->user(), $version, $access);

        $evaluations = $version->evaluations()
            ->with(['item', 'updater', 'reviews.reviewer'])
            ->orderBy('standard')->orderBy('requirement')
            ->get();

        // Two views of the step (/review and /review/units): the evaluations, and the assessment
        // units the manufacturer provided. /review/evaluations/{id} is the first with one evidence panel open.
        $view = $view === 'units' ? 'units' : 'evaluations';
        $open = $evaluation ? $evaluations->firstWhere('id', $evaluation->id) : null;

        return view('review.index', [
            'view' => $view,
            'cases' => AssessmentCases::for($assessment)->overview(),
            'version' => $version,
            'access' => $access,
            'assessment' => $assessment,
            'evaluations' => $evaluations->groupBy('standard'),
            // What changed since each stale review (keyed by review id).
            'changes' => $diff->forEvaluations($evaluations),
            'decisions' => $version->reviews()->with('reviewer')->get(),
            'reviewers' => $version->product->members()->where('role', ProductRole::Reviewer)->with('user')->get(),
            'rights' => $rights,
            'counts' => ReviewRights::counts($version->product),
            'canReview' => $rights->canReview() && $version->status === VersionStatus::InReview,
            'open' => $open,
            'evidence' => $open ? $this->evidence($assessment, $open) : null,
            'me' => $request->user(),
        ]);
    }

    public function reviewEvaluation(Request $request, ProductVersion $version, Evaluation $evaluation): RedirectResponse
    {
        $this->allow('review', $version);
        abort_unless($evaluation->product_version_id === $version->id && $version->status === VersionStatus::InReview, 404);

        $data = $this->opinion($request);
        EvaluationReview::updateOrCreate(
            ['evaluation_id' => $evaluation->id, 'reviewer_id' => $request->user()->id],
            $data + [
                'fingerprint' => $evaluation->fingerprint(),
                'snapshot' => $evaluation->reviewSnapshot(),
                'self_review' => ReviewRights::for($request->user(), $version)->isSelf(),
            ],
        );

        return redirect()->to(route('versions.review', $version).'#evaluation-'.$evaluation->id)
            ->with('status', __('review.saved'));
    }

    /** Agree, disagree or question one assessment unit result the manufacturer provided. */
    public function reviewUnit(Request $request, ProductVersion $version, AssessmentCaseResult $result): RedirectResponse
    {
        $this->allow('review', $version);
        abort_unless($result->product_version_id === $version->id && $version->status === VersionStatus::InReview, 404);

        $anchor = AssessmentCases::anchor($result->standard, $result->requirement, $result->case_key);
        $data = $this->opinion($request, $anchor);
        AssessmentCaseReview::updateOrCreate(
            ['assessment_case_result_id' => $result->id, 'reviewer_id' => $request->user()->id],
            $data + [
                'fingerprint' => $result->fingerprint(),
                'snapshot' => $result->reviewSnapshot(),
                'self_review' => ReviewRights::for($request->user(), $version)->isSelf(),
            ],
        );

        return redirect()->to(route('versions.review.units', $version).'#'.$anchor)
            ->with('status', __('review.saved'));
    }

    public function decide(Request $request, ProductVersion $version): RedirectResponse
    {
        $this->allow('review', $version);
        abort_unless($version->status === VersionStatus::InReview, 404);

        $data = $request->validate([
            'verdict' => ['required', Rule::in(VersionReview::VERDICTS)],
            'comment' => ['nullable', 'string', 'max:5000', Rule::requiredIf(fn () => $request->input('verdict') === 'changes_requested')],
        ]);

        if ($data['verdict'] === 'approved' && ($refusal = $this->approvalRefusal($version)) !== null) {
            return $refusal;
        }

        $self = ReviewRights::for($request->user(), $version)->isSelf();
        DB::transaction(function () use ($version, $data, $request, $self) {
            VersionReview::updateOrCreate(
                ['product_version_id' => $version->id, 'reviewer_id' => $request->user()->id],
                $data + ['self_review' => $self],
            );

            if ($data['verdict'] === 'changes_requested') {
                $version->statusReason = 'changes_requested';
                $version->forceFill(['status' => VersionStatus::Draft, 'submitted_at' => null])->save();

                return;
            }

            // Approved once every current reviewer has approved; without a reviewer, once self-reviewed.
            $reviewerIds = ReviewRights::reviewerIds($version->product);
            $approved = $reviewerIds->isEmpty()
                ? $self
                : $version->reviews()->where('verdict', 'approved')->where('self_review', false)->whereIn('reviewer_id', $reviewerIds)->count() === $reviewerIds->count();
            if ($approved) {
                $version->statusReason = 'approved';
                $version->forceFill(['status' => VersionStatus::Approved, 'approved_at' => now()])->save();
            }
        });

        return back()->with('status', __('review.decision_saved'));
    }

    /**
     * Why the version cannot be approved yet, as the refusal to send back, or null: an evaluation not
     * justified, an INCONCLUSIVE evaluation or unit, a unit not provided, a disagreement or a question
     * still open (on an evaluation or a unit, among the reviews that count; no unit outside REDact). Each refusal names the
     * first items and links to the first one on the Review page, never to an editor.
     */
    private function approvalRefusal(ProductVersion $version): ?RedirectResponse
    {
        $assessment = Assessment::for($version);
        $cases = AssessmentCases::for($assessment);
        $evaluationLink = fn (Evaluation $e): string => route('versions.review.evidence', [$version, $e]);
        $unitItem = fn (array $unit): array => [
            'label' => $unit['requirement'].' · '.AssessmentCases::label($unit['case']),
            'href' => route('versions.review.units', $version).'#'.AssessmentCases::anchor($unit['standard'], $unit['requirement'], $unit['case']),
        ];
        $evaluationItem = fn (string $requirement, ?InventoryItem $item, Evaluation $e): array => [
            'label' => $requirement.' · '.($item?->label() ?? __('evaluations.product')),
            'href' => $evaluationLink($e),
        ];
        $refuse = fn (string $key, array $items): RedirectResponse => back()
            ->with('error', trans_choice($key, count($items), ['count' => count($items), 'list' => VersionController::listOf($items)]))
            ->with('error_link', ['href' => $items[0]['href'], 'label' => __('assessment.approve_first')]);

        $unjustified = array_map(fn (array $row) => $evaluationItem($row['requirement'], $row['item'], $row['evaluation']), $assessment->unjustified());
        if ($unjustified !== []) {
            return $refuse('assessment.approve_refused_unjustified', $unjustified);
        }

        $inconclusive = array_map(fn (array $row) => $evaluationItem($row['requirement'], $row['item'], $row['evaluation']), $assessment->inconclusive());
        $missing = [];
        // Outside REDact (an independent test laboratory): approval does not look at the units.
        $units = $cases->external() ? [] : $cases->units();
        foreach ($units as $unit) {
            if ($unit['result'] === null) {
                $missing[] = $unitItem($unit);
            } elseif ($unit['result']->verdict === Verdict::Inconclusive) {
                $inconclusive[] = $unitItem($unit);
            }
        }
        if ($inconclusive !== []) {
            return $refuse('assessment.approve_refused', $inconclusive);
        }
        if ($missing !== []) {
            return $refuse('assessment.approve_refused_missing', $missing);
        }

        // Disagreements and questions still open, among the reviews that count (ReviewRights::counts).
        $counts = ReviewRights::counts($version->product);
        $open = [];
        foreach ($version->evaluations()->with(['item', 'reviews'])->orderBy('standard')->orderBy('requirement')->get() as $evaluation) {
            if ($evaluation->reviews->contains(fn (EvaluationReview $r) => $counts($r) && $r->verdict !== 'agree')) {
                $open[] = $evaluationItem($evaluation->requirement, $evaluation->item, $evaluation);
            }
        }
        foreach ($units as $unit) {
            if ($unit['result']?->reviews->contains(fn (AssessmentCaseReview $r) => $counts($r) && $r->verdict !== 'agree')) {
                $open[] = $unitItem($unit);
            }
        }
        if ($open !== []) {
            return $refuse('assessment.approve_refused_open', $open);
        }

        return null;
    }

    /**
     * Agree, disagree or question, with a comment required unless agreeing.
     *
     * @return array{verdict: string, comment: string|null}
     */
    private function opinion(Request $request, ?string $bag = null): array
    {
        $rules = [
            'verdict' => ['required', Rule::in(EvaluationReview::VERDICTS)],
            'comment' => ['nullable', 'string', 'max:5000', Rule::requiredIf(fn () => $request->input('verdict') !== 'agree')],
        ];
        $data = $bag === null ? $request->validate($rules) : $request->validateWithBag($bag, $rules);

        return ['verdict' => (string) $data['verdict'], 'comment' => $data['comment'] ?? null];
    }

    /**
     * Everything the read-only evidence panel of one evaluation shows: the path through the tree
     * (each question and the answer given), the E.Info of that requirement for that entry, the
     * documents attached to the requirement's assessment units, and the VEX statements of a known
     * vulnerability. Read-only: nothing here links to an editor.
     *
     * @return array<string, mixed>
     */
    private function evidence(Assessment $assessment, Evaluation $evaluation): array
    {
        $pack = $assessment->pack;
        $version = $assessment->version;
        $tree = $pack->tree($evaluation->standard, $evaluation->requirement);
        $steps = [];
        foreach ((array) $evaluation->path as $step) {
            $node = $tree && $tree->has((string) $step['node']) ? $tree->node((string) $step['node']) : null;
            $steps[] = [
                'ref' => 'DT.'.$evaluation->requirement.'.'.$step['node'],
                'question' => $node ? Text::of($node['text'] ?? '') : '',
                'answer' => ucfirst(mb_strtolower((string) $step['answer'])),
            ];
        }

        // The E.Info the rules place on this entry for this requirement (conditional ones once written).
        $catalogue = $pack->einfo();
        $texts = $version->einfoEntries()->where('inventory_item_id', $evaluation->inventory_item_id)->pluck('text', 'einfo');
        $einfo = [];
        foreach ($assessment->requiredEinfo($evaluation->standard) as $row) {
            if (($row['item']->id ?? null) !== $evaluation->inventory_item_id) {
                continue;
            }
            foreach ([[$row['codes'], false], [$row['conditional'], true]] as [$codes, $conditional]) {
                foreach ($codes as $code) {
                    $text = trim((string) ($texts[$code] ?? ''));
                    if (($catalogue[$code]['requirement'] ?? null) !== $evaluation->requirement || ($conditional && $text === '')) {
                        continue;
                    }
                    $einfo[] = ['code' => $code, 'label' => Text::of($catalogue[$code]['label'] ?? ''), 'text' => $text];
                }
            }
        }

        $documentIds = $version->caseResults()->where('standard', $evaluation->standard)
            ->where('requirement', $evaluation->requirement)->whereNotNull('document_id')->pluck('document_id')->unique()->values();
        $item = $evaluation->item;

        return [
            'steps' => $steps,
            'einfo' => $einfo,
            'check' => $assessment->justification()->check($evaluation),
            'status' => $assessment->status($evaluation, $tree),
            'documents' => $documentIds->isEmpty() ? collect() : Document::query()->whereIn('id', $documentIds)->orderBy('original_name')->get(['id', 'original_name']),
            'vex' => $item && SbomFeature::isVulnerabilityList($item->list) ? VexStatus::forVulnerability($version->id, $item->id) : collect(),
        ];
    }
}
