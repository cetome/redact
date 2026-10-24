<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SessionFilters;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base of the cetome console controllers. The `auth` and `staff` middleware
 * (404 for everyone else) are applied in routes/web.php; this adds no-store
 * caching and the super-admin check.
 */
abstract class AdminController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, Closure $next): Response {
                /** @var Response $response */
                $response = $next($request);
                $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

                return $response;
            }),
        ];
    }

    protected function staffUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    protected function isSuperAdmin(Request $request): bool
    {
        return $this->staffUser($request)->hasRole('super-admin');
    }

    /** Super-admin only, answered with 404 like the rest of the console. */
    protected function requireSuperAdmin(Request $request): void
    {
        abort_unless($this->isSuperAdmin($request), 404);
    }

    /**
     * A list's filter form (POST): validated and remembered in the session under $key, or forgotten
     * by its Reset button, then back to the clean address of the list.
     *
     * @param  array<string, mixed>  $rules
     */
    protected function rememberFilters(Request $request, string $key, array $rules, string $to): RedirectResponse
    {
        if (SessionFilters::resetting($request)) {
            SessionFilters::forget($request, $key);
        } else {
            SessionFilters::remember($request, $key, $request->validate($rules));
        }

        return redirect()->to($to);
    }

    /**
     * The remembered filters of a list, every rule key present (null when not set).
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function rememberedFilters(Request $request, string $key, array $rules): array
    {
        return SessionFilters::get($request, $key, array_fill_keys(array_keys($rules), null));
    }
}
