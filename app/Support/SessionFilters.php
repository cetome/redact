<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Filters of a list page kept in the session, so no address carries a query string: the filter
 * form POSTs, the controller validates and remembers the values under the page's key, then
 * redirects to the clean address of the page, which reads them back. "Reset" forgets them.
 * The key names one page and its subject (e.g. "history.product.<id>", "admin.users").
 */
final class SessionFilters
{
    /**
     * The remembered filters of a page, every key of $defaults present.
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function get(Request $request, string $key, array $defaults): array
    {
        $stored = $request->hasSession() ? $request->session()->get(self::key($key), []) : [];

        return array_merge($defaults, array_intersect_key(is_array($stored) ? $stored : [], $defaults));
    }

    /** @param  array<string, mixed>  $filters  already validated */
    public static function remember(Request $request, string $key, array $filters): void
    {
        $kept = array_filter($filters, fn ($value) => $value !== null && $value !== '' && $value !== []);

        if ($kept === []) {
            self::forget($request, $key);

            return;
        }
        $request->session()->put(self::key($key), $kept);
    }

    public static function forget(Request $request, string $key): void
    {
        $request->session()->forget(self::key($key));
    }

    /** Whether the filter form asked to reset (its Reset button: name="reset" value="1"). */
    public static function resetting(Request $request): bool
    {
        return $request->boolean('reset');
    }

    private static function key(string $key): string
    {
        return 'filters.'.$key;
    }
}
