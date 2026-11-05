<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\BackUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The language switch in the headers (components/ui/locale-switch): nobody is held in a language
 * they did not choose (owner 2026-11-05). Signed in, it is the account's language (the same as
 * Account > Profile), on every device; otherwise it is kept for the visit (session, read by
 * SetLocale) and an account created then starts in it. Back to the page it was chosen on.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'string', Rule::in(array_keys(config('redact.locales')))]]);

        $request->session()->put('locale', $data['locale']);
        // Signed in, a sandbox guest included (their own throwaway account): the account's language.
        $user = $request->user();
        if ($user instanceof User) {
            $user->forceFill(['locale' => $data['locale']])->save();
        }

        // The page the switch was used on (App\Support\BackUrl: on this site only, never an image).
        return redirect()->to(BackUrl::from($request, $request->user() ? route('dashboard') : route('home')));
    }
}
