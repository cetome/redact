<?php

namespace App\Support;

/**
 * What the interface needs to know about a language beyond its lang/ files: the language the AI
 * answers in, the captcha widget's language, the Open Graph locale, Stripe Checkout's locale and the
 * typography of a colon and of thousands. One entry per language in config('redact.locale_details');
 * a language without one falls back to English, so adding a language never breaks a page.
 */
final class Locales
{
    public static function detail(string $key, ?string $locale = null): string
    {
        $details = (array) config('redact.locale_details', []);
        $locale ??= app()->getLocale();

        return (string) ($details[$locale][$key] ?? $details['en'][$key] ?? '');
    }
}
