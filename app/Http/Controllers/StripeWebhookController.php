<?php

namespace App\Http\Controllers;

use App\Billing\Billing;
use App\Models\Organisation;
use App\Models\User;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Stripe\Exception\InvalidRequestException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe webhooks (routes/stripe.php): Cashier's handling, with one change. Stripe sends events in
 * no guaranteed order and resends the ones that failed, sometimes hours later: a "subscription
 * updated: active" written before a cancellation would otherwise switch an ended subscription
 * back on (Cashier stores the event's copy as it is). For subscription events, the subscription
 * is read again from Stripe and that current state is stored instead. A paid Checkout also fills
 * the names REDact does not have yet (App\Billing\StripeNames).
 */
class StripeWebhookController extends WebhookController
{
    /** @param  array<string, mixed>  $payload */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        $current = $this->current($payload);

        return $current === null ? $this->successMethod() : parent::handleCustomerSubscriptionCreated($current);
    }

    /** @param  array<string, mixed>  $payload */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $current = $this->current($payload);
        if ($current === null) {
            return $this->successMethod();
        }
        // Ended since: stored as ended, by Cashier's own "deleted" handling.
        if (($current['data']['object']['status'] ?? null) === 'canceled') {
            return parent::handleCustomerSubscriptionDeleted($current);
        }

        return parent::handleCustomerSubscriptionUpdated($current);
    }

    /**
     * A paid Checkout: the names typed there fill the ones REDact does not have yet (also when the
     * payer closed the tab before coming back). The payer: the member with the e-mail address given
     * in Checkout, else the organisation's creator.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        $session = $payload['data']['object'] ?? [];
        $customer = (string) ($session['customer'] ?? '');
        $organisation = $customer !== '' ? Organisation::query()->where('stripe_id', $customer)->first() : null;
        if ($organisation !== null) {
            $details = (array) ($session['customer_details'] ?? []);
            $email = mb_strtolower(trim((string) ($details['email'] ?? '')));
            $payer = ($email !== '' ? $organisation->members()->whereRaw('lower(email) = ?', [$email])->first() : null)
                ?? ($organisation->created_by ? User::query()->find($organisation->created_by) : null);
            app(Billing::class)->adoptNames($organisation, $details, $payer instanceof User ? $payer : null);
        }

        return $this->successMethod();
    }

    /**
     * The event with the subscription as Stripe has it now. Null when Stripe no longer knows it
     * (deleted with its customer): nothing to store.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function current(array $payload): ?array
    {
        $id = (string) ($payload['data']['object']['id'] ?? '');
        if ($id === '') {
            return $payload;
        }
        try {
            $payload['data']['object'] = Cashier::stripe()->subscriptions->retrieve($id)->toArray();
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                return null;
            }
            throw $e;
        }

        return $payload;
    }
}
