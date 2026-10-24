<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the UI language: the account's preference, else the browser's,
 * else the default. Only languages listed in config('redact.locales').
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('redact.locales'));

        $locale = $request->user()?->locale;
        if (! in_array($locale, $supported, true)) {
            $locale = $request->session()->get('locale');
        }
        if (! in_array($locale, $supported, true)) {
            $locale = $request->getPreferredLanguage($supported) ?? config('app.locale');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
