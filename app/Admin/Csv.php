<?php

namespace App\Admin;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed CSV exports for the console, safe to open in a spreadsheet:
 * a cell that starts with = + - @ (or a tab / carriage return) is prefixed
 * with an apostrophe so it is never evaluated as a formula (CSV injection).
 */
final class Csv
{
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_array($value)) {
            $value = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /** @param  array<int, string|null>|array<string, mixed>  $row */
    public static function line(array $row): array
    {
        return array_map(self::cell(...), array_values($row));
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<array<int|string, mixed>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheets read accents correctly
            fputcsv($out, self::line($header), ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, self::line($row), ',', '"', '');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
