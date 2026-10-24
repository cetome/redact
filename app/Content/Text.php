<?php

namespace App\Content;

/**
 * Content-pack strings are either plain strings (English) or locale maps
 * such as {"en": "...", "fr": "..."}. This resolves them for the current locale.
 */
final class Text
{
    public static function of(mixed $value, ?string $locale = null): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (! is_array($value) || $value === []) {
            return '';
        }

        $locale ??= app()->getLocale();

        return (string) ($value[$locale] ?? $value[config('app.fallback_locale', 'en')] ?? reset($value));
    }
}
