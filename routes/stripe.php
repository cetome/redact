<?php

use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController;

/*
| Stripe webhooks (Laravel Cashier). Outside the `web` group on purpose: no session, no CSRF,
| no cookies; Cashier checks the Stripe-Signature header against STRIPE_WEBHOOK_SECRET.
| Loaded by App\Providers\BillingServiceProvider. URL: https://<host>/stripe/webhook
*/

Route::post('/stripe/webhook', [WebhookController::class, 'handleWebhook'])->name('cashier.webhook');
