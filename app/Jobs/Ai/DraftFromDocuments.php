<?php

namespace App\Jobs\Ai;

use App\Ai\Features\DraftFromDocuments as Draft;
use App\Models\AiUsage;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs "Draft from documentation" off the request (database queue on shared
 * hosting; inline with QUEUE_CONNECTION=sync). The units were reserved when
 * the person asked, so the job is never retried: a failure is reported on the
 * AI page and never half-writes anything (suggestions only).
 */
class DraftFromDocuments implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $versionId,
        public readonly int $userId,
        public readonly int $usageId,
        public readonly string $locale = 'en',
    ) {}

    public function handle(Draft $draft): void
    {
        $version = ProductVersion::find($this->versionId);
        $user = User::find($this->userId);
        $usage = AiUsage::find($this->usageId);
        if (! $version || ! $user || ! $usage) {
            return;
        }

        app()->setLocale($this->locale);

        try {
            $count = $draft->run($version, $user, $usage);
            Cache::put(Draft::statusKey($version), ['state' => 'done', 'count' => $count, 'at' => now()->toIso8601String()], now()->addDay());
        } catch (Throwable $e) {
            report($e);
            if ($usage->outcome === 'reserved') {
                $usage->forceFill(['outcome' => 'error'])->save();
            }
            Cache::put(Draft::statusKey($version), ['state' => 'failed', 'at' => now()->toIso8601String()], now()->addDay());
        }
    }
}
