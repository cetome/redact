<?php

namespace App\Support;

/**
 * Signed links whose signature is a path segment, never a query (Laravel's signed URLs add
 * ?expires=&signature=): an HMAC-SHA256 with the application key over a purpose, the link's own
 * segments and its expiry (a Unix time, itself a segment). Checked with hash_equals, refused once
 * expired. The purpose keeps a signature made for one kind of link from opening another.
 */
final class PathSignature
{
    /** @param  list<string|int>  $parts */
    public static function sign(string $purpose, array $parts, int $expires): string
    {
        return hash_hmac('sha256', implode('|', [$purpose, ...array_map('strval', $parts), (string) $expires]), self::key());
    }

    /** @param  list<string|int>  $parts */
    public static function valid(string $purpose, array $parts, int|string $expires, string $signature): bool
    {
        if (! ctype_digit((string) $expires) || (int) $expires < time()) {
            return false;
        }

        return hash_equals(self::sign($purpose, $parts, (int) $expires), $signature);
    }

    private static function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }
}
