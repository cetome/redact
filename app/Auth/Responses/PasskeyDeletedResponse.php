<?php

namespace App\Auth\Responses;

use App\Http\Controllers\AccountController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyDeletedResponse as Contract;

class PasskeyDeletedResponse implements Contract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['status' => 'passkey-deleted']);
        }

        return redirect()->to(AccountController::tabUrl('security'))->with('status', __('auth.passkeys.removed'));
    }
}
