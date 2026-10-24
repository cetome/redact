<?php

namespace App\Http\Controllers;

use App\Actions\CreateProduct;
use App\Guest\OpenSandbox;
use App\Guest\Sandbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Start with this product" on the home page (the RED scoping at #start, public/_tool):
 *
 *  - a visitor: the invisible ALTCHA proof (solved on page load), then a sandbox
 *    (OpenSandbox: same per-network limit, 24 h, one product, no AI) and the product
 *    with its version 1.0 and the scoping answers (CreateProduct, as New product);
 *  - a signed-in guest or an account with one organisation: the product is created in
 *    that organisation straight away (the sandbox and plan limits still apply);
 *  - an account in several organisations: New product with the name and answers kept,
 *    to choose the organisation there (never guess where a product belongs).
 *
 * Every path lands on the version's first step (Product) with the "created" message; a new
 * sandbox also gets the one-time "You're in. Next…" tip there.
 */
class HomeStartController extends Controller
{
    public function __invoke(Request $request, OpenSandbox $open, CreateProduct $create): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null && ! Sandbox::enabled(), 404);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'answers' => ['nullable', 'array'],
            'altcha' => $user === null ? ['required', 'string'] : ['nullable'],
        ], ['altcha.required' => __('auth.register.altcha_required')]);

        if ($user !== null && Sandbox::productLimitReached($user)) {
            return redirect()->route('dashboard')->with('error', __('guest.product_limit'));
        }

        if ($user !== null) {
            $organisations = $user->organisations()->pluck('organisations.id');
            if ($organisations->count() !== 1) {
                return redirect()->route('products.create')->withInput($request->only('name', 'answers'));
            }
            $organisationId = (string) $organisations->first();
        } else {
            $user = $open($request, $data['altcha']);
            $organisationId = (string) $user->organisations()->value('organisations.id');
        }

        $version = $create($user, [
            'organisation_id' => $organisationId,
            'name' => $data['name'] ?? null,
            'answers' => $data['answers'] ?? [],
        ]);

        return redirect()->route('versions.edit', $version)
            // One message: what was created and what to do next (x-flash 'next', owner 2026-10-23).
            ->with('next', $create->createdMessage($version));
    }
}
