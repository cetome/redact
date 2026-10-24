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
 * Stripe, through Laravel Cashier: one subscription per organisation ("products"), one unit
 * per paid product (price STRIPE_PRICE_PRODUCT_MONTHLY). No Stripe.js: Checkout and the
 * Customer Portal are redirects, so the CSP is unchanged. Every change is logged ("billing").
 */
class Billing
{
    public function type(): string
    {
        return (string) config('redact.billing.subscription', 'products');
    }

    public function price(): string
    {
        return (string) config('redact.billing.price');
    }

    public function subscription(Organisation $organisation): ?Subscription
    {
        return Plan::fresh($organisation)->subscription;
    }

    /**
     * Before any paid action: the person asked for the service to start at once and acknowledged
     * the withdrawal rules (terms, "Consumers"). Stored as proof (billing_consents) with the terms
     * version and a keyed hash of the IP address.
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
     * The first paid product: a Stripe Checkout session (subscription, quantity 1, Stripe Tax,
     * billing address and tax ID for EU B2B VAT). Back on New product once paid.
     */
    public function checkout(Organisation $organisation, User $by, int $quantity = 1): Checkout
    {
        $checkout = $organisation->newSubscription($this->type(), $this->price())
            ->quantity(max(1, $quantity))
            ->checkout([
                // Stripe replaces {CHECKOUT_SESSION_ID} anywhere in the address: in the path, not URL-encoded.
                'success_url' => str_replace('/CHECKOUT_SESSION_ID', '/{CHECKOUT_SESSION_ID}', route('billing.success', [$organisation, 'CHECKOUT_SESSION_ID'])),
                'cancel_url' => route('billing.cancelled', $organisation),
                'billing_address_collection' => 'required',
                'tax_id_collection' => ['enabled' => true],
                'customer_update' => ['address' => 'auto', 'name' => 'auto'],
                'locale' => Locales::detail('stripe'),
            ]);

        $this->log($organisation, $by, 'checkout started', ['quantity' => max(1, $quantity)]);

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

        return true;
    }

    /** One more paid product on the existing subscription (prorated on the next invoice). */
    public function addProduct(Organisation $organisation, User $by, int $count = 1): Subscription
    {
        $subscription = $this->subscription($organisation);
        abort_unless($subscription !== null && $subscription->active(), 404);

        $subscription->incrementQuantity(max(1, $count));
        $this->log($organisation, $by, $count > 1 ? 'products reactivated' : 'product added', ['quantity' => $subscription->quantity]);

        return $subscription;
    }

    /**
     * One paid product fewer (a deleted paid product): the quantity goes down, or the subscription
     * ends at the close of the period when it was the last one. Data is never touched here.
     */
    public function removeProduct(Organisation $organisation, User $by): void
    {
        $subscription = $this->subscription($organisation);
        if ($subscription === null || ! $subscription->valid() || $subscription->onGracePeriod()) {
            return;
        }

        if ((int) $subscription->quantity > 1) {
            $subscription->decrementQuantity();
            $this->log($organisation, $by, 'product removed', ['quantity' => $subscription->quantity]);
        } else {
            $subscription->cancel();
            $this->log($organisation, $by, 'subscription cancelled', ['ends_at' => $subscription->ends_at?->toIso8601String()]);
        }
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
