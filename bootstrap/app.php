<?php

use App\Http\Middleware\EnsureSessionIsValid;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\GuestSandbox;
use App\Http\Middleware\LogRequest;
use App\Http\Middleware\RequireRecentAuth;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Csp\AddCspHeaders;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // HTTP cron, outside the web middleware (routes/cron.php).
            Route::group([], __DIR__.'/../routes/cron.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES'));

        $middleware->web(append: [
            EnsureSessionIsValid::class,
            GuestSandbox::class,
            SetLocale::class,
            AddCspHeaders::class,
            SecurityHeaders::class,
            LogRequest::class,
        ]);

        $middleware->alias([
            'recent.auth' => RequireRecentAuth::class,
            'staff' => EnsureStaff::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
