<?php

use App\Admin\UsageAnalytics;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrganisationController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\UsageController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// cetome console (prefix /admin, names admin.*, `auth` + `staff` applied in web.php: 404 for everyone else).
// No query strings: list filters are POSTed to …/filter and kept in the session (App\Support\SessionFilters),
// pages are …/page/{n} (App\Support\PathPaginator) and the CSV exports read the remembered filters.

Route::get('/', DashboardController::class)->name('dashboard');

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

Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/page/{page}', [ProductController::class, 'index'])->name('products.page')->whereNumber('page');
Route::post('/products/filter', [ProductController::class, 'filter'])->name('products.filter');

Route::get('/organisations', [OrganisationController::class, 'index'])->name('organisations.index');
Route::get('/organisations/page/{page}', [OrganisationController::class, 'index'])->name('organisations.page')->whereNumber('page');
Route::post('/organisations/filter', [OrganisationController::class, 'filter'])->name('organisations.filter');
Route::get('/organisations/{organisation}', [OrganisationController::class, 'show'])->name('organisations.show');
Route::put('/organisations/{organisation}/plan', [OrganisationController::class, 'update'])->name('organisations.plan');

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
