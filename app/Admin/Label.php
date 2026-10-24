<?php

namespace App\Admin;

use Illuminate\Support\Facades\Lang;

/**
 * Translated label for a stored code (sign-in method, role, log name ...),
 * falling back to the code itself when the console has no wording for it.
 */
final class Label
{
    public static function of(string $group, ?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        $key = 'admin.'.$group.'.'.$code;

        return Lang::has($key) ? (string) __($key) : $code;
    }
}
