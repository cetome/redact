<?php

namespace App\Http\Controllers;

use App\Guest\DemoProduct;
use App\Guest\OpenSandbox;
use App\Guest\Sandbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "See a demo" (POST /try/demo): the content pack's demo product in a guest sandbox, landing on its
 * Overview. Owner, 2026-10-23: anyone, no account.
 *
 *  - Visitor: the sandbox opens like "Try REDact" (OpenSandbox: per-network limit, invisible ALTCHA
 *    proof), then the demo is loaded into it.
 *  - Signed-in guest: the demo fills an empty sandbox; a sandbox that already holds a product keeps
 *    it (one product per sandbox, Sandbox::maxProducts()): delete it first.
 *  - Signed-in account: back to the dashboard, where "Load the demo" (POST /demo, account()) loads
 *    the read-only demo product into its organisation.
 *  - No demo in the pack (fixture or open demo pack) or the sandbox switched off: 404.
 *
 * "Load the demo" (POST /demo, accounts, free or paid): the read-only demo product (products.is_demo,
 * plan slot "demo": never counted) in the person's organisation (DemoProduct::organisationOf()), one
 * per organisation: a second load opens the one it has. Its declaration names the demo's fictitious
 * manufacturer, never the organisation's legal details. Guests: 404 (they use /try/demo).
 */
class DemoController extends Controller
{
    public function __invoke(Request $request, OpenSandbox $open, DemoProduct $demo): RedirectResponse
    {
        abort_unless($demo->available(), 404);

        $user = $request->user();
        if ($user !== null && ! $user->is_guest) {
            return redirect()->route('dashboard');
        }
        if ($user !== null && Sandbox::productLimitReached($user)) {
            return redirect()->to(Sandbox::home($user))->with('error', __('guest.demo.full'));
        }

        if ($user === null) {
            // No proof at all: the browser runs no script. /try explains the sandbox and says what is missing.
            if (! $request->filled('altcha')) {
                return redirect()->route('guest.start')->withErrors(['altcha' => __('auth.register.altcha_required')]);
            }
            $user = $open($request, (string) $request->input('altcha'));
        }

        $version = $demo->load($user);
        activity(Sandbox::LOG)->causedBy($user)->log('demo loaded');

        return redirect()->route('versions.show', $version)->with('next', __('guest.demo.loaded'));
    }

    public function account(Request $request, DemoProduct $demo): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->is_guest || ! $demo->hasData(), 404);
        $organisation = DemoProduct::organisationOf($user);
        abort_if($organisation === null, 404);

        if ($existing = DemoProduct::of($organisation)) {
            return redirect()->to($existing->homeUrl())->with('status', __('products.demo.exists'));
        }

        $version = $demo->load($user, $organisation);

        return redirect()->route('versions.show', $version); // the read-only notice says it all, no flash
    }
}
