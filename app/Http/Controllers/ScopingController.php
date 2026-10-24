<?php

namespace App\Http\Controllers;

use App\Ai\Features\SuggestScoping;
use App\Assessment\ArticlesCheck;
use App\Assessment\Assessment;
use App\Assessment\ScopingForm;
use App\Models\ProductVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Essential requirements" step (route key "scoping"), after the product: the RED scoping
 * (cetome.com) says which essential requirements (articles 3.3 d/e/f) apply, so which parts of
 * EN 18031, and a person confirms (ArticlesCheck). The risk assessment comes next and checks the
 * risks against them. The restrictions are asked at the end, in the conformity step.
 */
class ScopingController extends Controller
{
    public function edit(ProductVersion $version, ScopingForm $form, SuggestScoping $suggest): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canView(), 404);

        $assessment = Assessment::for($version);
        $check = new ArticlesCheck($assessment);

        return view('scoping.edit', [
            'version' => $version,
            'access' => $access,
            'sections' => $form->sections(ScopingForm::SCOPING),
            'answers' => $version->scopingAnswers()->pluck('value', 'question')->all(),
            'result' => $assessment->scoping(),
            'pack' => $assessment->pack,
            'aiRecorded' => $suggest->recorded($version),
            'state' => $check->state(),
            'confirmedBy' => $version->articlesConfirmedBy()->value('name'),
        ]);
    }

    public function update(Request $request, ProductVersion $version, ScopingForm $form, SuggestScoping $suggest): RedirectResponse
    {
        $this->allow('edit', $version);
        $request->validate(['answers' => ['nullable', 'array'], 'ai_scoping' => ['nullable', 'string', 'max:20000']]);

        $form->save($version, $request->input('answers', []), ScopingForm::SCOPING, $request->user()->id);
        // The applied "Suggest with AI" proposal, if any: marks the suggested answers "AI Generated".
        $suggest->record($version, $request->input('ai_scoping'), $request->user());

        // "Confirm the essential requirements": what the page shows once these answers are saved.
        if ($request->boolean('confirm')) {
            ArticlesCheck::for($version)->confirm($request->user());

            return redirect()->route('versions.scoping', $version)->with('status', __('articles.confirmed_flash'));
        }

        // "Save and continue": on to the step after this one (the risk assessment).
        $next = $request->boolean('continue') ? Assessment::for($version)->nextStepAfter('scoping') : null;

        return redirect()->to($next['href'] ?? route('versions.scoping', $version))->with('status', __('scoping.saved'));
    }
}
