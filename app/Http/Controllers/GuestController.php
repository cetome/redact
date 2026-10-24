<?php

namespace App\Http\Controllers;

use App\Auth\Altcha;
use App\Auth\ClientKey;
use App\Auth\EmailChallenge;
use App\Auth\SafeMail;
use App\Auth\SecurityLog;
use App\Auth\SignIn;
use App\Auth\SignInMailer;
use App\Auth\Timing;
use App\Guest\ClaimSandbox;
use App\Guest\OpenSandbox;
use App\Guest\Sandbox;
use App\Mail\Auth\VerificationCodeMail;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Guest sandbox: start one ("Try without an account") and keep its work
 * ("Keep my work": a code to an e-mail address, then App\Guest\ClaimSandbox).
 *
 * The claim answers the same whether or not the address has an account, pads
 * its timing like the sign-in flow and uses the same code challenge
 * (App\Auth\EmailChallenge: HMAC in the session, bound to this browser,
 * 15 minutes, 5 attempts).
 */
class GuestController extends Controller
{
    /** Session key holding the claim form between the two steps. */
    private const CLAIM = 'guest.claim';

    /** Claim e-mails per sandbox and 15 minutes. */
    private const CLAIMS_PER_15_MINUTES = 5;

    public function show(Request $request): View|RedirectResponse
    {
        abort_unless(Sandbox::enabled(), 404);

        if ($user = $request->user()) {
            return redirect()->to($user->is_guest ? Sandbox::home($user) : route('dashboard'));
        }

        return view('guest.start', ['hours' => Sandbox::ttlHours()]);
    }

    public function store(Request $request, OpenSandbox $open): RedirectResponse
    {
        abort_unless(Sandbox::enabled(), 404);

        if ($user = $request->user()) {
            return redirect()->to($user->is_guest ? Sandbox::home($user) : route('dashboard'));
        }

        // No proof at all: the browser runs no script (the home page's "Try REDact" posts here too).
        // /try explains the sandbox and says what is missing; it never sends itself after an error.
        if (! $request->filled('altcha')) {
            return redirect()->route('guest.start')->withErrors(['altcha' => __('auth.register.altcha_required')]);
        }

        $open($request, (string) $request->input('altcha'));

        // The empty sandbox dashboard is the New product scoping.
        return redirect()->route('dashboard')->with('status', __('guest.start.started'));
    }

    /** A fresh proof-of-work challenge for the ALTCHA widget (visitors and signed-in guests). */
    public function challenge(Request $request, Altcha $altcha): JsonResponse
    {
        abort_unless(Sandbox::enabled(), 404);
        abort_if($request->user() !== null && ! $request->user()->is_guest, 404);

        return response()->json($altcha->challenge())->header('Cache-Control', 'no-store');
    }

    public function claim(Request $request, EmailChallenge $challenges): View|RedirectResponse
    {
        $guest = $request->user();
        if (! $guest->is_guest) {
            return redirect()->route('dashboard');
        }

        $pending = $challenges->hasPending($request, 'guest_claim') && ! $request->routeIs('guest.claim.other');

        return view('guest.claim', [
            'form' => (array) $request->session()->get(self::CLAIM, []),
            'pending' => $pending,
            'email' => $challenges->pendingEmail($request, 'guest_claim'),
            'expires' => Sandbox::expiryLabel($guest),
        ]);
    }

    public function sendClaim(Request $request, Altcha $altcha, EmailChallenge $challenges): RedirectResponse
    {
        $start = microtime(true);
        $guest = $this->guest($request);

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

        $email = mb_strtolower(trim($data['email']));
        if (Sandbox::isReservedAddress($email)) {
            return back()->withInput()->withErrors(['email' => __('guest.claim.reserved')]);
        }

        $key = 'guest-claim:'.$guest->getKey();
        if (RateLimiter::tooManyAttempts($key, self::CLAIMS_PER_15_MINUTES)) {
            return back()->withInput()->withErrors(['email' => __('auth.too_many')]);
        }

        if (! $altcha->verify($data['altcha'])) {
            return back()->withInput()->withErrors(['altcha' => __('auth.register.altcha_failed')]);
        }
        RateLimiter::hit($key, 900);

        // The address's own limits are shared with the sign-in e-mail, so no path can flood an inbox.
        $addressKey = ClientKey::email($email);
        $short = 'signin-mail:15m:'.$addressKey;
        $daily = 'signin-mail:day:'.$addressKey;
        $allowed = ! RateLimiter::tooManyAttempts($short, SignInMailer::PER_15_MINUTES)
            && ! RateLimiter::tooManyAttempts($daily, SignInMailer::PER_DAY);
        RateLimiter::hit($short, 900);
        RateLimiter::hit($daily, 86400);

        // An existing account must be active (a disabled one gets nothing, silently, like sign-in).
        $existing = User::query()->where('email', $email)->first();
        $usable = $existing === null || ($existing->active && ! $existing->is_guest);

        $request->session()->put(self::CLAIM, [
            'name' => trim($data['name']),
            'email' => $email,
            'organisation' => trim((string) ($data['organisation'] ?? '')),
            'product_news' => (bool) ($data['product_news'] ?? false),
        ]);

        $challenge = $challenges->start($request, 'guest_claim', $allowed && $usable ? $guest : null, $email, $email);

        if ($challenge !== null) {
            SafeMail::send($email, new VerificationCodeMail(trim($data['name']), app()->getLocale(), $challenge['code'], 'guest_claim'));
        }

        Timing::padSince($start, Timing::sendMs());

        return redirect()->route('guest.claim')->with('status', __('guest.claim.sent'));
    }

    public function verifyClaim(Request $request, EmailChallenge $challenges, ClaimSandbox $claim, SignIn $signIn): RedirectResponse
    {
        $start = microtime(true);
        $guest = $this->guest($request);

        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $token = $challenges->verifyCode($request, 'guest_claim', (string) preg_replace('/\D/', '', $data['code']));
        Timing::padSince($start, Timing::codeMs());

        $form = $request->session()->get(self::CLAIM);
        $invalid = fn () => back()->withErrors(['code' => __('auth.code.invalid')]);

        if ($token === null || (int) $token->user_id !== (int) $guest->getKey() || ! $token->new_email
            || ! is_array($form) || ($form['email'] ?? null) !== $token->new_email) {
            return $invalid();
        }

        $details = [
            'name' => (string) $form['name'],
            'email' => (string) $token->new_email,
            'organisation' => (string) ($form['organisation'] ?? ''),
            'product_news' => (bool) $form['product_news'],
        ];
        $existing = User::query()->where('email', $details['email'])->first();

        if ($existing === null) {
            try {
                $user = $claim->convert($guest, $details);
            } catch (QueryException) {
                // The address was taken a moment ago: the person can simply try again.
                return $invalid();
            }

            $request->session()->forget(self::CLAIM);
            SecurityLog::log('guest sandbox kept', $user, ['path' => 'converted'], $user);
            $signIn->complete($request, $user, SignIn::EMAIL_CODE);
            $request->session()->put(SignIn::SUGGEST_PASSKEY, true);

            return redirect()->route('auth.passkey.suggest')->with('status', __('guest.claim.converted'));
        }

        if ($existing->is_guest || ! $existing->active) {
            return $invalid();
        }

        $account = $claim->merge($guest, $existing, $details);
        $request->session()->forget(self::CLAIM);
        $signIn->complete($request, $account, SignIn::EMAIL_CODE);

        return redirect()->to($signIn->homeAfterEmail($request, $account))->with('status', __('guest.claim.merged'));
    }

    /** The signed-in sandbox guest, else 404 (the claim is for guests only). */
    private function guest(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->is_guest, 404);

        return $user;
    }
}
