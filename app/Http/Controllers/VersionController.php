<?php

namespace App\Http\Controllers;

use App\Assessment\Assessment;
use App\Assessment\AssessmentCases;
use App\Assessment\Conformity;
use App\Assessment\VersionFactory;
use App\Enums\ProductRole;
use App\Enums\VersionStatus;
use App\Mail\VersionSubmitted;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Support\Links;
use App\Support\ProductModels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VersionController extends Controller
{
    public function store(Request $request, Product $product, VersionFactory $factory): RedirectResponse
    {
        $this->allow('manage', $product);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:40', Rule::unique('product_versions', 'label')->where('product_id', $product->id)],
            'from' => ['nullable', 'string', Rule::in($product->versions()->pluck('id'))],
        ]);

        $from = isset($data['from']) ? ProductVersion::find($data['from']) : null;
        $version = $factory->create($product, $data['label'], $request->user(), $from);

        return redirect()->route('versions.show', $version)->with('status', __('versions.created', ['label' => $version->label]));
    }

    public function show(ProductVersion $version): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canView(), 404);

        $assessment = Assessment::for($version);
        // The Overview is also the product page: the product card, its versions and its team.
        $version->product->load(['organisation', 'owner', 'models', 'versions.previousVersion', 'members.user']);

        return view('versions.show', [
            'version' => $version,
            'access' => $access,
            'assessment' => $assessment,
            'steps' => $assessment->steps(),
        ]);
    }

    public function edit(ProductVersion $version): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canView(), 404);

        return view('versions.edit', ['version' => $version, 'access' => $access]);
    }

    public function update(Request $request, ProductVersion $version): RedirectResponse
    {
        $this->allow('edit', $version);

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:10000'],
            'intended_use' => ['nullable', 'string', 'max:10000'],
            'author_name' => ['nullable', 'string', 'max:120'],
            'completion_date' => ['nullable', 'date'],
            ...ProductModels::rules(),
        ]);

        $version->product->update(['description' => $data['description'] ?? null]);
        if ($request->has('models_sent')) {
            ProductModels::sync($version->product, $data['models'] ?? []);
        }
        if ($request->hasAny(['hardware_version', 'software_version'])) {
            ProductModels::saveVersionNumbers($version, $data);
        }
        $version->update([
            'intended_use' => $data['intended_use'] ?? null,
            'author_name' => $data['author_name'] ?? null,
            'completion_date' => $data['completion_date'] ?? null,
        ]);

        // "Save and continue": on to the risk assessment (the step after the product).
        if ($request->boolean('continue') && ($next = Assessment::for($version)->nextStepAfter('description'))) {
            return redirect()->to($next['href'])->with('status', __('common.saved'));
        }

        return back()->with('status', __('common.saved'));
    }

    public function submit(Request $request, ProductVersion $version): RedirectResponse
    {
        $this->allow('manage', $version);
        abort_unless($version->status === VersionStatus::Draft, 404);

        // Self-assessment chosen while a FAIL or an unmet restriction requires a Notified Body.
        $assessment = Assessment::for($version);
        if ((new Conformity($assessment))->conflict()) {
            return redirect()->route('versions.conformity', $version)->with('error', __('conformity.conflict'));
        }

        // Every walked verdict must be justified the way the standard asks (Justification).
        $unjustified = $assessment->unjustified();
        if ($unjustified !== []) {
            $first = $unjustified[0];
            $what = $first['requirement'].' · '.($first['item']?->label() ?? __('evaluations.product'));

            return back()->with('error', trans_choice('assessment.submit_refused', count($unjustified), ['count' => count($unjustified)]))
                ->with('error_link', [
                    'href' => Links::walk($version, $first['standard'], $first['requirement'], $first['item']),
                    'label' => __('assessment.submit_first', ['what' => $what]),
                ]);
        }

        // Assessment units not provided yet never block the submission (owner, 2026-10-23: things are
        // not sequential): reviewers validate the evaluations meanwhile, the units can still be
        // provided in review (AssessmentUnitController::canRecord), approval waits for them.
        $missing = AssessmentCases::for($assessment)->missingItems();

        $version->statusReason = 'submitted';
        $version->forceFill([
            'status' => VersionStatus::InReview,
            'submitted_at' => now(),
            'submitted_by' => $request->user()->id,
        ])->save();

        $reviewers = $version->product->members()->where('role', ProductRole::Reviewer)->with('user')->get();
        foreach ($reviewers as $member) {
            Mail::to($member->user)->queue(new VersionSubmitted($version, $member->user));
        }

        // Allowed with INCONCLUSIVE items, but they must be resolved before approval: say which.
        // Without a reviewer the owner reviews it (self-review, ReviewRights): on to the Review page.
        $inconclusive = AssessmentCases::for($assessment)->inconclusiveItems();
        $response = $reviewers->isEmpty()
            ? redirect()->route('versions.review', $version)->with('status', __('review.submitted_self'))
            : back()->with('status', __('versions.submitted'));
        $warnings = [];
        if ($inconclusive !== []) {
            $warnings[] = trans_choice('assessment.submitted_with_inconclusive', count($inconclusive), [
                'count' => count($inconclusive), 'list' => self::listOf($inconclusive),
            ]);
            $response->with('warning_link', ['href' => $inconclusive[0]['href'], 'label' => __('assessment.approve_first')]);
        }
        if ($missing !== []) {
            // Units not provided yet: say so, link to the first one, and offer "outside REDact".
            $warnings[] = trans_choice('assessment.submitted_units_missing', count($missing), ['count' => count($missing)]);
            if ($inconclusive === []) {
                $response->with('warning_link', ['href' => $missing[0]['href'], 'label' => __('assessment.provide_units')]);
            }
            $response->with('warning_post', ['action' => route('versions.units.where', $version), 'fields' => ['where' => 'external'], 'label' => __('assessment.where.lab_action')]);
        }
        if ($warnings !== []) {
            $response->with('warning', implode(' ', $warnings));
        }

        return $response;
    }

    /**
     * "ACC-1 · Door key, ACC-1 · Front door, … (+3)": the first items of a list, for a message.
     *
     * @param  list<array{label: string, href: string}>  $items
     */
    public static function listOf(array $items, int $max = 5): string
    {
        $labels = array_column(array_slice($items, 0, $max), 'label');

        return implode(', ', $labels).(count($items) > $max ? ' (+'.(count($items) - $max).')' : '');
    }

    public function withdraw(ProductVersion $version): RedirectResponse
    {
        $this->allow('manage', $version);
        abort_unless($version->status === VersionStatus::InReview, 404);

        $version->statusReason = 'withdrawn';
        $version->forceFill(['status' => VersionStatus::Draft, 'submitted_at' => null])->save();

        return back()->with('status', __('versions.withdrawn'));
    }

    public function reopen(ProductVersion $version): RedirectResponse
    {
        $this->allow('manage', $version);
        abort_unless($version->status === VersionStatus::Approved, 404);

        $version->statusReason = 'reopened';
        $version->forceFill(['status' => VersionStatus::Draft, 'approved_at' => null])->save();

        return back()->with('status', __('versions.reopened'));
    }
}
