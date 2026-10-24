<?php

namespace App\Http\Controllers;

use App\Assessment\Assessment;
use App\Assessment\AssessmentCases;
use App\Enums\Verdict;
use App\Enums\VersionStatus;
use App\Models\AssessmentCaseResult;
use App\Models\ProductVersion;
use App\Support\ProductAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The "Assessment units" step: the manufacturer provides the result of every assessment unit of
 * the standard (conceptual, functional completeness, functional sufficiency, per requirement), each
 * with its evidence, on a draft or while the version is in review (not sequential: approval waits for
 * them). Or they are performed outside REDact (an independent test laboratory, units_external).
 * Reviewers validate them in the Review step (ReviewController::reviewUnit); they never record them.
 */
class AssessmentUnitController extends Controller
{
    public function index(Request $request, ProductVersion $version): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canView(), 404);
        $assessment = Assessment::for($version);
        $external = (bool) $version->units_external;
        // Outside REDact the forms are hidden: the results recorded before stay, read-only.
        $canRecord = ! $external && self::canRecord($access, $version);
        $cases = AssessmentCases::for($assessment)->overview();

        return view('units.index', [
            'version' => $version,
            'access' => $access,
            'assessment' => $assessment,
            'cases' => $external ? self::recordedOnly($cases) : $cases,
            'external' => $external,
            // In REDact or outside REDact: the editors, on a draft or in review (never the demo, never an assessor).
            'canChoose' => self::canChoose($access, $version),
            'canRecord' => $canRecord,
            // An assessor picks an existing document only (Documents is the editors').
            'canUpload' => self::canChoose($access, $version),
            // "Invite an external assessor": the people who manage the team (never a guest or the demo).
            'canInvite' => ! $external && self::canChoose($access, $version) && $access->canManage() && ! $request->user()->is_guest,
            'documents' => $canRecord ? $version->documents()->orderBy('original_name')->get(['id', 'original_name']) : collect(),
            'me' => $request->user(),
        ]);
    }

    /**
     * Records the result of one assessment unit, always with its evidence (owners, contributors,
     * organisation admins and the product's assessors, on a draft or in review: canRecord). Marked
     * "Independent" when an assessor records it (or a reviewer, data from before reviewers only
     * validated), "Not independent" otherwise.
     */
    public function record(Request $request, ProductVersion $version): RedirectResponse
    {
        $access = $this->access($version->product);
        abort_unless($access->canView() && self::canRecord($access, $version) && ! $version->units_external, 404);
        $assessment = Assessment::for($version);
        $cases = AssessmentCases::for($assessment);

        $data = $request->validate([
            'standard' => ['required', 'string', Rule::in($assessment->workingStandards())],
            'requirement' => ['required', 'string', 'max:40'],
            'case' => ['required', 'string', 'max:40'],
        ]);
        abort_unless(array_key_exists($data['requirement'], $assessment->applicableRequirements($data['standard'])), 404);
        // Only a case of the pack's criteria, and one with a verdict to give.
        $case = collect($cases->definitions($data['standard'], $data['requirement']))->firstWhere('key', $data['case']);
        abort_unless($case !== null && ! AssessmentCases::informative($case), 404);

        $anchor = AssessmentCases::anchor($data['standard'], $data['requirement'], $data['case']);
        $fields = $request->validateWithBag($anchor, [
            'verdict' => ['required', Rule::in(array_map(fn (Verdict $v) => $v->value, AssessmentCases::verdictsOf($case)))],
            // A verdict never goes without its justification.
            'evidence' => ['required', 'string', 'min:20', 'max:20000'],
            'document' => ['nullable', 'string', Rule::in($version->documents()->pluck('id')->all())],
        ], [
            'evidence.required' => __('assessment.evidence_required'),
            'evidence.min' => __('assessment.evidence_min', ['min' => 20]),
        ]);

        $independent = $cases->independent($request->user(), $access, $data['standard'], $data['requirement']);

        DB::transaction(fn () => AssessmentCaseResult::updateOrCreate(
            ['product_version_id' => $version->id, 'standard' => $data['standard'], 'requirement' => $data['requirement'], 'case_key' => $data['case']],
            [
                'verdict' => Verdict::from($fields['verdict']),
                'evidence' => trim($fields['evidence']),
                'document_id' => $fields['document'] ?? null,
                'recorded_by' => $request->user()->id,
                'independent' => $independent,
            ],
        ));

        return redirect()->to(route('versions.units', $version).'#'.$anchor)
            ->with('status', __('assessment.saved'));
    }

    /**
     * Where the assessment units are performed: in REDact (the team records each result here) or
     * outside REDact (an independent test laboratory performs them, its report attached in
     * Documents; whatever the conformity route). The people who provide the units, on a draft or
     * in review (canRecord). Results already recorded are kept either way.
     */
    public function where(Request $request, ProductVersion $version): RedirectResponse
    {
        $access = $this->access($version->product);
        abort_unless($access->canView() && self::canChoose($access, $version), 404);
        $data = $request->validate(['where' => ['required', Rule::in(['redact', 'external'])]]);

        $version->forceFill(['units_external' => $data['where'] === 'external'])->save();

        return back()->with('status', __('assessment.where.saved_'.$data['where']));
    }

    /**
     * The overview with only the units that have a result (outside REDact: what was recorded before
     * stays visible), the figures counting those.
     *
     * @param  array{standards: list<array<string, mixed>>, counts: array<string, int>}  $overview
     * @return array{standards: list<array<string, mixed>>, counts: array<string, int>}
     */
    public static function recordedOnly(array $overview): array
    {
        $standards = [];
        foreach ($overview['standards'] as $standard) {
            $requirements = [];
            foreach ($standard['requirements'] as $requirement) {
                $requirement['cases'] = array_values(array_filter($requirement['cases'], fn (array $case) => ! $case['informative'] && $case['result'] !== null));
                if ($requirement['cases'] !== []) {
                    $requirements[] = $requirement;
                }
            }
            if ($requirements !== []) {
                $standards[] = ['requirements' => $requirements] + $standard;
            }
        }
        $counts = $overview['counts'];
        $counts['total'] = $counts['recorded'];

        return ['standards' => $standards, 'counts' => $counts];
    }

    /**
     * Owners, contributors, organisation admins and the product's assessors
     * (ProductAccess::canRecordUnits), on a draft OR IN REVIEW. Never reviewers, never staff, never
     * the demo or a product the subscription no longer covers.
     *
     * Lock exception (owner, 2026-10-23: "things are not sequential"): a version in review is locked
     * for everything else, but its assessment units can still be provided (and their place chosen),
     * so reviewers validate the evaluations meanwhile; a new or changed result shows in Review's
     * units tab to validate (a changed one makes its reviews stale). Approval waits for them (in REDact).
     */
    public static function canRecord(ProductAccess $access, ProductVersion $version): bool
    {
        return $access->canRecordUnits($version);
    }

    /** Where the units are performed: the editors only (an assessor records, never chooses). */
    public static function canChoose(ProductAccess $access, ProductVersion $version): bool
    {
        return $access->canEdit() && in_array($version->status, [VersionStatus::Draft, VersionStatus::InReview], true);
    }
}
