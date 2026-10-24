<?php

namespace App\Http\Controllers;

use App\Billing\Billing;
use App\Billing\Plan;
use App\Models\Organisation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * An organisation's plan and billing (organisation admins only, 404 for anyone else):
 * summary, next invoice and invoices from Stripe, Checkout for the first paid product,
 * one more product on the subscription (confirmed), the Stripe Customer Portal.
 */
class BillingController extends Controller
{
    public function show(Request $request, Organisation $organisation): View
    {
        $this->authorizeAdmin($request, $organisation);
        $plan = Plan::fresh($organisation);

        $upcoming = null;
        $invoices = new Collection;
        $stripeError = false;
        if (Plan::billingEnabled() && $organisation->hasStripeId()) {
            try {
                $upcoming = $plan->subscription?->valid() ? $organisation->upcomingInvoice() : null;
                $invoices = $organisation->invoices();
            } catch (Throwable $e) {
                report($e);
                $stripeError = true;
            }
        }

        return view('billing.show', [
            'organisation' => $organisation,
            'plan' => $plan,
            'products' => $organisation->products()->notDemo()->orderBy('created_at')->get(['id', 'name', 'plan_slot', 'created_at']),
            'upcoming' => $upcoming,
            'invoices' => $invoices,
            'stripeError' => $stripeError,
            'billingEnabled' => Plan::billingEnabled(),
        ]);
    }

    /** The first paid product: off to Stripe Checkout. */
    public function checkout(Request $request, Organisation $organisation, Billing $billing): Response
    {
        $this->authorizeAdmin($request, $organisation);
        $plan = Plan::fresh($organisation);
        abort_unless($plan->canBuy(), 404);
        if ($plan->hasActiveSubscription()) {
            return redirect()->route('products.create');
        }
        $this->consent($request, $organisation, $billing, 'checkout', 1);

        try {
            return $billing->checkout($organisation, $request->user())->redirect();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('products.create')->with('error', __('plans.billing.stripe_error'));
        }
    }

    public function success(Request $request, Organisation $organisation, Billing $billing): RedirectResponse
    {
        $this->authorizeAdmin($request, $organisation);

        try {
            $billing->completeCheckout($organisation, (string) ($request->route('session') ?? $request->query('session_id')), $request->user());
        } catch (Throwable $e) {
            // The webhook stores the subscription anyway: nothing is lost.
            report($e);
        }

        $plan = Plan::fresh($organisation);
        if ($plan->locked === [] && ! $plan->productLimitReached()) {
            return redirect()->route('products.create')->with('status', __('plans.billing.paid'));
        }

        return redirect()->route('billing.show', $organisation)
            ->with('status', $plan->locked === [] && $plan->subscription?->valid() ? __('plans.billing.reactivated') : __('plans.billing.pending'));
    }

    public function cancelled(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authorizeAdmin($request, $organisation);

        return redirect()->route('products.create')->with('status', __('plans.billing.cancelled'));
    }

    /** "This adds 100 €/month (prorated)": confirmed on New product, then one more unit. */
    public function addProduct(Request $request, Organisation $organisation, Billing $billing): RedirectResponse
    {
        $this->authorizeAdmin($request, $organisation);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('plans.billing.confirm_required')]);
        $plan = Plan::fresh($organisation);
        abort_unless($plan->canBuy() && $plan->hasActiveSubscription(), 404);

        if (! $plan->productLimitReached()) {
            return redirect()->route('products.create');
        }
        $this->consent($request, $organisation, $billing, 'add_product', 1);

        try {
            $billing->addProduct($organisation, $request->user());
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('products.create')->with('error', __('plans.billing.stripe_error'));
        }

        return redirect()->route('products.create')->with('status', __('plans.billing.added', ['price' => Plan::priceLabel()]));
    }

    /**
     * "Reactivate": pay again for the paid products the subscription no longer covers (one more
     * unit each on an active subscription, else a new Checkout for all of them).
     */
    public function reactivate(Request $request, Organisation $organisation, Billing $billing): Response
    {
        $this->authorizeAdmin($request, $organisation);
        $plan = Plan::fresh($organisation);
        $count = $plan->lockedPaidCount();
        abort_unless($plan->canBuy() && $count > 0, 404);
        if ($plan->hasActiveSubscription()) {
            $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('plans.billing.confirm_required')]);
        }
        $this->consent($request, $organisation, $billing, 'reactivate', $count);

        try {
            if ($plan->hasActiveSubscription()) {
                $billing->addProduct($organisation, $request->user(), $count);

                return redirect()->route('billing.show', $organisation)->with('status', __('plans.billing.reactivated'));
            }

            return $billing->checkout($organisation, $request->user(), $count)->redirect();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('billing.show', $organisation)->with('error', __('plans.billing.stripe_error'));
        }
    }

    /** Payment method, billing details, cancellation, invoices: Stripe's Customer Portal. */
    public function portal(Request $request, Organisation $organisation, Billing $billing): RedirectResponse
    {
        $this->authorizeAdmin($request, $organisation);
        abort_unless(Plan::billingEnabled() && $organisation->hasStripeId(), 404);

        try {
            $billing->log($organisation, $request->user(), 'billing portal opened');

            return $organisation->redirectToBillingPortal(route('billing.show', $organisation));
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', __('plans.billing.stripe_error'));
        }
    }

    /**
     * Every paid action needs the box "I ask for the service to start now..." (EU consumer
     * withdrawal right, terms "Consumers"): refused without it, recorded with it.
     */
    private function consent(Request $request, Organisation $organisation, Billing $billing, string $action, int $quantity): void
    {
        $request->validate(['withdrawal' => ['accepted']], ['withdrawal.accepted' => __('plans.consent.required')]);
        $billing->recordConsent($organisation, $request->user(), $action, $quantity, $request->ip());
    }

    private function authorizeAdmin(Request $request, Organisation $organisation): void
    {
        $user = $request->user();
        abort_if($user->is_guest, 404);
        abort_unless($organisation->isAdmin($user), 404);
    }
}
