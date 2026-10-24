<?php

namespace App\Providers;

use App\Models\Organisation;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

/**
 * Plans and Stripe billing (App\Billing). The organisation is the Stripe customer. Only the
 * webhook route of Cashier is used (routes/stripe.php): Checkout and the Customer Portal are
 * Stripe-hosted redirects, so no Stripe.js and no CSP change.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Cashier::ignoreRoutes();
    }

    public function boot(): void
    {
        Cashier::useCustomerModel(Organisation::class);
        // Stripe Tax on Checkout and invoices (EU VAT, reverse charge with a valid tax ID).
        Cashier::calculateTaxes();
        // A failed payment is retried by Stripe: the products stay editable meanwhile (banner on the billing page).
        Cashier::keepPastDueSubscriptionsActive();

        $this->loadRoutesFrom(base_path('routes/stripe.php'));
    }
}
