<?php

namespace App\Http\Controllers;

use App\Assessment\Assessment;
use App\Assessment\Conformity;
use App\Assessment\ScopingForm;
use App\Enums\ConformityRoute;
use App\Models\ProductVersion;
use App\Support\NotifiedBodies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Last step before the review: the RED restrictions, the decision-tree
 * outcome and the conformity assessment route that follows from them.
 */
class ConformityController extends Controller
{
    public function edit(ProductVersion $version, ScopingForm $form): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canView(), 404);

        $assessment = Assessment::for($version);
        $conformity = new Conformity($assessment);

        return view('conformity.edit', [
            'version' => $version,
            'access' => $access,
            'assessment' => $assessment,
            'conformity' => $conformity,
            'sections' => $form->sections(ScopingForm::CONFORMITY),
            'answers' => $version->scopingAnswers()->pluck('value', 'question')->all(),
            'form' => $form,
            'bodies' => NotifiedBodies::all(),
        ]);
    }

    public function update(Request $request, ProductVersion $version, ScopingForm $form): RedirectResponse
    {
        $this->allow('edit', $version);

        $data = $request->validate([
            'answers' => ['nullable', 'array'],
            'conformity_route' => ['nullable', Rule::enum(ConformityRoute::class)],
            'notified_body' => ['nullable', 'string', 'max:160'],
            'notified_body_number' => ['nullable', 'regex:/^\d{4}$/'],
        ], ['notified_body_number.regex' => __('declaration.nando.four_digits')]);

        // The questions of a restriction the data settles (e.g. no secure update) are not asked.
        $settled = (new Conformity(Assessment::for($version)))->settledQuestions();
        $form->save($version, $data['answers'] ?? [], ScopingForm::CONFORMITY, $request->user()->id, $settled);

        // The route is checked against the answers just saved and the decision trees.
        $route = isset($data['conformity_route']) ? ConformityRoute::from($data['conformity_route']) : null;
        $conformity = new Conformity(Assessment::for($version->fresh()));
        if ($route && ! $conformity->allows($route)) {
            throw ValidationException::withMessages(['conformity_route' => __('conformity.module_a_refused')]);
        }

        $version->forceFill([
            'conformity_route' => $route,
            'notified_body' => $route?->needsNotifiedBody() ? ($data['notified_body'] ?? null) : null,
            'notified_body_number' => $route?->needsNotifiedBody() ? ($data['notified_body_number'] ?? null) : null,
            // The certificate is recorded on the Declaration step, once the Notified Body has issued it.
        ])->save();

        $redirect = redirect()->route('versions.conformity', $version)->with('status', __('common.saved'));

        // A number NANDO (as retrieved) does not list is kept, with a warning.
        return NotifiedBodies::unknown($version->notified_body_number)
            ? $redirect->with('warning', __('declaration.nando.not_listed', ['date' => NotifiedBodies::retrieved()]))
            : $redirect;
    }
}
