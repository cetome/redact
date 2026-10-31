<?php

namespace App\Support;

use Illuminate\Support\Number;

/**
 * A file size in the interface language: "12.1 KB" in English, "12,1 Ko" in French
 * (Number::fileSize() always writes English units and an English decimal point).
 */
final class FileSize
{
    public static function format(int|float $bytes, int $precision = 1): string
    {
        $units = ['b', 'kb', 'mb', 'gb'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        $number = Number::format($bytes, $i === 0 ? 0 : $precision, locale: app()->getLocale());

        return (string) __('common.size.'.$units[$i], ['size' => $number === false ? (string) round($bytes, $precision) : $number]);
    }
}
