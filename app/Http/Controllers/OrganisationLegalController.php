<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The manufacturer as the EU declaration of conformity names it (RED Annex VI, point 2): legal
 * name, address, country and the authorised representative. Only the organisation's
 * administrators change it; the change is logged (Organisation logs these fields).
 */
class OrganisationLegalController extends Controller
{
    public function update(Request $request, Organisation $organisation): RedirectResponse
    {
        abort_unless($organisation->isAdmin($request->user()), 404);

        $data = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:1000'],
            'country' => ['nullable', 'string', 'max:80'],
            'authorised_rep_name' => ['nullable', 'string', 'max:200'],
            'authorised_rep_address' => ['nullable', 'string', 'max:1000'],
            'back' => ['nullable', 'string', 'max:500'],
        ]);

        $organisation->fill(array_map(
            fn ($value) => filled($value) ? trim(str_replace("\r", '', (string) $value)) : null,
            collect($data)->except('back')->all(),
        ))->save();

        // Back to the page that sent the form, when it is one of ours.
        $back = (string) ($data['back'] ?? '');
        $to = str_starts_with($back, url('/').'/') ? $back : url()->previous();

        return redirect()->to($to.'#manufacturer')->with('status', __('declaration.manufacturer.saved'));
    }
}
