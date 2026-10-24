<?php

use App\Http\Controllers\DemoController;
use App\Http\Controllers\GuestController;
use Illuminate\Support\Facades\Route;

/*
| Guest sandbox ("Try without an account", App\Guest).
|   /try        explains the sandbox and starts one (ALTCHA, 5 per IP and day)
|   /try/keep   "Keep my work": a code to an e-mail address turns the sandbox
|               into an account, or moves it into the account of that address
*/

Route::get('/try', [GuestController::class, 'show'])->name('guest.start');
Route::post('/try', [GuestController::class, 'store'])->name('guest.create')->middleware('throttle:20,60,guest-create');
// "See a demo": a sandbox holding the content pack's demo product (App\Guest\DemoProduct), for visitors (ALTCHA)
// and guests whose sandbox is still empty. Same per-IP sandbox limit and throttle as /try.
Route::post('/try/demo', DemoController::class)->name('guest.demo')->middleware('throttle:20,60,guest-create');
// The proof-of-work challenge, for visitors and for signed-in guests (the sign-up one is for visitors only).
Route::get('/try/altcha', [GuestController::class, 'challenge'])->name('guest.altcha')->middleware('throttle:30,1,guest-altcha');

Route::middleware('auth')->group(function () {
    Route::get('/try/keep', [GuestController::class, 'claim'])->name('guest.claim');
    // "Use another address" while a code waits: the form again.
    Route::get('/try/keep/other', [GuestController::class, 'claim'])->name('guest.claim.other');
    Route::post('/try/keep', [GuestController::class, 'sendClaim'])->name('guest.claim.send')->middleware('throttle:10,15');
    Route::post('/try/keep/verify', [GuestController::class, 'verifyClaim'])->name('guest.claim.verify')->middleware('throttle:30,15');
});
