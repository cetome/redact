<?php

namespace App\Http\Controllers\Auth;

use App\Auth\EmailChallenge;
use App\Auth\SafeMail;
use App\Auth\SignIn;
use App\Auth\Timing;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Mail\Auth\VerificationCodeMail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * "Confirm it's you" (route auth.confirm, used by the recent.auth middleware
 * and the staff console): a passkey (laravel/passkeys confirm endpoints, see
 * App\Auth\Responses\PasskeyConfirmationResponse) or a code sent by e-mail.
 */
class ConfirmController extends Controller
{
    public function show(Request $request, EmailChallenge $challenges): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('auth.confirm', [
            'hasPasskeys' => $user->passkeys()->exists(),
            'codeSent' => $challenges->hasPending($request, 'confirm'),
            'staffNeedsPasskey' => $user->isStaff()
                && $request->session()->get(EnsureSessionIsValid::METHOD) !== SignIn::PASSKEY,
        ]);
    }

    public function send(Request $request, EmailChallenge $challenges): RedirectResponse
    {
        $start = microtime(true);
        /** @var User $user */
        $user = $request->user();

        $key = 'confirm-mail:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('error', __('auth.too_many'));
        }
        RateLimiter::hit($key, 900);

        $challenge = $challenges->start($request, 'confirm', $user, $user->email);
        if ($challenge !== null) {
            SafeMail::send($user->email, new VerificationCodeMail($user->name, $user->locale ?: config('app.locale'), $challenge['code'], 'confirm'));
        }

        Timing::padSince($start, Timing::sendMs());

        return redirect()->route('auth.confirm')->with('status', __('auth.confirm.code_sent'));
    }

    public function verify(Request $request, EmailChallenge $challenges, SignIn $signIn): RedirectResponse
    {
        $start = microtime(true);
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $token = $challenges->verifyCode($request, 'confirm', (string) preg_replace('/\D/', '', $data['code']));
        Timing::padSince($start, Timing::codeMs());

        if ($token === null || (int) $token->user_id !== (int) $user->getKey()) {
            return back()->withErrors(['code' => __('auth.code.invalid')]);
        }

        $signIn->confirmed($request, $user, SignIn::EMAIL_CODE);

        return redirect()->intended(route('account.show'));
    }
}
