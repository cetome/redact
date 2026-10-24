<?php

use App\Auth\SignIn;
use App\Models\User;
use App\Support\PathSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Local development only (APP_ENV=local and REDACT_DEV_LOGIN=true), see
| `php artisan redact:dev-login`. Never registered in any other environment.
| The signature and its expiry are path segments (App\Support\PathSignature), not a query; it does
| not cover the host, so the link works on any local host/port.
*/

Route::get('/_dev/login/{user}/{method}/{expires}/{signature}', function (Request $request, string $user, string $method, string $expires, string $signature) {
    abort_unless(PathSignature::valid('dev-login', [$user, $method], $expires, $signature), 403);
    app(SignIn::class)->complete($request, User::query()->findOrFail($user), $method);

    return redirect()->route('dashboard');
})->middleware('web')->name('dev.login')
    ->where(['user' => '[0-9]+', 'method' => 'passkey|email-link', 'expires' => '[0-9]{10}', 'signature' => '[0-9a-f]{64}']);
