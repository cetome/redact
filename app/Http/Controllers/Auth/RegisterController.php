<?php

namespace App\Http\Controllers\Auth;

use App\Actions\CreateAccount;
use App\Auth\Altcha;
use App\Auth\ClientKey;
use App\Auth\SecurityLog;
use App\Auth\SignInMailer;
use App\Auth\Timing;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Self-service sign-up. The answer is the same whether or not the address
 * already has an account: an existing account simply gets a sign-in e-mail.
 */
class RegisterController extends Controller
{
    /** Sign-ups per IP (IPv6: per /64) and hour. */
    public const PER_IP = 5;

    public function show(): View
    {
        abort_unless(config('redact.auth.signup'), 404);

        return view('auth.register');
    }

    public function store(Request $request, Altcha $altcha, CreateAccount $createAccount, SignInMailer $mailer): RedirectResponse
    {
        abort_unless(config('redact.auth.signup'), 404);
        $start = microtime(true);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:191'],
            'organisation' => ['nullable', 'string', 'max:160'],
            'terms' => ['accepted'],
            'product_news' => ['nullable', 'boolean'],
            'altcha' => ['required', 'string'],
        ], [
            'altcha.required' => __('auth.register.altcha_required'),
            'terms.accepted' => __('auth.register.terms_required'),
        ]);

        $ipKey = 'signup-ip:'.ClientKey::ip($request);
        if (RateLimiter::tooManyAttempts($ipKey, self::PER_IP)) {
            return back()->withInput()->withErrors(['email' => __('auth.too_many')]);
        }

        if (! $altcha->verify($data['altcha'])) {
            return back()->withInput()->withErrors(['altcha' => __('auth.register.altcha_failed')]);
        }
        RateLimiter::hit($ipKey, 3600);

        $email = mb_strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();
        $newAccount = false;

        if ($user === null) {
            try {
                $user = $createAccount([
                    'name' => trim($data['name']),
                    'email' => $email,
                    'organisation' => trim((string) ($data['organisation'] ?? '')),
                    'product_news' => (bool) ($data['product_news'] ?? false),
                    'locale' => app()->getLocale(),
                ]);
                $newAccount = true;
                SecurityLog::log('sign-up', $user, [], $user);
            } catch (QueryException) {
                // Created a moment ago by a parallel request: treat it as existing.
                $user = User::where('email', $email)->first();
            }
        }

        $mailer->send($request, $email, $user, $newAccount);

        Timing::padSince($start, Timing::sendMs());

        return redirect()->route('login.code')->with('status', __('auth.register.sent'));
    }

    /** A fresh proof-of-work challenge for the ALTCHA widget. */
    public function challenge(Altcha $altcha): JsonResponse
    {
        return response()->json($altcha->challenge())->header('Cache-Control', 'no-store');
    }
}
