<?php

namespace App\Admin;

use App\Enums\VersionStatus;
use App\Guest\Sandbox;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Key figures and daily series for the console dashboard.
 */
final class DashboardStats
{
    public const DAYS = 90;

    /** @return array<string, int|array<string, int>> */
    public function kpis(): array
    {
        // Accounts: sandbox guests are counted apart (sandboxes()).
        $users = DB::table('users')->where('is_guest', false);
        $active = fn (int $days) => DB::table('request_logs')
            ->whereNotNull('user_id')
            ->where('created_at', '>=', now()->subDays($days))
            ->distinct()
            ->count('user_id');

        $versions = DB::table('product_versions')
            ->join('products', 'products.id', '=', 'product_versions.product_id')
            ->whereNull('products.deleted_at')
            ->selectRaw('product_versions.status as status, COUNT(*) as n')
            ->groupBy('product_versions.status')
            ->pluck('n', 'status');

        $byStatus = [];
        foreach (VersionStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($versions[$status->value] ?? 0);
        }

        return [
            'users' => (clone $users)->count(),
            'verified' => (clone $users)->whereNotNull('email_verified_at')->count(),
            'new_7' => (clone $users)->where('created_at', '>=', now()->subDays(7))->count(),
            'new_30' => (clone $users)->where('created_at', '>=', now()->subDays(30))->count(),
            'active_1' => $active(1),
            'active_7' => $active(7),
            'active_30' => $active(30),
            'organisations' => DB::table('organisations')->count(),
            'products' => DB::table('products')->whereNull('deleted_at')->count(),
            'versions' => $byStatus,
            'evaluations' => DB::table('evaluations')->whereNotNull('verdict')->count(),
            'reports' => DB::table('activity_log')->where('log_name', 'report')->count(),
            'ai_today' => (int) DB::table('ai_usage')->where('created_at', '>=', today())->sum('units'),
            'ai_30' => (int) DB::table('ai_usage')->where('created_at', '>=', now()->subDays(30))->sum('units'),
        ];
    }

    /**
     * Guest sandboxes, from the anonymous activity('guest') log (App\Guest):
     * started / kept / expired over 7 and 30 days, and sandboxes alive now.
     *
     * @return array{started_7: int, started_30: int, kept_7: int, kept_30: int, expired_7: int, expired_30: int, active: int}
     */
    public function sandboxes(): array
    {
        $count = fn (string $description, int $days) => DB::table('activity_log')
            ->where('log_name', Sandbox::LOG)
            ->where('description', $description)
            ->where('created_at', '>=', now()->subDays($days))
            ->count();

        return [
            'started_7' => $count('sandbox started', 7),
            'started_30' => $count('sandbox started', 30),
            'kept_7' => $count('sandbox kept', 7),
            'kept_30' => $count('sandbox kept', 30),
            'expired_7' => $count('sandbox expired', 7),
            'expired_30' => $count('sandbox expired', 30),
            'active' => DB::table('users')->where('is_guest', true)->where('guest_expires_at', '>', now())->count(),
        ];
    }

    /** @return array<string, array<string, int>> chart key => (Y-m-d => value) */
    public function series(int $days = self::DAYS): array
    {
        return [
            'signups' => DailySeries::of(DB::table('users')->where('is_guest', false), $days),
            'active' => DailySeries::of(DB::table('request_logs')->whereNotNull('user_id'), $days, 'COUNT(DISTINCT user_id)'),
            'views' => DailySeries::of(self::pageViews(), $days),
            'ai' => DailySeries::of(DB::table('ai_usage'), $days, 'COALESCE(SUM(units), 0)'),
        ];
    }

    /** A page view: a successful GET that rendered a page. */
    public static function pageViews(): Builder
    {
        return DB::table('request_logs')->where('method', 'GET')->where('status', '<', 400);
    }
}
