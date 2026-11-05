<?php

use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Stripe webhooks (Laravel Cashier through App\Http\Controllers\StripeWebhookController, which reads
| subscriptions again from Stripe). Outside the `web` group on purpose: no session, no CSRF,
| no cookies; Cashier checks the Stripe-Signature header against STRIPE_WEBHOOK_SECRET.
| Loaded by App\Providers\BillingServiceProvider. URL: https://<host>/stripe/webhook
*/

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])->name('cashier.webhook');
