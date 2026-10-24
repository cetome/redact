<?php

namespace App\Http\Middleware;

use App\Models\RequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * First-party usage analytics: one request_logs row per page hit, written
 * after the response has been sent (terminate). No third-party tracker.
 *
 *  - signed-in users: every routed request;
 *  - anonymous visitors: routed hits on public pages only (user_id null);
 *  - never the query string, never a token (invitation / sign-in links are masked),
 *    never a raw IP (keyed HMAC-SHA256 with APP_KEY);
 *  - assets, /up, Vite and console polling are skipped;
 *  - a failure here is logged and swallowed: analytics never break a request.
 */
class LogRequest
{
    /** Request attribute holding the measured duration (ms). */
    private const DURATION = 'redact.request_log.duration_ms';

    /** Paths that are never logged (assets, health check, Vite dev server). */
    private const SKIP_PATHS = [
        'up', 'build/*', '@vite/*', '@fs/*', 'node_modules/*', 'resources/*', 'brand/*', 'js/*', 'css/*',
        'fonts/*', 'images/*', 'storage/*', 'favicon*', 'robots.txt', 'site.webmanifest', 'sanctum/*',
    ];

    /** Routes whose own hits would only add noise (console polling, endpoints called by scripts). */
    private const SKIP_ROUTES = ['admin.poll*', 'passkeys.*options*', 'templates.image'];

    /** Route parameters that carry a secret and are masked in the stored path. */
    private const SECRET_PARAMETERS = ['token', 'code', 'hash', 'signature', 'secret'];

    /** Path prefixes whose following segments are treated as secrets, whatever the route. */
    private const SECRET_PREFIXES = ['invitations', 'login', 'register', 'auth/link', 'auth/verify', 'email/verify'];

    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);

        /** @var Response $response */
        $response = $next($request);

        $request->attributes->set(self::DURATION, (int) round((hrtime(true) - $start) / 1_000_000));

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->shouldLog($request)) {
                return;
            }

            $route = $request->route();
            $user = $request->user();

            RequestLog::query()->insert([
                'user_id' => $user?->getKey(),
                // Frozen at write time, so purged sandbox guests still count as guests (null: anonymous).
                'guest' => $user === null ? null : (bool) $user->getAttribute('is_guest'),
                'method' => substr($request->getMethod(), 0, 8),
                'route' => $route?->getName() ? substr((string) $route->getName(), 0, 120) : null,
                'path' => $this->safePath($request),
                'status' => min(999, max(0, $response->getStatusCode())),
                'duration_ms' => max(0, (int) $request->attributes->get(self::DURATION, 0)),
                'product_id' => $this->ulidParameter($request, 'product'),
                'version_id' => $this->ulidParameter($request, 'version'),
                'ip_hash' => self::hashIp($request->ip()),
                'user_agent' => $this->clip($request->userAgent(), 255),
                'locale' => substr(app()->getLocale(), 0, 8),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Request log could not be written', ['exception' => $e->getMessage()]);
        }
    }

    /** Keyed hash of an IP address: comparable between rows, not reversible without APP_KEY. */
    public static function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    private function shouldLog(Request $request): bool
    {
        if (in_array($request->getMethod(), ['HEAD', 'OPTIONS'], true)) {
            return false;
        }

        if ($request->is(...self::SKIP_PATHS) || $request->headers->has('X-Admin-Poll')) {
            return false;
        }

        $route = $request->route();
        $name = $route?->getName();
        if ($name !== null && Str::is(self::SKIP_ROUTES, $name)) {
            return false;
        }

        // Anonymous visitors: only routed public pages (no scanners hitting random paths).
        if ($request->user() === null) {
            return $name !== null && ! Str::startsWith($name, 'admin.');
        }

        return true;
    }

    /** Path without query string, with every secret segment replaced by a placeholder. */
    private function safePath(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');
        $route = $request->route();

        if ($route !== null) {
            foreach (self::SECRET_PARAMETERS as $parameter) {
                $value = $route->originalParameter($parameter);
                if (is_string($value) && $value !== '') {
                    $path = str_replace('/'.rawurlencode($value), '/{'.$parameter.'}', $path);
                    $path = str_replace('/'.$value, '/{'.$parameter.'}', $path);
                }
            }
        }

        foreach (self::SECRET_PREFIXES as $prefix) {
            $quoted = preg_quote($prefix, '#');
            if (preg_match('#^/'.$quoted.'/(.+)$#', $path)) {
                $path = (string) preg_replace('#^/'.$quoted.'/[^/]+#', '/'.$prefix.'/{token}', $path);
            }
        }

        return $this->clip($path, 255) ?? '/';
    }

    private function ulidParameter(Request $request, string $name): ?string
    {
        $value = $request->route()?->originalParameter($name);

        return is_string($value) && Str::isUlid($value) ? $value : null;
    }

    private function clip(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
