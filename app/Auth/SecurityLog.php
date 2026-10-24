<?php

namespace App\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Security audit trail (activity log "security"): who, what, from where.
 */
final class SecurityLog
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(string $description, ?Model $causer = null, array $properties = [], ?Model $subject = null): void
    {
        $request = request();

        $logger = activity('security')->withProperties(array_merge([
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ], $properties));

        if ($causer !== null) {
            $logger->causedBy($causer);
        }
        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        $logger->log($description);
    }
}
