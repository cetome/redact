<?php

namespace App\Console\Commands;

use App\Auth\SecurityLog;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;

/**
 * Grants the cetome staff role (or super-admin) to an existing account.
 * Staff reach the console only from a session signed in or confirmed with a
 * verified passkey (EnsureStaff).
 */
class MakeStaff extends Command
{
    protected $signature = 'redact:make-staff {email : The account\'s e-mail address} {--super : Grant super-admin instead of staff}';

    protected $description = 'Grant the cetome staff (or super-admin) role to an account';

    public function handle(): int
    {
        (new RolesSeeder)->run();

        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No account uses {$email}.");

            return self::FAILURE;
        }

        if ($user->is_guest) {
            $this->error('A guest sandbox account can never be staff.');

            return self::FAILURE;
        }

        $role = $this->option('super') ? 'super-admin' : 'staff';
        $user->assignRole($role);

        SecurityLog::log('staff granted', null, ['role' => $role, 'via' => 'console', 'email' => $user->email], $user);

        $this->info("{$user->email} now has the {$role} role.");
        if (! $user->passkeys()->exists()) {
            $this->warn('This account has no passkey yet: the console needs a passkey sign-in.');
        }

        return self::SUCCESS;
    }
}
