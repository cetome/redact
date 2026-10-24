<?php

namespace App\Http\Controllers;

use App\Auth\AccountDeleter;
use App\Auth\EmailChallenge;
use App\Auth\SafeMail;
use App\Auth\SecurityLog;
use App\Auth\Sessions;
use App\Auth\Timing;
use App\Http\Middleware\RequireRecentAuth;
use App\Mail\Auth\EmailChangedMail;
use App\Mail\Auth\VerificationCodeMail;
use App\Models\User;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\Response;

/**
 * The account page, in tabs (/account, /account/{tab}): profile and preferences, security (e-mail
 * address, passkeys), sessions, data export, deletion. Only the selected tab is rendered,
 * and every form comes back to its own tab.
 */
class AccountController extends Controller
{
    public const TABS = ['profile', 'security', 'sessions', 'data', 'delete'];

    /** The account page on one tab (unknown values fall back to the profile). */
    public static function tabUrl(string $tab): string
    {
        return in_array($tab, self::TABS, true) && $tab !== 'profile' ? route('account.tab', $tab) : route('account.show');
    }

    public function show(Request $request, Sessions $sessions, EmailChallenge $challenges): View
    {
        /** @var User $user */
        $user = $request->user();
        $tab = $request->route('tab');
        $tab = is_string($tab) && in_array($tab, self::TABS, true) ? $tab : 'profile';
        $window = (int) config('redact.auth.recent_auth') * 60;
        $last = RequireRecentAuth::lastProof($request->session());

        return view('account.show', [
            'tab' => $tab,
            'user' => $user,
            'passkeys' => $user->passkeys()->orderBy('created_at')->get(),
            'sessions' => $sessions->forUser($request, $user),
            'organisations' => $user->organisations()->orderBy('name')->get(),
            'locales' => config('redact.locales'),
            'timezones' => DateTimeZone::listIdentifiers(),
            'recent' => time() - $last <= $window,
            'emailChangePending' => $challenges->hasPending($request, 'email_change'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'locale' => ['required', 'string', Rule::in(array_keys(config('redact.locales')))],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'product_news' => ['nullable', 'boolean'],
            'shortcuts' => ['nullable', 'boolean'],
        ]);

        $user->fill([
            'name' => trim($data['name']),
            'locale' => $data['locale'],
            'timezone' => $data['timezone'],
            'wants_product_news' => (bool) ($data['product_news'] ?? false),
        ]);
        // The form always sends it (a hidden 0 before the checkbox): absent means unchanged.
        if (array_key_exists('shortcuts', $data) && $data['shortcuts'] !== null) {
            $user->shortcuts_enabled = (bool) $data['shortcuts'];
        }
        $user->save();

        app()->setLocale($user->locale);

        return redirect()->to(self::tabUrl('profile'))->with('status', __('auth.account.saved'));
    }

    /** Goes through recent.auth, then back to the account page on the tab it came from (/confirm/{tab}). */
    public function reconfirmed(Request $request): RedirectResponse
    {
        return redirect()->to(self::tabUrl((string) ($request->route('tab') ?? 'profile')));
    }

    public function requestEmailChange(Request $request, EmailChallenge $challenges): RedirectResponse
    {
        $start = microtime(true);
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:191']]);
        $email = mb_strtolower(trim($data['email']));

        if ($email === $user->email) {
            return back()->withErrors(['email' => __('auth.email_change.same')]);
        }

        $key = 'email-change:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => __('auth.too_many')]);
        }
        RateLimiter::hit($key, 900);

        // Taken: same answer, no code (the other account is not told either).
        $taken = User::where('email', $email)->exists();
        $challenge = $challenges->start($request, 'email_change', $taken ? null : $user, $email, $email);

        if ($challenge !== null) {
            SafeMail::send($email, new VerificationCodeMail($user->name, $user->locale ?: config('app.locale'), $challenge['code'], 'email_change'));
        }

        Timing::padSince($start, Timing::sendMs());

        return redirect()->route('account.email.confirm')->with('status', __('auth.email_change.sent'));
    }

    public function showEmailChange(Request $request, EmailChallenge $challenges): View|RedirectResponse
    {
        if (! $challenges->hasPending($request, 'email_change')) {
            return redirect()->to(self::tabUrl('security'));
        }

        return view('account.email-confirm', ['email' => $challenges->pendingEmail($request, 'email_change')]);
    }

    public function confirmEmailChange(Request $request, EmailChallenge $challenges, Sessions $sessions): RedirectResponse
    {
        $start = microtime(true);
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $token = $challenges->verifyCode($request, 'email_change', (string) preg_replace('/\D/', '', $data['code']));
        Timing::padSince($start, Timing::codeMs());

        if ($token === null || (int) $token->user_id !== (int) $user->getKey() || ! $token->new_email
            || User::where('email', $token->new_email)->exists()) {
            return back()->withErrors(['code' => __('auth.code.invalid')]);
        }

        $old = $user->email;
        $user->forceFill(['email' => $token->new_email, 'email_verified_at' => now()])->save();

        SecurityLog::log('e-mail changed', $user, ['from' => $old, 'to' => $user->email], $user);
        SafeMail::send($old, new EmailChangedMail($user->name, $user->locale ?: config('app.locale'), $user->email));
        $sessions->revokeOthers($request, $user, 'e-mail changed');

        return redirect()->to(self::tabUrl('security'))->with('status', __('auth.email_change.done'));
    }

    public function revokeSessions(Request $request, Sessions $sessions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $sessions->revokeOthers($request, $user, 'signed out other devices');

        return redirect()->to(self::tabUrl('sessions'))->with('status', __('auth.sessions.revoked'));
    }

    /** Everything we hold about the person (GDPR art. 15 and 20), as JSON. */
    public function export(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $activity = Activity::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('causer_type', $user->getMorphClass())->where('causer_id', $user->getKey()))
                ->orWhere(fn ($q) => $q->where('subject_type', $user->getMorphClass())->where('subject_id', $user->getKey())))
            ->orderBy('id')
            ->get(['log_name', 'description', 'event', 'subject_type', 'subject_id', 'attribute_changes', 'properties', 'created_at']);

        $data = [
            'exported_at' => now()->toIso8601String(),
            'profile' => $user->only([
                'name', 'email', 'email_verified_at', 'organisation_name', 'locale', 'timezone',
                'wants_product_news', 'terms_accepted_at', 'terms_version', 'last_login_at',
                'last_login_method', 'created_at', 'updated_at',
            ]),
            'roles' => $user->getRoleNames()->values(),
            'organisations' => $user->organisations()->get()->map(fn ($org) => [
                'name' => $org->name,
                'role' => $org->getRelationValue('pivot')->role,
                'joined_at' => $org->getRelationValue('pivot')->created_at,
            ]),
            'product_memberships' => DB::table('product_members')
                ->join('products', 'products.id', '=', 'product_members.product_id')
                ->where('product_members.user_id', $user->getKey())
                ->get(['products.name as product', 'product_members.role', 'product_members.external', 'product_members.created_at']),
            'passkeys' => $user->passkeys()->get()->map(fn ($key) => [
                'name' => $key->name,
                'created_at' => $key->created_at,
                'last_used_at' => $key->last_used_at,
            ]),
            'activity' => $activity,
        ];

        $filename = 'redact-account-'.now()->format('Y-m-d').'.json';

        return response(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function destroy(Request $request, AccountDeleter $deleter): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('auth.delete.confirm_required')]);

        if ($deleter->ownsProducts($user)) {
            return redirect()->to(self::tabUrl('delete'))->with('error', __('auth.delete.owns_products'));
        }

        Auth::guard('web')->logout();
        $deleter->delete($user, 'self-service');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', __('auth.delete.done'));
    }
}
