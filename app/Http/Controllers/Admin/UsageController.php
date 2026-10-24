<?php

namespace App\Http\Controllers\Admin;

use App\Admin\DailySeries;
use App\Admin\UsageAnalytics;
use Illuminate\View\View;

class UsageController extends AdminController
{
    /** /admin/usage (30 days) or /admin/usage/{days} (routes/admin.php: one of UsageAnalytics::PERIODS). */
    public function index(int $days = 30): View
    {
        $usage = UsageAnalytics::for($days);

        $views = $usage->pageViewsPerDay();
        $users = $usage->uniqueUsersPerDay();

        return view('admin.usage.index', [
            'days' => $usage->days(),
            'periods' => UsageAnalytics::PERIODS,
            'totals' => $usage->totals(),
            'routes' => $usage->topRoutes(),
            'charts' => [
                'views' => DailySeries::chart($views, __('admin.charts.views'), 'bar'),
                'users' => DailySeries::chart($users, __('admin.charts.unique_users'), 'line'),
            ],
            'weeks' => $usage->uniqueUsersPerWeek(),
            'locales' => $usage->locales(),
            'ai' => $usage->aiUsage(),
            'signIns' => $usage->signInMethods(),
            'templates' => $usage->templateDownloads(),
            'funnel' => $usage->funnel(),
            'audience' => $usage->pageViewsByAudience(),
        ]);
    }
}
