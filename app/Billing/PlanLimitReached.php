<?php

namespace App\Billing;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * A product cannot be created: the organisation's plan has no product left (CreateProduct,
 * whichever page started it). Rendered as a redirect to New product, which then shows how
 * to add a product (Stripe) or whom to talk to. Nothing is lost: nothing was created.
 */
class PlanLimitReached extends RuntimeException
{
    public static function products(Plan $plan): self
    {
        return new self($plan->productRefusal());
    }

    public function render(): RedirectResponse
    {
        return redirect()->route('products.create')->with('error', $this->getMessage());
    }
}
