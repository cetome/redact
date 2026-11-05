<?php

namespace App\Billing;

use App\Http\Middleware\LogRequest;
use App\Models\BillingConsent;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Locales;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\Subscription;

/**
 * Stripe, through Laravel Cashier: one subscription per organisation ("products"): the plan's
 * price (App\Billing\Catalogue, monthly or yearly) and the add-on "Additional product", one unit
 * per product beyond what the plan includes (or more units of the plan price when the plan has
 * no add-on price). No Stripe.js: Checkout and the Customer Portal are redirects, so the CSP is
 * unchanged. Every change is logged ("billing").
 */
class Billing
{
    public function type(): string
    {
        return (string) config('redact.billing.subscription', 'products');
    }

    public function __construct(private readonly Catalogue $catalogue) {}

    public function subscription(Organisation $organisation): ?Subscription
    {
        return Plan::fresh($organisation)->subscription;
    }

    /**
     * Before any paid action: the person confirmed they subscribe for their business, not as a
     * consumer (terms, "Businesses only"). Stored as proof (billing_consents) with the terms version
     * and a keyed hash of the IP address.
     */
    public function recordConsent(Organisation $organisation, User $by, string $action, int $quantity, ?string $ip): BillingConsent
    {
        return BillingConsent::query()->create([
            'organisation_id' => $organisation->getKey(),
            'user_id' => $by->getKey(),
            'action' => $action,
            'quantity' => max(1, $quantity),
            'terms_version' => (string) config('redact.auth.terms_version'),
            'ip_hash' => LogRequest::hashIp($ip),
        ]);
    }

    /**
     * A plan, on $interval, for $quantity paid products: a Stripe Checkout session (Stripe Tax,
     * billing address and tax ID for EU B2B VAT). Products beyond what the plan includes are
     * add-on units. Back on New product once paid.
     */
    public function checkout(Organisation $organisation, User $by, int $quantity = 1, ?string $plan = null, string $interval = 'month'): Checkout
    {
        $definition = $this->catalogue->plan($plan) ?? $this->catalogue->featured();
        $planPrice = $definition !== null ? $this->catalogue->price($definition->key, Catalogue::ITEM_PLAN, $interval) : null;
        abort_if($definition === null || $planPrice === null, 404);
        $extra = $definition->unlimited() ? 0 : max(0, $quantity - $definition->slotsBeyond((int) config('redact.plans.free.products', 1)));
        $extraPrice = $this->catalogue->price($definition->key, Catalogue::ITEM_EXTRA, $interval);

        $builder = $organisation->newSubscription($this->type(), $extra > 0 && $extraPrice !== null ? [$planPrice, $extraPrice] : [$planPrice]);
        if ($extra > 0 && $extraPrice !== null) {
            $builder->quantity(1, $planPrice)->quantity($extra, $extraPrice);
        } else {
            // Without an add-on price, each additional product is one more unit of the plan.
            $builder->quantity(1 + $extra);
        }
        $checkout = $builder->withMetadata(['redact_plan' => $definition->key, 'redact_interval' => $interval])
            ->checkout([
                // Stripe replaces {CHECKOUT_SESSION_ID} anywhere in the address: in the path, not URL-encoded.
                'success_url' => str_replace('/CHECKOUT_SESSION_ID', '/{CHECKOUT_SESSION_ID}', route('billing.success', [$organisation, 'CHECKOUT_SESSION_ID'])),
                'cancel_url' => route('billing.cancelled', $organisation),
                'billing_address_collection' => 'required',
                'tax_id_collection' => ['enabled' => true],
                'customer_update' => ['address' => 'auto', 'name' => 'auto'],
                'locale' => Locales::detail('stripe'),
            ]);

        $this->log($organisation, $by, 'checkout started', ['plan' => $definition->key, 'interval' => $interval, 'quantity' => max(1, $quantity)]);

        return $checkout;
    }

    /**
     * Back from Checkout: the subscription may be there before Stripe's webhook, so it is read
     * from the session (only when it belongs to this organisation's customer).
     */
    public function completeCheckout(Organisation $organisation, string $sessionId, User $by): bool
    {
        if (! $organisation->hasStripeId() || ! preg_match('/^cs_[A-Za-z0-9_]{1,250}$/', $sessionId)) {
            return false;
        }

        $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId, ['expand' => ['subscription']]);
        if ($session->customer !== $organisation->stripe_id || $session->status !== 'complete' || ! is_object($session->subscription)) {
            return false;
        }

        (new StripeSync)->subscriptionCreated($session->subscription->toArray());
        $this->log($organisation, $by, 'subscription started', ['subscription' => $session->subscription->id]);
        $this->adoptNames($organisation, $session->customer_details?->toArray() ?? [], $by);

        return true;
    }

    /**
     * The names typed in Checkout fill the ones REDact does not have yet (App\Billing\StripeNames).
     *
     * @param  array<string, mixed>  $details  the session's customer_details
     */
    public function adoptNames(Organisation $organisation, array $details, ?User $payer): void
    {
        $changed = app(StripeNames::class)->adopt($organisation, $details, $payer);
        if ($changed !== []) {
            $this->log($organisation, $payer, 'names from Stripe', $changed);
        }
    }

    /**
     * More paid products on the existing subscription (prorated on the next invoice): units of
     * its add-on item (even on an older add-on price), else the plan's current add-on price on
     * the subscription's interval, else more units of the plan price.
     */
    public function addProduct(Organisation $organisation, User $by, int $count = 1): Subscription
    {
        $plan = Plan::fresh($organisation);
        $subscription = $plan->subscription;
        abort_unless($subscription !== null && $subscription->active(), 404);
        $count = max(1, $count);

        $extra = $this->item($subscription, Catalogue::ITEM_EXTRA);
        $price = $this->catalogue->price($plan->key(), Catalogue::ITEM_EXTRA, $plan->interval ?? 'month');
        if ($extra !== null) {
            $subscription->incrementQuantity($count, $extra);
        } elseif ($price !== null) {
            $subscription->addPrice($price, $count);
        } else {
            $subscription->incrementQuantity($count, $this->single($subscription));
        }
        $this->log($organisation, $by, $count > 1 ? 'products reactivated' : 'product added', ['count' => $count, 'items' => $this->items($subscription)]);

        return $subscription;
    }

    /**
     * The number of products the subscription pays for, the free one counted (billing page, as FAST):
     * add-on units added until it matches. Only ever up (owner 2026-11-05): a total at or below what
     * is paid for changes nothing, whatever the caller sends. Cancelling is on Stripe's portal.
     * Stripe prorates the addition on the next invoice.
     */
    public function setProducts(Organisation $organisation, User $by, int $total): void
    {
        $plan = Plan::fresh($organisation);
        $subscription = $plan->subscription;
        abort_unless($subscription !== null && $subscription->active() && ! $subscription->onGracePeriod(), 404);
        $target = $total - (int) config('redact.plans.free.products', 1);

        if ($target > $plan->paidSlots) {
            $this->addProduct($organisation, $by, $target - $plan->paidSlots);
        }
    }

    /** The Stripe price of the subscription's item for $item (plan or add-on), null without one. */
    private function item(Subscription $subscription, string $item): ?string
    {
        $prices = $subscription->items->isNotEmpty() ? $subscription->items->pluck('stripe_price')->all() : [$subscription->stripe_price];
        foreach ($prices as $price) {
            if ($this->catalogue->describe((string) $price)['item'] === $item) {
                return (string) $price;
            }
        }

        return null;
    }

    /** The plan item's price on a subscription with several items, null on a one-item subscription (Cashier's own). */
    private function single(Subscription $subscription): ?string
    {
        return $subscription->items->count() > 1 ? $this->item($subscription, Catalogue::ITEM_PLAN) : null;
    }

    /** @return array<string, int> Stripe price => quantity, for the log */
    private function items(Subscription $subscription): array
    {
        return $subscription->items->isNotEmpty()
            ? $subscription->items->pluck('quantity', 'stripe_price')->map(fn ($quantity) => (int) $quantity)->all()
            : [(string) $subscription->stripe_price => (int) $subscription->quantity];
    }

    /** @param  array<string, mixed>  $properties */
    public function log(Organisation $organisation, ?User $by, string $description, array $properties = []): void
    {
        $activity = activity('billing')->performedOn($organisation)->event(str_replace(' ', '_', $description));
        if ($by !== null) {
            $activity->causedBy($by);
        }
        $activity->withProperties($properties)->log($description);
    }
}
