<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes usage analytics, audit entries and contact form messages older than their retention
 * (config/redact.php, retention). Security events follow their own retention.
 * Runs daily from the scheduler. Deletes in chunks to stay light on shared hosting.
 */
#[Signature('redact:prune-tracking {--dry-run : Count what would be deleted without deleting}')]
#[Description('Delete request logs, activity log entries and contact messages older than the configured retention')]
class PruneTracking extends Command
{
    private const CHUNK = 1000;

    public function handle(): int
    {
        $retention = (array) config('redact.retention');

        $requestCutoff = now()->subDays(max(1, (int) ($retention['request_logs_days'] ?? 395)));
        $activityCutoff = now()->subDays(max(1, (int) ($retention['activity_log_days'] ?? 730)));
        $securityCutoff = now()->subDays(max(1, (int) ($retention['security_log_days'] ?? 365)));
        $contactCutoff = now()->subDays(max(1, (int) ($retention['contact_messages_days'] ?? 365)));

        $requests = $this->prune(fn () => DB::table('request_logs')->where('created_at', '<', $requestCutoff));

        $activity = $this->prune(fn () => DB::table('activity_log')
            ->where('created_at', '<', $activityCutoff)
            ->where(fn (Builder $q) => $q->whereNull('log_name')->orWhere('log_name', '!=', 'security')));

        $security = $this->prune(fn () => DB::table('activity_log')
            ->where('log_name', 'security')
            ->where('created_at', '<', $securityCutoff));

        $contact = $this->prune(fn () => DB::table('contact_messages')->where('created_at', '<', $contactCutoff));

        $verb = $this->option('dry-run') ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$requests} request log rows, {$activity} activity entries, {$security} security entries, {$contact} contact messages.");

        return self::SUCCESS;
    }

    /** @param  \Closure(): Builder  $query */
    private function prune(\Closure $query): int
    {
        if ($this->option('dry-run')) {
            return $query()->count();
        }

        $total = 0;
        do {
            $ids = $query()->orderBy('id')->limit(self::CHUNK)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $total += DB::table($query()->from)->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        return $total;
    }
}
