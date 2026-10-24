<?php

namespace App\Http\Controllers;

use App\Billing\Plan;
use App\Guest\DemoProduct;
use App\Guest\Sandbox;
use App\Models\Invitation;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $adminOrgs = $user->organisations()->wherePivot('role', 'admin')->pluck('organisations.id');

        $products = Product::query()
            ->where(function (Builder $q) use ($user, $adminOrgs) {
                $q->where('owner_id', $user->id)
                    ->orWhereIn('organisation_id', $adminOrgs)
                    ->orWhereHas('members', fn (Builder $m) => $m->where('user_id', $user->id)
                        ->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now())));
            })
            ->with(['organisation', 'owner', 'versions' => fn ($q) => $q->limit(1)])
            ->orderByDesc('updated_at')
            ->get();

        $pendingInvitations = Invitation::query()
            ->where('email', $user->email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            // An organisation invitation stands for the products offered with it (its children).
            ->whereNull('parent_id')
            ->with(['product', 'organisation'])
            ->get();

        $organisations = $user->organisations()->get();
        // The plan of each organisation (App\Billing\Plan); a guest sandbox keeps its own rules.
        $plans = $user->is_guest ? collect() : $organisations->map(fn ($o) => Plan::for($o));

        // "Load the demo" / "Open the demo" for an account (sandbox guests use /try/demo): the demo of
        // its organisation (DemoProduct::organisationOf()), when the content pack ships one.
        $demoOrganisation = ! $user->is_guest && app(DemoProduct::class)->hasData() ? DemoProduct::organisationOf($user) : null;

        return view('dashboard', [
            'demo' => $demoOrganisation ? ['product' => DemoProduct::of($demoOrganisation)] : null,
            'products' => $products,
            'invitations' => $pendingInvitations,
            'canCreate' => $organisations->isNotEmpty() && ! Sandbox::productLimitReached($user),
            // No product left on any plan: the "New product" button becomes the upgrade hint.
            'atPlanLimit' => $plans->isNotEmpty() && $plans->every(fn (Plan $p) => $p->productLimitReached()),
            'plans' => $plans,
            'organisations' => $organisations,
        ]);
    }
}
