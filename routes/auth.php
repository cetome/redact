<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\ConfirmController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Auth\PasskeyController;
use App\Http\Controllers\Auth\PasskeySuggestionController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

/*
| Sign-up, sign-in (e-mail link/code, passkeys) and the account page.
| Passkey ceremonies themselves use the laravel/passkeys routes
| (/passkeys/login, /passkeys/confirm, /user/passkeys; config/passkeys.php).
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'send'])->name('login.send');
    Route::get('/login/code', [LoginController::class, 'code'])->name('login.code');
    Route::post('/login/code', [LoginController::class, 'verify'])->name('login.verify')->middleware('throttle:30,15');

    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->name('register.store')->middleware('throttle:20,60');
    Route::get('/altcha/challenge', [RegisterController::class, 'challenge'])->name('altcha.challenge')->middleware('throttle:30,1');
});

// The e-mail link: GET only shows a "Continue" button, POST signs in.
Route::get('/login/link/{token}', [MagicLinkController::class, 'show'])->name('login.link')->middleware('throttle:60,15');
Route::post('/login/link/{token}', [MagicLinkController::class, 'consume'])->name('login.link.consume')->middleware('throttle:30,15');

Route::middleware('auth')->group(function () {
    Route::post('/logout', LogoutController::class)->name('logout');

    Route::get('/auth/confirm', [ConfirmController::class, 'show'])->name('auth.confirm');
    Route::post('/auth/confirm/send', [ConfirmController::class, 'send'])->name('auth.confirm.send');
    Route::post('/auth/confirm', [ConfirmController::class, 'verify'])->name('auth.confirm.verify')->middleware('throttle:30,15');

    Route::get('/welcome/passkey', [PasskeySuggestionController::class, 'show'])->name('auth.passkey.suggest');
    Route::get('/welcome/passkey/skip', [PasskeySuggestionController::class, 'skip'])->name('auth.passkey.skip');

    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::put('/account', [AccountController::class, 'update'])->name('account.update');
    // One tab of the account page (/account is the profile), and "Confirm it's you" coming back to a tab.
    Route::get('/account/{tab}', [AccountController::class, 'show'])->name('account.tab')->whereIn('tab', ['security', 'sessions', 'data', 'delete']);
    Route::get('/account/confirm/{tab?}', [AccountController::class, 'reconfirmed'])->name('account.reconfirm')
        ->whereIn('tab', AccountController::TABS)->middleware('recent.auth');
    Route::post('/account/email', [AccountController::class, 'requestEmailChange'])->name('account.email')->middleware(['recent.auth', 'throttle:10,15']);
    Route::get('/account/email/confirm', [AccountController::class, 'showEmailChange'])->name('account.email.confirm');
    Route::post('/account/email/confirm', [AccountController::class, 'confirmEmailChange'])->name('account.email.verify')->middleware('throttle:30,15');
    Route::post('/account/sessions/revoke', [AccountController::class, 'revokeSessions'])->name('account.sessions.revoke');
    Route::get('/account/export', [AccountController::class, 'export'])->name('account.export')->middleware('throttle:10,60');
    Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy')->middleware('recent.auth');

    Route::patch('/account/passkeys/{key}', [PasskeyController::class, 'update'])->whereNumber('key')->name('account.passkeys.update');
    Route::delete('/account/passkeys/{key}', [PasskeyController::class, 'destroy'])->whereNumber('key')->name('account.passkeys.destroy')->middleware('recent.auth');
});
