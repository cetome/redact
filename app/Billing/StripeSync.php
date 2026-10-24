<?php

namespace App\Billing;

use Laravel\Cashier\Http\Controllers\WebhookController;

/**
 * Cashier's own webhook handling, reused when the subscription must be stored before Stripe's
 * webhook arrives (the return from Checkout), so the two paths can never disagree.
 */
class StripeSync extends WebhookController
{
    /** @param  array<string, mixed>  $subscription  a Stripe subscription object */
    public function subscriptionCreated(array $subscription): void
    {
        $this->handleCustomerSubscriptionCreated(['data' => ['object' => $subscription]]);
    }
}
