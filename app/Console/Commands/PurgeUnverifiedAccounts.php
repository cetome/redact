<?php

namespace App\Console\Commands;

use App\Auth\AccountDeleter;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Deletes accounts whose e-mail address was never confirmed, after
 * config('redact.auth.purge_unverified_days') days. Scheduled daily.
 */
class PurgeUnverifiedAccounts extends Command
{
    protected $signature = 'redact:purge-unverified';

    protected $description = 'Delete accounts that never confirmed their e-mail address';

    public function handle(AccountDeleter $deleter): int
    {
        $days = (int) config('redact.auth.purge_unverified_days', 7);
        $count = 0;

        User::query()
            ->whereNull('email_verified_at')
            // Guest sandboxes have their own purge (redact:purge-guests).
            ->where('is_guest', false)
            ->where('created_at', '<', now()->subDays($days))
            ->lazyById()
            ->each(function (User $user) use ($deleter, &$count) {
                if ($deleter->ownsProducts($user)) {
                    return;
                }
                $deleter->delete($user, 'unverified for '.config('redact.auth.purge_unverified_days', 7).' days');
                $count++;
            });

        $this->info("Deleted {$count} unverified account(s).");

        return self::SUCCESS;
    }
}
