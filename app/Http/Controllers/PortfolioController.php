<?php

namespace App\Http\Controllers;

use App\Admin\UsageAnalytics;
use App\Admin\WorkActivity;
use App\Billing\Plan;
use App\Models\Organisation;
use App\Portfolio\Portfolio;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * An organisation's portfolio (its admins): every product and version and where each stands
 * (App\Portfolio\Portfolio), then who does what over 7, 30 or 90 days (App\Admin\WorkActivity
 * limited to the organisation). Paid plans: on the free plan the page says so and links to Plan
 * and billing.
 */
class PortfolioController extends Controller
{
    public function show(Request $request, Organisation $organisation, int $days = 30): View
    {
        $user = $request->user();
        abort_if($user->is_guest, 404);
        abort_unless($organisation->isAdmin($user), 404);

        $available = Portfolio::available($organisation);
        $portfolio = new Portfolio($organisation);
        $work = WorkActivity::for($days, $organisation);

        return view('portfolio.show', [
            'organisation' => $organisation,
            'organisations' => $user->organisations()->wherePivot('role', 'admin')->get(),
            'available' => $available,
            'upgradeUrl' => $available ? null : (Plan::billingEnabled() ? route('billing.show', $organisation) : Plan::contactUrl()),
            'days' => $work->days(),
            'periods' => UsageAnalytics::PERIODS,
            'products' => $available ? $portfolio->products() : [],
            'totals' => $available ? $portfolio->totals() : null,
            'work' => $available ? [
                'totals' => $work->totals(),
                'chart' => $work->chart(),
                'people' => $work->people($user),
                'latest' => $work->latest($user),
            ] : null,
        ]);
    }
}
