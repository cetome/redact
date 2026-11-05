<?php

namespace App\Billing;

use App\Models\Organisation;
use App\Models\User;

/**
 * Names typed in Stripe Checkout fill the ones REDact does not have yet (owner 2026-11-05): the
 * payer's name for an account still known by its e-mail address, the business name for an
 * organisation still named after its creator (or an e-mail address). A name someone chose is
 * never overwritten. From the return from Checkout (Billing::completeCheckout) or Stripe's
 * checkout.session.completed webhook (StripeWebhookController), whichever comes first.
 */
class StripeNames
{
    /**
     * @param  array<string, mixed>  $details  a Checkout Session's customer_details
     * @return array<string, string> what changed: user, organisation
     */
    public function adopt(Organisation $organisation, array $details, ?User $payer): array
    {
        $business = $this->clean($details['business_name'] ?? null);
        // Buying as a business, "name" may be the business: the person is then individual_name.
        $person = $this->clean($details['individual_name'] ?? null) ?? ($business === null ? $this->clean($details['name'] ?? null) : null);
        $changed = [];

        if ($payer !== null && $person !== null && ! $payer->hasName()) {
            $payer->forceFill(['name' => $person])->save();
            $changed['user'] = $person;
        }

        if ($business !== null && $this->unnamed($organisation)) {
            $organisation->forceFill(['name' => $business])->save();
            $changed['organisation'] = $business;
        }

        return $changed;
    }

    /** Still the name given at sign-up: an e-mail address, or its creator's name. */
    private function unnamed(Organisation $organisation): bool
    {
        $name = trim((string) $organisation->name);
        if ($name === '' || str_contains($name, '@')) {
            return true;
        }
        $creator = $organisation->created_by ? User::query()->find($organisation->created_by) : null;

        return $creator !== null && in_array($name, [trim((string) $creator->getRawOriginal('name')), $creator->email], true);
    }

    private function clean(mixed $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, 120);
    }
}
