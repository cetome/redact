<?php

namespace App\Billing;

use App\Models\Organisation;
use App\Models\User;
use Laravel\Cashier\Cashier;
use Stripe\Charge;

/**
 * The unused part of the current period (owner 2026-11-04: prorated): the last payment times the
 * time left in the period of the active subscription, read from Stripe at the second, VAT included
 * as it was paid. Used when staff end a subscription (Admin\OrganisationController) or delete an
 * account (App\Admin\AccountEraser).
 */
class ProratedRefund
{
    /**
     * What a refund would give back. Nothing when there is no active subscription, the period is
     * over, or the last payment is older than the period (not paid for).
     *
     * @return array{charge: Charge, cents: int, days: int, total: int}|null
     */
    public function compute(Organisation $organisation): ?array
    {
        $charge = $this->lastCharge($organisation);
        if ($charge === null) {
            return null;
        }
        $subscription = Cashier::stripe()->subscriptions->all(['customer' => (string) $organisation->stripe_id, 'status' => 'active', 'limit' => 1])->data[0] ?? null;
        if ($subscription === null) {
            return null;
        }
        // The current API keeps the period on each item, older ones on the subscription.
        $item = $subscription->items->data[0] ?? null;
        $read = fn (string $key): int => (int) ($item !== null && isset($item->{$key}) ? $item->{$key} : (isset($subscription->{$key}) ? $subscription->{$key} : 0));
        [$start, $end, $now] = [$read('current_period_start'), $read('current_period_end'), now()->getTimestamp()];
        if ($end <= $now || $end <= $start || (int) $charge->created < $start - 86400) {
            return null;
        }
        $cents = intdiv((int) $charge->amount * ($end - $now), $end - $start);

        return $cents > 0 ? [
            'charge' => $charge,
            'cents' => $cents,
            'days' => (int) ceil(($end - $now) / 86400),
            'total' => (int) round(($end - $start) / 86400),
        ] : null;
    }

    /**
     * For the console: the amount, the payment it comes from and the days left, as shown on the button.
     *
     * @return array{amount: string, paid: string, date: string, days: int, total: int}|null
     */
    public function describe(Organisation $organisation): ?array
    {
        $refund = $this->compute($organisation);
        if ($refund === null) {
            return null;
        }
        $charge = $refund['charge'];

        return [
            'amount' => StripeSettings::label($refund['cents'], (string) $charge->currency),
            'paid' => StripeSettings::label((int) $charge->amount, (string) $charge->currency),
            'date' => now()->setTimestamp((int) $charge->created)->isoFormat('D MMMM YYYY'),
            'days' => $refund['days'],
            'total' => $refund['total'],
        ];
    }

    /** Refunds the unused part now. Returns the amount refunded, or null when there is nothing to refund. */
    public function issue(Organisation $organisation, User $by): ?string
    {
        $prorated = $this->compute($organisation);
        if ($prorated === null) {
            return null;
        }
        Cashier::stripe()->refunds->create([
            'charge' => $prorated['charge']->id,
            'amount' => $prorated['cents'],
            'reason' => 'requested_by_customer',
            'metadata' => [
                'redact_organisation' => (string) $organisation->getKey(), 'redact_by' => (string) $by->getKey(),
                'redact_unused_days' => $prorated['days'].'/'.$prorated['total'],
            ],
        ]);

        return StripeSettings::label($prorated['cents'], (string) $prorated['charge']->currency);
    }

    /**
     * The organisation's last successful payment, while nothing of it was refunded: a payment
     * refunded once (in part or in full) is never refunded again from here.
     */
    private function lastCharge(Organisation $organisation): ?Charge
    {
        if (! $organisation->hasStripeId()) {
            return null;
        }
        $charges = Cashier::stripe()->charges->all(['customer' => (string) $organisation->stripe_id, 'limit' => 10]);
        foreach ($charges->data as $charge) {
            if ($charge->paid && $charge->status === 'succeeded') {
                return (int) $charge->amount_refunded === 0 ? $charge : null;
            }
        }

        return null;
    }
}
