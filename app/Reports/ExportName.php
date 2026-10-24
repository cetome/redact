<?php

namespace App\Reports;

/**
 * The name of every file REDact exports, one rule (CRAted's exportFileName):
 * "REDact - <part> - <part>.<extension>". Characters a file system refuses are
 * replaced, spaces evened out, empty parts left out.
 */
final class ExportName
{
    /**
     * @param  list<string|null>  $parts
     */
    public static function make(array $parts, string $extension): string
    {
        return implode(' - ', ['REDact', ...self::clean($parts)]).'.'.$extension;
    }

    /**
     * The same rule without the "REDact" lead, for files named after a cetome workbook
     * ("Smart lock 2.0 - cetome REDact - E.Info.xlsx").
     *
     * @param  list<string|null>  $parts
     */
    public static function plain(array $parts, string $extension): string
    {
        return implode(' - ', self::clean($parts) ?: ['REDact']).'.'.$extension;
    }

    /**
     * @param  list<string|null>  $parts
     * @return list<string>
     */
    private static function clean(array $parts): array
    {
        $clean = [];
        foreach ($parts as $part) {
            $part = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]+/u', ' ', (string) $part) ?? '';
            $part = trim(preg_replace('/\s+/u', ' ', $part) ?? '');
            if ($part !== '') {
                $clean[] = $part;
            }
        }

        return $clean;
    }
}
