<?php

namespace App\Admin;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Usage analytics for the console, over a period of 7, 30 or 90 days.
 * Everything comes from first-party tables (request_logs, ai_usage, activity_log).
 */
final class UsageAnalytics
{
    public const PERIODS = [7, 30, 90];

    private const TOP_ROUTES = 25;

    public function __construct(private readonly int $days) {}

    public static function for(int $days): self
    {
        return new self(in_array($days, self::PERIODS, true) ? $days : 30);
    }

    public function days(): int
    {
        return $this->days;
    }

    public function from(): CarbonImmutable
    {
        return DailySeries::start($this->days);
    }

    private function requests(): Builder
    {
        return DB::table('request_logs')->where('created_at', '>=', $this->from());
    }

    /** @return array{requests: int, errors: int, error_rate: float, users: int} */
    public function totals(): array
    {
        $requests = $this->requests()->count();
        $errors = $this->requests()->where('status', '>=', 500)->count();

        return [
            'requests' => $requests,
            'errors' => $errors,
            'error_rate' => $requests > 0 ? round($errors / $requests * 100, 2) : 0.0,
            'users' => $this->requests()->whereNotNull('user_id')->distinct()->count('user_id'),
        ];
    }

    /**
     * Most used routes with timings and server error rate.
     *
     * @return list<array{route: string, hits: int, users: int, avg_ms: int, p95_ms: int, errors: int, error_rate: float}>
     */
    public function topRoutes(): array
    {
        $rows = $this->requests()
            ->whereNotNull('route')
            ->selectRaw('route, COUNT(*) as hits, COUNT(DISTINCT user_id) as users, AVG(duration_ms) as avg_ms, '
                .'SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) as errors')
            ->groupBy('route')
            ->orderByDesc('hits')
            ->limit(self::TOP_ROUTES)
            ->get();

        return $rows->map(fn ($row) => [
            'route' => (string) $row->route,
            'hits' => (int) $row->hits,
            'users' => (int) $row->users,
            'avg_ms' => (int) round((float) $row->avg_ms),
            'p95_ms' => $this->percentile((string) $row->route, (int) $row->hits, 0.95),
            'errors' => (int) $row->errors,
            'error_rate' => $row->hits > 0 ? round($row->errors / $row->hits * 100, 2) : 0.0,
        ])->values()->all();
    }

    /** Nearest-rank percentile of the duration of one route (portable: no window functions needed). */
    private function percentile(string $route, int $count, float $p): int
    {
        if ($count === 0) {
            return 0;
        }

        $offset = max(0, (int) ceil($p * $count) - 1);

        return (int) $this->requests()
            ->where('route', $route)
            ->orderBy('duration_ms')
            ->offset($offset)
            ->limit(1)
            ->value('duration_ms');
    }

    /** @return array<string, int> */
    public function pageViewsPerDay(): array
    {
        return DailySeries::of(DashboardStats::pageViews(), $this->days);
    }

    /** @return array<string, int> */
    public function uniqueUsersPerDay(): array
    {
        return DailySeries::of(DB::table('request_logs')->whereNotNull('user_id'), $this->days, 'COUNT(DISTINCT user_id)');
    }

    /**
     * Distinct signed-in users per ISO week (weeks starting in the period).
     *
     * @return array<string, int> "2026-W42" => users
     */
    public function uniqueUsersPerWeek(): array
    {
        $weeks = [];
        $pairs = $this->requests()
            ->whereNotNull('user_id')
            ->selectRaw('DATE(created_at) as d, user_id')
            ->distinct()
            ->cursor();

        foreach ($pairs as $pair) {
            $week = CarbonImmutable::parse($pair->d)->isoFormat('GGGG-[W]WW');
            $weeks[$week][(int) $pair->user_id] = true;
        }

        ksort($weeks);

        return array_map('count', $weeks);
    }

    /**
     * Page views split by audience: registered accounts, guest sandboxes, anonymous visitors.
     *
     * request_logs.guest is written with each row (null: anonymous, or a row
     * older than the column), so purged guests, whose user id is cleared, stay
     * counted as guests. For older rows the account's current users.is_guest
     * is used (left join), and a row without user is anonymous.
     *
     * @return array{registered: int, guest: int, anonymous: int}
     */
    public function pageViewsByAudience(): array
    {
        $rows = DashboardStats::pageViews()
            ->where('request_logs.created_at', '>=', $this->from())
            ->leftJoin('users', 'users.id', '=', 'request_logs.user_id')
            ->selectRaw('CASE WHEN COALESCE(request_logs.guest, users.is_guest) = 1 THEN \'guest\' '
                ."WHEN request_logs.user_id IS NULL THEN 'anonymous' ELSE 'registered' END as audience, COUNT(*) as n")
            ->groupBy('audience')
            ->pluck('n', 'audience');

        return [
            'registered' => (int) ($rows['registered'] ?? 0),
            'guest' => (int) ($rows['guest'] ?? 0),
            'anonymous' => (int) ($rows['anonymous'] ?? 0),
        ];
    }

    /** @return array<string, int> locale => requests */
    public function locales(): array
    {
        return $this->requests()
            ->selectRaw("COALESCE(locale, '') as locale, COUNT(*) as n")
            ->groupBy('locale')
            ->orderByDesc('n')
            ->pluck('n', 'locale')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return list<array{feature: string, outcome: string, calls: int, units: int}> */
    public function aiUsage(): array
    {
        return DB::table('ai_usage')
            ->where('created_at', '>=', $this->from())
            ->selectRaw('feature, outcome, COUNT(*) as calls, COALESCE(SUM(units), 0) as units')
            ->groupBy('feature', 'outcome')
            ->orderBy('feature')
            ->orderBy('outcome')
            ->get()
            ->map(fn ($row) => [
                'feature' => (string) $row->feature,
                'outcome' => (string) $row->outcome,
                'calls' => (int) $row->calls,
                'units' => (int) $row->units,
            ])->all();
    }

    /**
     * Sign-in methods from the security log: App\Auth\SignIn logs every sign-in
     * as activity('security') "sign-in" with properties.method
     * (email-link, email-code, passkey, passkey-unverified).
     *
     * @return array<string, int> method => sign-ins
     */
    public function signInMethods(): array
    {
        return DB::table('activity_log')
            ->where('log_name', 'security')
            ->where('created_at', '>=', $this->from())
            ->where('description', 'sign-in')
            ->select('properties->method as method')
            ->get()
            ->groupBy(fn ($row) => is_string($row->method) && $row->method !== '' ? trim($row->method, '"') : 'unknown')
            ->map->count()
            ->sortDesc()
            ->all();
    }

    /**
     * Free template downloads (/templates): TemplateController logs each one as
     * activity('usage') "template download" with properties.file (a workbook key or "all").
     *
     * @return array<string, int> file key => downloads
     */
    public function templateDownloads(): array
    {
        return DB::table('activity_log')
            ->where('log_name', 'usage')
            ->where('created_at', '>=', $this->from())
            ->where('description', 'template download')
            ->select('properties->file as file')
            ->get()
            ->groupBy(fn ($row) => is_string($row->file) && $row->file !== '' ? trim($row->file, '"') : 'unknown')
            ->map->count()
            ->sortDesc()
            ->all();
    }

    /**
     * Conversion of the accounts created in the period.
     *
     * @return array<string, int> step => accounts
     */
    public function funnel(): array
    {
        // Real accounts only: sandbox guests that kept their work count from the day they became one.
        $cohort = fn () => DB::table('users')->where('users.is_guest', false)->where('users.created_at', '>=', $this->from());
        $userClass = (new User)->getMorphClass();

        return [
            'signed_up' => $cohort()->count(),
            'verified' => $cohort()->whereNotNull('email_verified_at')->count(),
            'created_product' => $cohort()->whereExists(fn ($q) => $q->from('products')
                ->whereColumn('products.owner_id', 'users.id'))->count(),
            'recorded_evaluation' => $cohort()->whereExists(fn ($q) => $q->from('evaluations')
                ->whereColumn('evaluations.updated_by', 'users.id')->whereNotNull('evaluations.verdict'))->count(),
            'exported_report' => $cohort()->whereExists(fn ($q) => $q->from('activity_log')
                ->where('activity_log.log_name', 'report')
                ->where('activity_log.causer_type', $userClass)
                ->whereColumn('activity_log.causer_id', 'users.id'))->count(),
        ];
    }
}
