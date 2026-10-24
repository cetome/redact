<?php

namespace App\Auth;

use App\Http\Middleware\EnsureSessionIsValid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The account's sessions: listing (database session driver) and revocation.
 * Revocation bumps users.session_epoch, which EnsureSessionIsValid checks on
 * every request; the current session can be kept by moving it to the new epoch.
 * Revocation also voids every "Keep me signed in" cookie (App\Auth\Remember).
 */
class Sessions
{
    /**
     * @return Collection<int, stdClass&object{id: string, current: bool, device: string, ip: ?string, last_active: CarbonImmutable}>
     */
    public function forUser(Request $request, User $user): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        $currentId = $request->session()->getId();

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($row) => (object) [
                'id' => (string) $row->id,
                'current' => hash_equals((string) $currentId, (string) $row->id),
                'device' => self::device((string) $row->user_agent),
                'ip' => $row->ip_address === null ? null : (string) $row->ip_address,
                'last_active' => CarbonImmutable::createFromTimestamp((int) $row->last_activity),
            ]);
    }

    /** Signs out every other session of the account and keeps this one. */
    public function revokeOthers(Request $request, User $user, string $reason): void
    {
        $user->revokeSessions();
        $user->refresh();

        if ($request->hasSession() && $request->user()?->is($user)) {
            $request->session()->put(EnsureSessionIsValid::EPOCH, (int) $user->session_epoch);
            app(Remember::class)->keepThisDevice($request, $user);
        }

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->when($request->hasSession(), fn ($q) => $q->where('id', '!=', $request->session()->getId()))
                ->delete();
        }

        SecurityLog::log('sessions revoked', $user, ['reason' => $reason], $user);
    }

    /** "Firefox on Windows" from a user agent, without a UA database. */
    public static function device(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $os = match (true) {
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        if ($browser && $os) {
            return __('auth.sessions.device', ['browser' => $browser, 'os' => $os]);
        }

        return $browser ?? $os ?? __('auth.sessions.unknown_device');
    }
}
