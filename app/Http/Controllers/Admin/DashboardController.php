<?php

namespace App\Http\Controllers\Admin;

use App\Admin\DailySeries;
use App\Admin\DashboardStats;
use Illuminate\View\View;

class DashboardController extends AdminController
{
    public function __invoke(DashboardStats $stats): View
    {
        $series = $stats->series();

        return view('admin.dashboard', [
            'kpis' => $stats->kpis(),
            'sandboxes' => $stats->sandboxes(),
            'days' => DashboardStats::DAYS,
            'charts' => [
                'signups' => DailySeries::chart($series['signups'], __('admin.charts.signups'), 'bar'),
                'active' => DailySeries::chart($series['active'], __('admin.charts.active'), 'line'),
                'views' => DailySeries::chart($series['views'], __('admin.charts.views'), 'bar'),
                'ai' => DailySeries::chart($series['ai'], __('admin.charts.ai'), 'bar'),
            ],
        ]);
    }
}
