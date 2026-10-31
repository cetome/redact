<?php

namespace App\Http\Controllers;

use App\Assessment\Assessment;
use App\Assessment\SignificantChange;
use App\Models\EinfoEntry;
use App\Models\Evaluation;
use App\Models\ProductVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Changes" step (route key "changes"), only on a version made from an earlier one: the signs of a
 * significant change since that version, the decision with its justification, and what a
 * significant change puts back to re-check, with "Still valid" (SignificantChange).
 */
class SignificantChangeController extends Controller
{
    public function show(ProductVersion $version): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canView(), 404);
        $assessment = Assessment::for($version);
        $changes = $assessment->changes();
        abort_if($changes === null, 404);

        return view('changes.show', [
            'version' => $version,
            'access' => $access,
            'changes' => $changes,
            'signs' => collect($changes->signs())->groupBy('kind'),
            'state' => $changes->state(),
            'rechecks' => $changes->significant() ? $changes->rechecks() : [],
            'decidedBy' => $version->change_decided_by ? $version->changeDecider()->value('name') : null,
            'next' => $assessment->nextStepAfter('changes'),
        ]);
    }

    public function decide(Request $request, ProductVersion $version): RedirectResponse
    {
        $this->allow('edit', $version);
        $changes = Assessment::for($version)->changes();
        abort_if($changes === null, 404);

        $data = $request->validate([
            'decision' => ['required', Rule::in(SignificantChange::DECISIONS)],
            'justification' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
        $changes->decide($request->user(), $data['decision'], trim($data['justification']));

        $count = $data['decision'] === SignificantChange::SIGNIFICANT ? count($changes->rechecks()) : 0;

        return redirect()->route('versions.changes', $version)->with('status', $data['decision'] === SignificantChange::SIGNIFICANT
            ? trans_choice('changes.decided_significant', $count, ['count' => $count])
            : __('changes.decided_not_significant'));
    }

    public function confirmEvaluation(Request $request, ProductVersion $version, Evaluation $evaluation): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($evaluation->product_version_id === $version->id, 404);
        SignificantChange::confirm($evaluation, $request->user());

        return back()->with('status', __('changes.confirmed'));
    }

    public function confirmEinfo(Request $request, ProductVersion $version, EinfoEntry $entry): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($entry->product_version_id === $version->id, 404);
        SignificantChange::confirm($entry, $request->user());

        return back()->with('status', __('changes.confirmed'));
    }
}
