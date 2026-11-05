<?php

use App\Admin\UsageAnalytics;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrganisationController;
use App\Http\Controllers\Admin\PlanCatalogueController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\StripeSettingsController;
use App\Http\Controllers\Admin\UsageController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// cetome console (prefix /admin, names admin.*, `auth` + `staff` applied in web.php: 404 for everyone else).
// No query strings: list filters are POSTed to …/filter and kept in the session (App\Support\SessionFilters),
// pages are …/page/{n} (App\Support\PathPaginator) and the CSV exports read the remembered filters.

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('/overview/{days}', DashboardController::class)->name('dashboard.period')->whereIn('days', array_map('strval', UsageAnalytics::PERIODS));

Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::get('/users/page/{page}', [UserController::class, 'index'])->name('users.page')->whereNumber('page');
Route::post('/users/filter', [UserController::class, 'filter'])->name('users.filter');
Route::get('/users/export', [UserController::class, 'export'])->name('users.export')->middleware('throttle:10,1');
Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show')->whereNumber('user');
Route::post('/users/{user}/timeline', [UserController::class, 'timeline'])->name('users.timeline')->whereNumber('user');
Route::post('/users/{user}/disable', [UserController::class, 'disable'])->name('users.disable')->whereNumber('user');
Route::post('/users/{user}/enable', [UserController::class, 'enable'])->name('users.enable')->whereNumber('user');
Route::post('/users/{user}/sessions/revoke', [UserController::class, 'revokeSessions'])->name('users.sessions.revoke')->whereNumber('user');
Route::post('/users/{user}/roles', [UserController::class, 'updateRole'])->name('users.roles')->whereNumber('user');
// Delete an account for good, with or without refunding the last payment (super-admin, App\Admin\AccountEraser).
Route::delete('/users/{user}', [UserController::class, 'erase'])->name('users.erase')->whereNumber('user')->middleware('recent.auth');

Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/page/{page}', [ProductController::class, 'index'])->name('products.page')->whereNumber('page');
Route::post('/products/filter', [ProductController::class, 'filter'])->name('products.filter');
// Delete a customer's product (super-admin; customers only on self-hosted installs or in Enterprise).
Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy')->middleware('recent.auth');

Route::get('/organisations', [OrganisationController::class, 'index'])->name('organisations.index');
Route::get('/organisations/page/{page}', [OrganisationController::class, 'index'])->name('organisations.page')->whereNumber('page');
Route::post('/organisations/filter', [OrganisationController::class, 'filter'])->name('organisations.filter');
Route::get('/organisations/{organisation}', [OrganisationController::class, 'show'])->name('organisations.show');
Route::put('/organisations/{organisation}/plan', [OrganisationController::class, 'update'])->name('organisations.plan');
Route::delete('/organisations/{organisation}/trial', [OrganisationController::class, 'endTrial'])->name('organisations.trial.end');
// End the Stripe subscription now, with or without a prorated refund (super-admin).
Route::delete('/organisations/{organisation}/subscription', [OrganisationController::class, 'endSubscription'])->name('organisations.subscription.end')->middleware('recent.auth');

// Plans and prices: what each plan includes, its Stripe prices per month and per year, the add-ons (App\Billing\Catalogue).
Route::get('/plans', [PlanCatalogueController::class, 'index'])->name('plans.index');
Route::post('/plans', [PlanCatalogueController::class, 'store'])->name('plans.store')->middleware('throttle:20,1');
Route::put('/plans/{plan}', [PlanCatalogueController::class, 'update'])->name('plans.update')->where('plan', '[a-z][a-z0-9_]{1,30}')->middleware('throttle:20,1');
Route::delete('/plans/{plan}', [PlanCatalogueController::class, 'destroy'])->name('plans.destroy')->where('plan', '[a-z][a-z0-9_]{1,30}');
Route::post('/plans/{plan}/prices', [PlanCatalogueController::class, 'price'])->name('plans.price')->where('plan', '[a-z][a-z0-9_]{1,30}')->middleware('throttle:10,1');

Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');
Route::get('/activity/page/{page}', [ActivityController::class, 'index'])->name('activity.page')->whereNumber('page');
Route::post('/activity/filter', [ActivityController::class, 'filter'])->name('activity.filter');
Route::get('/activity/export', [ActivityController::class, 'export'])->name('activity.export')->middleware('throttle:10,1');

Route::get('/usage', [UsageController::class, 'index'])->name('usage.index');
Route::get('/usage/{days}', [UsageController::class, 'index'])->name('usage.period')->whereIn('days', array_map('strval', UsageAnalytics::PERIODS));

Route::get('/contact', [ContactMessageController::class, 'index'])->name('contact.index');
Route::get('/contact/page/{page}', [ContactMessageController::class, 'index'])->name('contact.page')->whereNumber('page');
Route::post('/contact/filter', [ContactMessageController::class, 'filter'])->name('contact.filter');
Route::get('/contact/{message}', [ContactMessageController::class, 'show'])->name('contact.show')->whereNumber('message');

// AI settings: bring your own key and model (staff read, super-admin change; App\Ai\AiSettings).
Route::get('/ai', [AiSettingsController::class, 'edit'])->name('ai.edit');
Route::put('/ai', [AiSettingsController::class, 'update'])->name('ai.update')->middleware('throttle:20,1');
Route::post('/ai/test', [AiSettingsController::class, 'test'])->name('ai.test')->middleware('throttle:6,1');

// Stripe: keys, the product price and the webhook, sandbox then live (staff read, super-admin change; App\Billing\StripeSettings).
Route::get('/stripe', [StripeSettingsController::class, 'edit'])->name('stripe.edit');
Route::put('/stripe', [StripeSettingsController::class, 'update'])->name('stripe.update')->middleware('throttle:20,1');
Route::post('/stripe/webhook', [StripeSettingsController::class, 'webhook'])->name('stripe.webhook')->middleware('throttle:6,1');
