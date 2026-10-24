<?php

namespace App\Http\Controllers\Auth;

use App\Auth\SignIn;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shown after an e-mail sign-in while the account has no passkey: a strong
 * nudge to add one, with "Skip for now".
 */
class PasskeySuggestionController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->passkeys()->exists()) {
            return $this->skip($request);
        }

        return view('auth.passkey-suggestion', [
            'continueUrl' => route('auth.passkey.skip'),
        ]);
    }

    public function skip(Request $request): RedirectResponse
    {
        $request->session()->forget(SignIn::SUGGEST_PASSKEY);

        return redirect()->intended(route('dashboard'));
    }
}
