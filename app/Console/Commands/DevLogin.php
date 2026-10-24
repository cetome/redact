<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PathSignature;
use Illuminate\Console\Command;

/**
 * Local development only: prints a one-minute signed address (signature in the path,
 * App\Support\PathSignature) that signs in as a user, for screenshots and manual testing. Refuses to run (and the route is
 * not registered) unless APP_ENV=local and REDACT_DEV_LOGIN=true.
 */
class DevLogin extends Command
{
    protected $signature = 'redact:dev-login {email} {--staff : sign in with the passkey method (staff console)}';

    protected $description = 'Local only: print a short-lived sign-in URL for a user (screenshots, manual tests).';

    public function handle(): int
    {
        if (! app()->environment('local') || ! config('redact.dev_login')) {
            $this->error('Only available with APP_ENV=local and REDACT_DEV_LOGIN=true.');

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        // Relative address: the link works on any local host/port (php artisan serve --port=...).
        $method = $this->option('staff') ? 'passkey' : 'email-link';
        $expires = now()->addMinute()->getTimestamp();
        $this->line(route('dev.login', [
            'user' => $user->id,
            'method' => $method,
            'expires' => $expires,
            'signature' => PathSignature::sign('dev-login', [(string) $user->id, $method], $expires),
        ], absolute: false));

        return self::SUCCESS;
    }
}
