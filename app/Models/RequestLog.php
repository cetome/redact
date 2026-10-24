<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * First-party page/usage analytics (no third-party tracker). IPs are stored
 * as keyed hashes only.
 */
#[Fillable(['user_id', 'method', 'route', 'path', 'status', 'duration_ms', 'product_id', 'version_id',
    'ip_hash', 'user_agent', 'locale'])]
class RequestLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** @return Builder<RequestLog> */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('redact.retention.request_logs_days')));
    }
}
