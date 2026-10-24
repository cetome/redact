<?php

namespace App\Auth\Responses;

use App\Auth\SignIn;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as Contract;

/**
 * Runs after laravel/passkeys has verified the assertion, logged the user in
 * and regenerated the session: completes the sign-in the REDact way
 * (session keys, last login, security log).
 */
class PasskeyLoginResponse implements Contract
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

        $this->signIn->complete($request, $user, $method, $request->boolean('remember'));

        $target = redirect()->intended(route('dashboard'))->getTargetUrl();

        if ($request->wantsJson()) {
            return new JsonResponse(['redirect' => $target]);
        }

        return redirect($target);
    }
}
