<?php

use App\Http\Controllers\BillingController;
use Illuminate\Support\Facades\Route;

// Plan and billing of an organisation (admins only, App\Billing). Stripe webhooks: routes/stripe.php.

// Premium on the pricing page, Upgrade in the app bar: the subscribe page of the person's organisation.
Route::get('/subscribe', [BillingController::class, 'start'])->name('subscribe');
// "Plan and billing" in the account menu: the billing page of the organisation the person pays for.
Route::get('/billing', [BillingController::class, 'mine'])->name('billing.mine');

// One organisation's billing (a person may run several): /billing/{organisation}.
Route::prefix('/billing/{organisation}')->name('billing.')->group(function () {
    Route::get('/', [BillingController::class, 'show'])->name('show');
    // FAST's /subscribe: monthly or yearly, how many products, the total, then Stripe Checkout.
    Route::get('/subscribe', [BillingController::class, 'subscribe'])->name('subscribe');
    Route::post('/checkout', [BillingController::class, 'checkout'])->name('checkout')->middleware('throttle:10,10');
    // Back from Stripe Checkout: Stripe writes the session id into the path ({CHECKOUT_SESSION_ID}, App\Billing\Billing).
    // Without it: a checkout started before the id moved into the path (?session_id=, read once).
    Route::get('/success/{session?}', [BillingController::class, 'success'])->name('success')->where('session', '[A-Za-z0-9_]{1,255}');
    Route::get('/cancelled', [BillingController::class, 'cancelled'])->name('cancelled');
    Route::post('/products', [BillingController::class, 'addProduct'])->name('products.add')->middleware('throttle:10,10');
    // The number of products on the subscription, up only (never fewer from the billing page) (billing page, as FAST).
    Route::put('/products', [BillingController::class, 'setProducts'])->name('products.set')->middleware('throttle:10,10');
    Route::post('/reactivate', [BillingController::class, 'reactivate'])->name('reactivate')->middleware('throttle:10,10');
    Route::post('/portal', [BillingController::class, 'portal'])->name('portal')->middleware('throttle:20,10');
});
