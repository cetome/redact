<?php

namespace App\Auth\Responses;

use App\Auth\SignIn;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as Contract;

/**
 * After a passkey re-confirmation (/auth/confirm): opens the recent-auth
 * window and, for a verified passkey, lifts the session to "passkey".
 */
class PasskeyConfirmationResponse implements Contract
{
    public function __construct(private readonly SignIn $signIn) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request)
    {
        /** @var User $user */
        $user = $request->user();

        $method = SignIn::userVerified($request->input('credential.response.authenticatorData'))
            ? SignIn::PASSKEY
            : SignIn::PASSKEY_UNVERIFIED;

        $this->signIn->confirmed($request, $user, $method);

        $target = redirect()->intended(route('account.show'))->getTargetUrl();

        if ($request->wantsJson()) {
            return new JsonResponse(['redirect' => $target]);
        }

        return redirect($target);
    }
}
