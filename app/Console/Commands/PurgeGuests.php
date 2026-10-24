<?php

namespace App\Console\Commands;

use App\Guest\SandboxPurger;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deletes guest sandboxes whose expiry has passed (24 hours after their last
 * activity, config redact.guest.ttl_hours), with everything they own.
 * Scheduled every 15 minutes (routes/console.php).
 */
class PurgeGuests extends Command
{
    protected $signature = 'redact:purge-guests';

    protected $description = 'Delete expired guest sandboxes and everything they hold';

    public function handle(SandboxPurger $purger): int
    {
        $count = 0;

        User::query()
            ->where('is_guest', true)
            ->where(fn ($q) => $q->where('guest_expires_at', '<', now())
                // A guest without expiry (should not happen) goes ttl hours after creation.
                ->orWhere(fn ($q) => $q->whereNull('guest_expires_at')
                    ->where('created_at', '<', now()->subHours((int) config('redact.guest.ttl_hours', 24)))))
            ->lazyById()
            ->each(function (User $guest) use ($purger, &$count) {
                try {
                    $purger->purge($guest);
                    $count++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $this->info("Deleted {$count} expired guest sandbox(es).");

        return self::SUCCESS;
    }
}
