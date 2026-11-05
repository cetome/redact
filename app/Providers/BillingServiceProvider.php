<?php

namespace App\Providers;

use App\Billing\StripeSettings;
use App\Models\Organisation;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

/**
 * Plans and Stripe billing (App\Billing). The organisation is the Stripe customer. Only the
 * webhook route of Cashier is used (routes/stripe.php): Checkout and the Customer Portal are
 * Stripe-hosted redirects, so no Stripe.js and no CSP change. The Stripe settings saved in the
 * console (App\Billing\StripeSettings) apply to each request and queued job, not at boot.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Cashier::ignoreRoutes();
        $this->app->singleton(StripeSettings::class);
    }

    public function boot(): void
    {
        Cashier::useCustomerModel(Organisation::class);
        // Stripe Tax on Checkout and invoices (EU VAT, reverse charge with a valid tax ID).
        Cashier::calculateTaxes();
        // A failed payment is retried by Stripe: the products stay editable meanwhile (banner on the billing page).
        Cashier::keepPastDueSubscriptionsActive();

        $apply = fn () => $this->app->make(StripeSettings::class)->apply();
        Event::listen(RouteMatched::class, $apply);
        Queue::before($apply);

        $this->loadRoutesFrom(base_path('routes/stripe.php'));
    }
}
