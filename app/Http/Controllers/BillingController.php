<?php

namespace App\Http\Controllers;

use App\Auth\SignIn;
use App\Billing\Billing;
use App\Billing\Catalogue;
use App\Billing\Plan;
use App\Models\Organisation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * An organisation's plan and billing (organisation admins only, 404 for anyone else):
 * summary, next invoice and invoices from Stripe, the subscribe page (FAST's /subscribe: monthly or
 * yearly, how many products, then Stripe Checkout), one more product on the subscription
 * (confirmed), the Stripe Customer Portal.
 */
class BillingController extends Controller
{
    /** The most products the subscribe page offers (more: "Talk to us", Plan::MAX_ONLINE_PRODUCTS). */
    public const MAX_PRODUCTS = Plan::MAX_ONLINE_PRODUCTS;

    /**
     * /subscribe (Premium on the pricing page, Upgrade in the app bar): the subscribe page of the
     * first organisation the person administers. A sandbox guest keeps their work first.
     */
    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->is_guest) {
            return redirect()->route('guest.claim');
        }
        $organisation = $user->organisations()->get()->first(fn (Organisation $o) => $o->isAdmin($user));
        if ($organisation === null) {
            return redirect()->route('products.create');
        }

        return redirect()->route('billing.subscribe', $organisation);
    }

    /** "Plan and billing" in the account menu: the billing page of the organisation the person pays for. */
    public function mine(Request $request): RedirectResponse
    {
        $organisation = Plan::billingOrganisation($request->user());
        abort_if($organisation === null, 404);

        return redirect()->route('billing.show', $organisation);
    }

    /**
     * The plan, monthly or yearly, and how many products (FAST's /subscribe), with the total, then
     * Stripe Checkout (checkout). An active subscription adds products one at a time instead (New
     * product), and without online payment it is "Talk to us".
     */
    public function subscribe(Request $request, Organisation $organisation, Catalogue $catalogue): View|RedirectResponse
    {
        $this->authorizeAdmin($request, $organisation);
        $plan = Plan::fresh($organisation);
        $definition = $catalogue->featured();
        if (! Plan::billingEnabled() || $definition === null) {
            return redirect()->away(Plan::contactUrl('pricing'));
        }
        if ($plan->hasActiveSubscription() || ! $plan->canBuy()) {
            return redirect()->route('billing.show', $organisation);
        }

        $free = (int) config('redact.plans.free.products', 1);
        $included = $definition->products;
        $options = [];
        foreach ($catalogue->intervals($definition->key) as $interval) {
            $options[$interval] = [
                'plan' => $catalogue->label($definition->key, Catalogue::ITEM_PLAN, $interval),
                'extra' => $catalogue->label($definition->key, Catalogue::ITEM_EXTRA, $interval),
                'planCents' => $catalogue->amount($definition->key, Catalogue::ITEM_PLAN, $interval),
                'extraCents' => $catalogue->amount($definition->key, Catalogue::ITEM_EXTRA, $interval),
            ];
        }
        abort_if($options === [], 404);

        return view('billing.subscribe', [
            'organisation' => $organisation,
            'plan' => $plan,
            'definition' => $definition,
            'options' => $options,
            'included' => $included,
            'free' => $free,
            // Products the organisation already has: the total starts there (never below what is included).
            'used' => $plan->freeUsed + $plan->paidUsed + $plan->grantedUsed,
            'max' => max(self::MAX_PRODUCTS, (int) $included),
            'currency' => strtoupper((string) config('cashier.currency', 'eur')),
        ]);
    }

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

    /** A plan, monthly or yearly: off to Stripe Checkout. */
    public function checkout(Request $request, Organisation $organisation, Billing $billing, Catalogue $catalogue): Response
    {
        $this->authorizeAdmin($request, $organisation);
        $plan = Plan::fresh($organisation);
        abort_unless($plan->canBuy(), 404);
        if ($plan->hasActiveSubscription()) {
            return redirect()->route('products.create');
        }
        [$chosen, $interval] = $this->choice($request, $catalogue);
        // The products asked for on the subscribe page (all of them, the free one counted): the paid
        // slots are the ones beyond the free product. Without it: one paid slot.
        $data = $request->validate([
            'extra' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PRODUCTS],
            'products' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PRODUCTS],
        ]);
        $included = (int) ($catalogue->plan($chosen)->products ?? 1);
        $total = isset($data['extra']) ? $included + (int) $data['extra'] : (int) ($data['products'] ?? 0);
        if ($total > self::MAX_PRODUCTS) {
            return back()->withInput()->withErrors(['extra' => __('plans.add.maximum', ['max' => self::MAX_PRODUCTS])]);
        }
        $quantity = max(1, $total - (int) config('redact.plans.free.products', 1));
        $this->consent($request, $organisation, $billing, 'checkout', $quantity);

        try {
            return $billing->checkout($organisation, $request->user(), $quantity, $chosen, $interval)->redirect();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('billing.subscribe', $organisation)->withInput()->with('error', __('plans.billing.stripe_error'));
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
        [$next, $status] = $plan->locked === [] && ! $plan->productLimitReached()
            ? [route('products.create'), __('plans.billing.paid')]
            : [route('billing.show', $organisation), $plan->locked === [] && $plan->subscription?->valid() ? __('plans.billing.reactivated') : __('plans.billing.pending')];

        // Signed up to buy (SignIn::homeAfterEmail): the passkey now that the payment is done, then on.
        if ($request->session()->get(SignIn::SUGGEST_PASSKEY) && ! $request->user()->passkeys()->exists()) {
            $request->session()->put('url.intended', $next);

            return redirect()->route('auth.passkey.suggest')->with('status', $status);
        }

        return redirect()->to($next)->with('status', $status);
    }

    /** Back from Checkout without paying: the subscribe page again, the choices kept. */
    public function cancelled(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authorizeAdmin($request, $organisation);

        return redirect()->route('billing.subscribe', $organisation)->with('status', __('plans.billing.cancelled'));
    }

    /** "This adds 100 €/month (prorated)": confirmed on New product, then one more unit. */
    public function addProduct(Request $request, Organisation $organisation, Billing $billing): RedirectResponse
    {
        $this->authorizeAdmin($request, $organisation);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('plans.billing.confirm_required')]);
        $plan = Plan::fresh($organisation);
        abort_unless($plan->canBuy() && $plan->hasActiveSubscription(), 404);

        // Not needed yet, or the most sold online already (New product then says "Talk to us").
        if (! $plan->productLimitReached() || $plan->onlineMaximumReached()) {
            return redirect()->route('products.create');
        }
        $this->consent($request, $organisation, $billing, 'add_product', 1);

        try {
            $billing->addProduct($organisation, $request->user());
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('products.create')->with('error', __('plans.billing.stripe_error'));
        }

        $plan = Plan::fresh($organisation);

        return redirect()->route('products.create')->with('status', __('plans.billing.added', ['price' => $plan->extraPriceLabel(), 'per' => $plan->per()]));
    }

    /**
     * The number of products on the subscription (billing page, as FAST): from what is in use (or what
     * the plan includes) up to the most sold online. Adding needs the immediate-start consent.
     */
    public function setProducts(Request $request, Organisation $organisation, Billing $billing): RedirectResponse
    {
        $this->authorizeAdmin($request, $organisation);
        $plan = Plan::fresh($organisation);
        abort_unless($plan->canBuy() && $plan->hasActiveSubscription() && ! $plan->subscription?->onGracePeriod(), 404);
        $current = $plan->freeSlots + $plan->paidSlots;
        // "Add N" (the counter). Never fewer from here (owner 2026-11-05): cancelling is in the portal.
        $data = $request->validate([
            'add' => ['required', 'integer', 'min:1', 'max:'.max(0, self::MAX_PRODUCTS - $current)],
        ]);
        $total = $current + (int) $data['add'];
        $this->consent($request, $organisation, $billing, 'add_product', $total - $current);

        try {
            $billing->setProducts($organisation, $request->user(), $total);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('billing.show', $organisation)->with('error', __('plans.billing.stripe_error'));
        }

        return redirect()->route('billing.show', $organisation)->with('status', __('plans.billing.products_saved', ['count' => $total]));
    }

    /**
     * "Reactivate": pay again for the paid products the subscription no longer covers (one more
     * unit each on an active subscription, else a new Checkout for all of them).
     */
    public function reactivate(Request $request, Organisation $organisation, Billing $billing, Catalogue $catalogue): Response
    {
        $this->authorizeAdmin($request, $organisation);
        $plan = Plan::fresh($organisation);
        $count = $plan->lockedPaidCount();
        abort_unless($plan->canBuy() && $count > 0, 404);
        // Never past what is sold online (owner: 11 products, then "Talk to us").
        if ($plan->freeSlots + $plan->paidSlots + $count > self::MAX_PRODUCTS) {
            return redirect()->route('billing.show', $organisation)->with('error', __('plans.add.maximum', ['max' => self::MAX_PRODUCTS]));
        }
        if ($plan->hasActiveSubscription()) {
            $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('plans.billing.confirm_required')]);
        }
        $this->consent($request, $organisation, $billing, 'reactivate', $count);

        try {
            if ($plan->hasActiveSubscription()) {
                $billing->addProduct($organisation, $request->user(), $count);

                return redirect()->route('billing.show', $organisation)->with('status', __('plans.billing.reactivated'));
            }

            [$chosen, $interval] = $this->choice($request, $catalogue);

            return $billing->checkout($organisation, $request->user(), $count, $chosen, $interval)->redirect();
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
     * Every paid action shows the line "REDact is sold to businesses only: by paying, you confirm you
     * subscribe for your business" (billing._business, terms "Businesses only"). No box to tick
     * (owner 2026-11-05): paying is the confirmation, recorded as proof.
     */
    private function consent(Request $request, Organisation $organisation, Billing $billing, string $action, int $quantity): void
    {
        $billing->recordConsent($organisation, $request->user(), $action, $quantity, $request->ip());
    }

    /**
     * The plan and interval asked for ("offer" = "plan:interval", billing._choice): a plan offered
     * online, on an interval it has a price for. Without one: the first offer (the featured plan,
     * monthly when it can be).
     *
     * @return array{0: string, 1: string}
     */
    private function choice(Request $request, Catalogue $catalogue): array
    {
        $offers = [];
        foreach ($catalogue->offered() as $plan) {
            foreach ($catalogue->intervals($plan->key) as $interval) {
                $offers[] = $plan->key.':'.$interval;
            }
        }
        $data = $request->validate(['offer' => ['nullable', Rule::in($offers)]], ['offer.in' => __('plans.add.choose_required')]);
        $featured = $catalogue->featured()?->key;
        $default = collect($offers)->first(fn ($o) => $o === $featured.':month') ?? collect($offers)->first(fn ($o) => str_starts_with($o, $featured.':')) ?? ($offers[0] ?? null);
        $offer = (string) ($data['offer'] ?? $default);
        abort_if($offer === '', 404);

        return explode(':', $offer, 2);
    }

    private function authorizeAdmin(Request $request, Organisation $organisation): void
    {
        $user = $request->user();
        abort_if($user->is_guest, 404);
        abort_unless($organisation->isAdmin($user), 404);
    }
}
