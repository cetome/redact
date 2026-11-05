<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Catalogue;
use App\Billing\Plan;
use App\Billing\ProratedRefund;
use App\Billing\SubscriptionEnder;
use App\Models\Organisation;
use App\Support\PathPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Customer organisations, their plan and billing state. Staff set the plan (Free, or any plan of
 * the catalogue: a contract without an end date, a trial without a card until a date, which they
 * can end at any time) and per-organisation overrides (e.g. extra products granted to a partner):
 * logged in the console log with the before and after values. No payment happens in the plan form.
 * A super-admin can end a Stripe subscription now, with or without refunding the unused part of
 * the period (owner 2026-11-05), the account and its work kept: the organisation is back on Free.
 * Search in the session (POST …/filter), pages /admin/organisations/page/{n}.
 */
class OrganisationController extends AdminController
{
    private const FILTERS = 'admin.organisations';

    private const RULES = ['q' => ['nullable', 'string', 'max:120']];

    public function index(Request $request, int $page = 1): View
    {
        $filters = $this->rememberedFilters($request, self::FILTERS, self::RULES);
        $term = trim((string) ($filters['q'] ?? ''));

        $organisations = Organisation::query()
            ->withCount(['products', 'members'])
            ->with(['subscriptions'])
            ->when($term !== '', function (Builder $query) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('name', 'like', $like)
                    ->orWhereHas('members', fn (Builder $m) => $m->where('email', 'like', $like)));
            })
            ->orderBy('name');

        return view('admin.organisations.index', [
            'organisations' => PathPaginator::of($organisations, 25, $page, route('admin.organisations.index')),
            'filters' => ['q' => $filters['q']],
        ]);
    }

    public function filter(Request $request): RedirectResponse
    {
        return $this->rememberFilters($request, self::FILTERS, self::RULES, route('admin.organisations.index'));
    }

    public function show(Request $request, Organisation $organisation, ProratedRefund $refunds, SubscriptionEnder $ender): View
    {
        $organisation->load(['members']);
        $plan = Plan::fresh($organisation);

        // End the subscription (super-admin): shown while REDact or Stripe still has one running,
        // with what a refund would give back, read from Stripe.
        $end = null;
        if ($this->isSuperAdmin($request) && ($plan->subscription?->valid() || $organisation->hasStripeId())) {
            $local = $plan->subscription !== null && $plan->subscription->valid() && ! $plan->subscription->ended();
            try {
                $end = $local || $ender->running($organisation) !== [] ? ['refund' => $refunds->describe($organisation), 'stripeError' => false] : null;
            } catch (Throwable $e) {
                report($e);
                $end = $local ? ['refund' => null, 'stripeError' => true] : null;
            }
        }

        return view('admin.organisations.show', [
            'end' => $end,
            'organisation' => $organisation,
            'plan' => $plan,
            'plans' => app(Catalogue::class)->plans(),
            'products' => $organisation->products()->withTrashed()->with('owner:id,name,email')->orderBy('created_at')->get(),
            'billingEnabled' => Plan::billingEnabled(),
        ]);
    }

    public function update(Request $request, Organisation $organisation, Catalogue $catalogue): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(array_merge([Plan::FREE], array_keys($catalogue->plans())))],
            'plan_ends_at' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'extra_products' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'team_members' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'ai_units' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'ai_period' => ['nullable', Rule::in(Plan::PERIODS)],
            'support' => ['nullable', Rule::in(['yes', 'no'])],
        ]);

        $limits = array_filter([
            'extra_products' => isset($data['extra_products']) ? (int) $data['extra_products'] : null,
            'team_members' => isset($data['team_members']) ? (int) $data['team_members'] : null,
            'ai_units' => isset($data['ai_units']) ? (int) $data['ai_units'] : null,
            'ai_period' => $data['ai_period'] ?? null,
            'support' => isset($data['support']) ? $data['support'] === 'yes' : null,
        ], fn ($v) => $v !== null);

        // A trial ends at the end of the chosen day; Free has no end date.
        $endsAt = $data['plan'] !== Plan::FREE && ! empty($data['plan_ends_at']) ? Carbon::parse($data['plan_ends_at'])->endOfDay() : null;

        $before = $this->state($organisation);
        $organisation->forceFill(['plan' => $data['plan'], 'plan_ends_at' => $endsAt, 'limits' => $limits === [] ? null : $limits])->save();
        $after = $this->state($organisation);

        if ($before != $after) {
            activity('admin')
                ->causedBy($this->staffUser($request))
                ->performedOn($organisation)
                ->event('plan_changed')
                ->withProperties(['old' => $before, 'attributes' => $after, 'organisation' => $organisation->name])
                ->log('plan changed');
        }

        return redirect()->route('admin.organisations.show', $organisation)->with('status', __('plans.admin.saved'));
    }

    /** End a trial now: back to Free (paid products then follow the subscription, if any). */
    public function endTrial(Request $request, Organisation $organisation): RedirectResponse
    {
        abort_if($organisation->plan === Plan::FREE || $organisation->plan_ends_at === null, 404);

        $before = $this->state($organisation);
        $organisation->forceFill(['plan' => Plan::FREE, 'plan_ends_at' => null])->save();
        activity('admin')
            ->causedBy($this->staffUser($request))
            ->performedOn($organisation)
            ->event('trial_ended')
            ->withProperties(['old' => $before, 'attributes' => $this->state($organisation), 'organisation' => $organisation->name])
            ->log('trial ended');

        return redirect()->route('admin.organisations.show', $organisation)->with('status', __('plans.admin.trial_ended'));
    }

    /**
     * Ends the Stripe subscription now (super-admin): refund = 1 refunds the unused part of the period
     * first (App\Billing\ProratedRefund). The customer, the account and its work stay: the
     * organisation is back on Free, products beyond it read-only, never deleted.
     */
    public function endSubscription(Request $request, Organisation $organisation, SubscriptionEnder $ender): RedirectResponse
    {
        $this->requireSuperAdmin($request);
        $data = $request->validate(['refund' => ['required', Rule::in(['0', '1'])]]);
        abort_unless($organisation->hasStripeId() || $organisation->subscriptions()->exists(), 404);

        try {
            $ended = $ender->end($organisation, $this->staffUser($request), $data['refund'] === '1');
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', __('plans.admin.end.failed', ['error' => $e->getMessage()]));
        }
        $amount = $ended['refund'];

        activity('admin')
            ->causedBy($this->staffUser($request))
            ->performedOn($organisation)
            ->event('subscription_ended')
            ->withProperties(['organisation' => $organisation->name, 'subscriptions' => $ended['cancelled'], 'refund' => $amount])
            ->log('subscription ended');

        return redirect()->route('admin.organisations.show', $organisation)->with('status', $amount !== null
            ? __('plans.admin.end.done_refund', ['amount' => $amount])
            : __('plans.admin.end.done'));
    }

    /** @return array<string, mixed> */
    private function state(Organisation $organisation): array
    {
        return ['plan' => $organisation->plan, 'plan_ends_at' => $organisation->plan_ends_at?->toDateString(), 'limits' => $organisation->limits];
    }
}
