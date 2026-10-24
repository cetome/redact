<?php

namespace App\Http\Controllers\Auth;

use App\Auth\ClientKey;
use App\Auth\EmailChallenge;
use App\Auth\Remember;
use App\Auth\SignIn;
use App\Auth\SignInMailer;
use App\Auth\Timing;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Sign-in: passkey (conditional UI + button, see resources/js/modules/auth.js)
 * or e-mail first factor (link + code).
 */
class LoginController extends Controller
{
    /** Sign-in e-mails per IP (IPv6: per /64) and 15 minutes. */
    public const PER_IP = 20;

    public function show(Request $request, EmailChallenge $challenges): View
    {
        return view('auth.login', [
            'pending' => $challenges->hasPending($request, 'login'),
            'rememberDays' => Remember::days(),
        ]);
    }

    public function send(Request $request, SignInMailer $mailer): RedirectResponse
    {
        $start = microtime(true);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
            'remember' => ['nullable', 'boolean'],
        ]);
        $email = mb_strtolower(trim($data['email']));

        $ipKey = 'signin-ip:'.ClientKey::ip($request);
        if (RateLimiter::tooManyAttempts($ipKey, self::PER_IP)) {
            Timing::padSince($start, Timing::sendMs());

            return back()->withInput()->withErrors(['email' => __('auth.too_many')]);
        }
        RateLimiter::hit($ipKey, 900);

        $mailer->send($request, $email, User::where('email', $email)->first(), remember: $request->boolean('remember') && Remember::enabled());

        Timing::padSince($start, Timing::sendMs());

        return redirect()->route('login.code')->with('status', __('auth.login.sent'));
    }

    public function code(Request $request, EmailChallenge $challenges): View|RedirectResponse
    {
        if (! $challenges->hasPending($request, 'login')) {
            return redirect()->route('login');
        }

        return view('auth.code', [
            'email' => $challenges->pendingEmail($request, 'login'),
        ]);
    }

    public function verify(Request $request, EmailChallenge $challenges, SignIn $signIn): RedirectResponse
    {
        $start = microtime(true);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);
        $code = preg_replace('/\D/', '', $data['code']);
        $remember = $challenges->remembers($request, 'login');

        $token = $challenges->verifyCode($request, 'login', (string) $code);
        Timing::padSince($start, Timing::codeMs());

        if ($token === null || ! $token->user->active) {
            return back()->withErrors(['code' => __('auth.code.invalid')]);
        }

        $signIn->complete($request, $token->user, SignIn::EMAIL_CODE, $remember);

        return redirect($signIn->homeAfterEmail($request, $token->user));
    }
}
