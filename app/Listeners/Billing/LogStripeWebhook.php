<?php

namespace App\Listeners\Billing;

use App\Billing\Billing;
use App\Models\Organisation;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Stripe told us something about a subscription (Cashier's webhook, signature checked by
 * Cashier): log it on the organisation. Cashier itself stores the new status and quantity;
 * App\Billing\Plan reads them, so a cancelled or unpaid subscription makes the paid products
 * read-only (never deleted) and a reactivated one makes them editable again.
 */
class LogStripeWebhook
{
    /** Stripe events worth a line in the organisation's billing log. */
    private const EVENTS = [
        'customer.subscription.created' => 'subscription started',
        'customer.subscription.updated' => 'subscription updated',
        'customer.subscription.deleted' => 'subscription ended',
        'invoice.payment_failed' => 'payment failed',
        'invoice.payment_succeeded' => 'payment succeeded',
    ];

    public function __construct(private readonly Billing $billing) {}

    public function handle(WebhookReceived $event): void
    {
        $type = (string) ($event->payload['type'] ?? '');
        $object = $event->payload['data']['object'] ?? [];
        if (! isset(self::EVENTS[$type]) || ! is_array($object) || ! is_string($object['customer'] ?? null)) {
            return;
        }

        // The Stripe customer is the organisation (Cashier::useCustomerModel).
        $organisation = Organisation::query()->where('stripe_id', $object['customer'])->first();
        if ($organisation === null) {
            return;
        }

        $this->billing->log($organisation, null, self::EVENTS[$type], array_filter([
            'stripe_event' => $event->payload['id'] ?? null,
            'status' => $object['status'] ?? null,
            'quantity' => $object['items']['data'][0]['quantity'] ?? null,
        ], fn ($v) => $v !== null));
    }
}
