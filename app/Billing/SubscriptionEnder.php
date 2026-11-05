<?php

namespace App\Billing;

use App\Models\Organisation;
use App\Models\User;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Stripe\Exception\InvalidRequestException;

/**
 * Staff end an organisation's subscriptions now (console, Admin\OrganisationController), the
 * customer and the account kept: back to Free. Every subscription Stripe still runs for the
 * customer is cancelled (two can exist, e.g. paid twice in the sandbox), not only the one REDact
 * holds, so the customer portal shows none afterwards. Without proration: Stripe would otherwise
 * credit the unused time to the customer's balance on top of the refund (App\Billing\ProratedRefund).
 */
class SubscriptionEnder
{
    /** Stripe statuses a subscription can still bill or be revived in. */
    private const RUNNING = ['active', 'trialing', 'past_due', 'unpaid', 'incomplete', 'paused'];

    public function __construct(private readonly ProratedRefund $refunds) {}

    /**
     * The customer's running subscriptions at Stripe (their ids).
     *
     * @return list<string>
     */
    public function running(Organisation $organisation): array
    {
        if (! $organisation->hasStripeId()) {
            return [];
        }
        $list = Cashier::stripe()->subscriptions->all(['customer' => (string) $organisation->stripe_id, 'status' => 'all', 'limit' => 100]);
        $ids = [];
        foreach ($list->data as $subscription) {
            if (in_array((string) $subscription->status, self::RUNNING, true)) {
                $ids[] = (string) $subscription->id;
            }
        }

        return $ids;
    }

    /**
     * Refund first when asked (nothing is cancelled when the refund fails), then cancel every
     * running subscription at Stripe, then mark REDact's copies as ended.
     *
     * @return array{refund: string|null, cancelled: list<string>}
     */
    public function end(Organisation $organisation, User $by, bool $refund): array
    {
        $amount = $refund ? $this->refunds->issue($organisation, $by) : null;

        $cancelled = [];
        foreach ($this->running($organisation) as $id) {
            try {
                Cashier::stripe()->subscriptions->cancel($id, ['prorate' => false]);
            } catch (InvalidRequestException $e) {
                // Already gone at Stripe: nothing to cancel.
                if ($e->getStripeCode() !== 'resource_missing') {
                    throw $e;
                }
            }
            $cancelled[] = $id;
        }

        foreach ($organisation->subscriptions()->get() as $subscription) {
            if ($subscription instanceof Subscription && ! $subscription->ended()) {
                $subscription->markAsCanceled();
            }
        }

        return ['refund' => $amount, 'cancelled' => $cancelled];
    }
}
