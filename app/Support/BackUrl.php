<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * "Back to the page this was sent from" (the language switch, "Confirm it's you" on a form): the
 * page that sent the request (Referer) when it is on this site, else $fallback. Never the session's
 * last URL: pictures and logos are GET requests too, so it can be an image (the demo bulb).
 * The target is rebuilt on this site from the Referer's path and query only, so whatever a header
 * holds (another host, a backslash trick, another port or scheme) can never leave the site.
 */
final class BackUrl
{
    public static function from(Request $request, string $fallback): string
    {
        $referer = (string) $request->headers->get('referer', '');
        if ($referer === '' || str_contains($referer, '\\') || preg_match('~^https?://[^/?#]+~i', $referer) !== 1) {
            return $fallback;
        }
        $parts = parse_url($referer);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return $fallback;
        }
        $origin = strtolower(($parts['scheme'] ?? '').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''));
        if ($origin !== strtolower($request->getSchemeAndHttpHost()) || isset($parts['user'], $parts['pass'])) {
            return $fallback;
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '' || $path[0] !== '/') {
            return $fallback;
        }

        return url($path).(isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '');
    }
}
