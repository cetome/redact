<?php

namespace App\Providers;

use App\Content\ContentPack;
use App\History\ActivityScope;
use App\Models\User;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse;
use Laravel\Passkeys\Contracts\PasskeyDeletedResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ContentPack::class, function () {
            $path = rtrim((string) config('redact.content_path'), '/\\');
            if ($path === '') {
                throw new \RuntimeException('REDACT_CONTENT_PATH is not set: point it to a content pack, e.g. packs/demo (docs/CONTENT-PACK.md).');
            }
            // Relative paths are relative to the application root.
            if (! preg_match('#^([A-Za-z]:)?[\\\\/]#', $path)) {
                $path = base_path($path);
            }

            return new ContentPack($path);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Passkeys (laravel/passkeys): REDact completes sign-ins and confirmations
        // itself (session keys, security log) and refuses inactive accounts.
        $this->app->bind(PasskeyLoginResponse::class, \App\Auth\Responses\PasskeyLoginResponse::class);
        $this->app->bind(PasskeyConfirmationResponse::class, \App\Auth\Responses\PasskeyConfirmationResponse::class);
        $this->app->bind(PasskeyDeletedResponse::class, \App\Auth\Responses\PasskeyDeletedResponse::class);
        Passkeys::authorizeLoginUsing(
            fn ($request, $user) => $user instanceof User && $user->active
        );

        // Numbers follow the interface language ("1,234.5" / "1 234,5"), like dates do (Carbon).
        Number::useLocale($this->app->getLocale());
        Event::listen(LocaleUpdated::class, fn (LocaleUpdated $event) => Number::useLocale($event->locale));

        // Every activity entry knows its organisation, product and version (customer histories).
        ActivityScope::register();

        // Local development sign-in links (php artisan redact:dev-login). Never in other environments.
        if ($this->app->environment('local') && config('redact.dev_login')) {
            Route::group([], base_path('routes/dev.php'));
        }
    }
}
