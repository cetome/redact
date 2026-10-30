<?php

namespace App\Http\Controllers\Admin;

use App\Admin\DailySeries;
use App\Admin\DashboardStats;
use App\Admin\UsageAnalytics;
use App\Admin\WorkActivity;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends AdminController
{
    /** /admin (30 days) or /admin/overview/{days} (routes/admin.php: one of UsageAnalytics::PERIODS). */
    public function __invoke(Request $request, DashboardStats $stats, int $days = 30): View
    {
        $work = WorkActivity::for($days);
        $series = $stats->series($work->days());

        return view('admin.dashboard', [
            'kpis' => $stats->kpis(),
            'sandboxes' => $stats->sandboxes(),
            'days' => $work->days(),
            'periods' => UsageAnalytics::PERIODS,
            'work' => [
                'totals' => $work->totals(),
                'chart' => $work->chart(),
                'people' => $work->people($request->user()),
                'products' => $work->products(),
                'latest' => $work->latest($request->user()),
            ],
            'charts' => [
                'signups' => DailySeries::chart($series['signups'], __('admin.charts.signups'), 'bar'),
                'ai' => DailySeries::chart($series['ai'], __('admin.charts.ai'), 'bar'),
            ],
        ]);
    }
}
