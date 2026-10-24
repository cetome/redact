<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkey;

/**
 * Passkeys on the account page. Adding goes through laravel/passkeys
 * (POST /user/passkeys, recent.auth). Mails, security log and session
 * revocation happen in App\Listeners\Auth on the package events.
 */
class PasskeyController extends Controller
{
    public function update(Request $request, int $key): RedirectResponse
    {
        $passkey = $this->find($request, $key);
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $passkey->forceFill(['name' => trim($data['name'])])->save();

        return redirect()->to(AccountController::tabUrl('security'))->with('status', __('auth.passkeys.renamed'));
    }

    public function destroy(Request $request, int $key, DeletePasskey $delete): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $delete($user, $this->find($request, $key));

        return redirect()->to(AccountController::tabUrl('security'))->with('status', __('auth.passkeys.removed'));
    }

    /** Someone else's passkey is a 404, like anything that is not yours. */
    private function find(Request $request, int $id): Passkey
    {
        /** @var User $user */
        $user = $request->user();

        return $user->passkeys()->whereKey($id)->firstOrFail();
    }
}
