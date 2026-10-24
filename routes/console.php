<?php

use App\Models\Product;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shared hosting has no queue worker: drain the database queue from the every-minute cron.
if (config('redact.queue_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
        ->everyMinute()->withoutOverlapping()->runInBackground();
}

// Products deleted more than 30 days ago (App\Models\Product::prunable).
Schedule::command('model:prune', ['--model' => [Product::class]])->dailyAt('03:10');

// Usage analytics, audit trail and contact form messages retention (config/redact.php, retention).
Schedule::command('redact:prune-tracking')->dailyAt('03:20')->withoutOverlapping();

// Accounts that never confirmed their e-mail address (config/redact.php, auth.purge_unverified_days).
Schedule::command('redact:purge-unverified')->dailyAt('03:40')->withoutOverlapping();

// Guest sandboxes 24 hours after their last activity (config/redact.php, guest.ttl_hours).
Schedule::command('redact:purge-guests')->everyFifteenMinutes()->withoutOverlapping();

// Original SBOM / HBOM files of deleted versions and uploads nobody imported (App\Console\Commands\PruneSbomFiles).
Schedule::command('redact:prune-sbom-files')->dailyAt('03:50')->withoutOverlapping();

// Weekly re-check of known vulnerabilities of the versions in work, off unless REDACT_VULN_WEEKLY=true.
if (config('sbom.checks.weekly')) {
    Schedule::command('redact:recheck-vulnerabilities')->weeklyOn(1, '04:00')->withoutOverlapping();
}
