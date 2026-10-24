<?php

namespace App\Http\Middleware;

use App\Guest\Sandbox;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guest sandbox accounts (App\Guest), on every web request of a guest:
 *
 *  - an expired sandbox is signed out (redact:purge-guests deletes it soon);
 *  - account, passkey and identity-confirmation pages are closed (a guest has
 *    no credential to manage): pages lead to "Keep my work", anything else 404;
 *  - downloads and uploads need an account: they lead to "Keep my work" too;
 *  - sliding expiry: guest_expires_at moves to now + ttl, at most once every
 *    touch_minutes, so a busy guest does not cost a write per request.
 */
class GuestSandbox
{
    /** Routes a guest never uses (a sandbox has no organisation team either). */
    private const CLOSED = ['account.*', 'auth.confirm*', 'auth.passkey.*', 'passkey.store', 'passkey.registration-options', 'passkey.destroy', 'passkey.confirm*', 'organisations.team*',
        // Histories (App\History): a sandbox has none.
        'products.history*', 'organisations.history*'];

    /**
     * Downloads and uploads need an account (owner, 2026-10-23): reports and filled workbooks,
     * documents, bills of materials and workbook imports. A guest is led to "Keep my work".
     */
    private const ACCOUNT_REQUIRED = [
        'reports.download', 'versions.workbooks.*', 'versions.import.*',
        'versions.documents.store', 'versions.documents.download',
        'versions.sbom', 'versions.sbom.show', 'versions.sbom.store', 'versions.sbom.apply', 'versions.sbom.download', 'sbom.template',
        'versions.vex.*',
        // Declaration & technical file: the Word declaration, the zip, the certificate (owner, 2026-10-23).
        'versions.declaration.*', 'versions.technical-file.*', 'versions.certificate.*',
        // Product identification from Excel, and its template.
        'versions.identification.import', 'versions.identification.template',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->is_guest) {
            return $next($request);
        }

        if ($user->guest_expires_at !== null && $user->guest_expires_at->isPast()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401);
            }

            return redirect()->route('guest.start')->with('status', __('guest.expired'));
        }

        if ($request->routeIs(...self::CLOSED)) {
            abort_unless($request->isMethod('GET') && ! $request->expectsJson(), 404);

            return redirect()->route('guest.claim');
        }

        if ($request->routeIs(...self::ACCOUNT_REQUIRED)) {
            abort_if($request->expectsJson(), 403, __('guest.account_required'));

            return redirect()->route('guest.claim')->with('status', __('guest.account_required'));
        }

        $this->touch($user);

        return $next($request);
    }

    private function touch(User $user): void
    {
        $threshold = Sandbox::expiresFromNow()->subMinutes(max(1, (int) config('redact.guest.touch_minutes', 10)));

        if ($user->guest_expires_at !== null && $user->guest_expires_at->greaterThan($threshold)) {
            return;
        }

        $expires = Sandbox::expiresFromNow();
        // A plain update: no model events, no activity entry, no updated_at churn.
        User::query()->whereKey($user->getKey())->toBase()->update(['guest_expires_at' => $expires]);
        $user->setAttribute('guest_expires_at', $expires);
        $user->syncOriginalAttribute('guest_expires_at');
    }
}
