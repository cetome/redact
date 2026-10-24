<?php

namespace App\Sbom;

use DOMDocument;
use JsonException;
use ZipArchive;

/**
 * Defensive readers for untrusted uploads: JSON with a depth limit, XML without any DOCTYPE
 * (so no entity expansion or external entity can ever be loaded) and no network, zip archives
 * checked for size, entry count, path tricks and compression ratio before anything is read.
 */
final class Safe
{
    public static function stripBom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }

    /** @return array<mixed> */
    public static function json(string $content): array
    {
        try {
            $data = json_decode(self::stripBom($content), true, (int) config('sbom.import.json_depth', 64), JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw SbomException::because('bad_json');
        }
        if (! is_array($data)) {
            throw SbomException::because('bad_json');
        }

        return $data;
    }

    public static function xml(string $content): DOMDocument
    {
        $content = self::stripBom($content);
        // A bill of materials never needs a DTD: refusing it rules out XXE and "billion laughs".
        // Only UTF-8 (or ASCII) is read, so no other encoding can hide a DOCTYPE from this check.
        if (str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')
            || preg_match('/^\s*<\?xml[^>]*encoding\s*=\s*["\'](?!utf-8|us-ascii)/i', $content)) {
            throw SbomException::because('bad_xml');
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            throw SbomException::because('xml_doctype');
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $ok = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $ok || ! $dom->documentElement || $dom->doctype !== null) {
            throw SbomException::because('bad_xml');
        }

        return $dom;
    }

    public static function isZip(string $content): bool
    {
        return str_starts_with($content, "PK\x03\x04");
    }

    /**
     * Checks a zip before it is opened by anything: entry count, total uncompressed size,
     * ratio of each entry, no absolute or parent paths, no encryption.
     */
    public static function checkZip(string $path, int $maxEntries = 1000): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw SbomException::because('bad_zip');
        }
        $limit = (int) config('sbom.import.max_unzipped_mb', 50) * 1024 * 1024;
        $ratio = (int) config('sbom.import.max_zip_ratio', 200);
        if ($zip->numFiles > $maxEntries) {
            $zip->close();
            throw SbomException::because('zip_too_many');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();
                throw SbomException::because('bad_zip');
            }
            $name = (string) $stat['name'];
            $total += (int) $stat['size'];
            $compressed = max(1, (int) $stat['comp_size']);
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')
                || $stat['encryption_method'] !== 0
                || $total > $limit
                || ((int) $stat['size'] > 1024 * 1024 && (int) $stat['size'] / $compressed > $ratio)) {
                $zip->close();
                throw SbomException::because('zip_unsafe');
            }
        }

        return $zip;
    }

    /** @return array<string, string> name => content of the files of a checked zip (folders and hidden files skipped) */
    public static function zipFiles(string $path, int $maxEntries): array
    {
        $zip = self::checkZip($path, $maxEntries * 4);
        $maxBytes = (int) config('sbom.import.max_kb', 10240) * 1024;
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $base = basename($name);
            if (str_ends_with($name, '/') || $base === '' || str_starts_with($base, '.') || str_starts_with($name, '__MACOSX/')) {
                continue;
            }
            if (count($files) >= $maxEntries) {
                $zip->close();
                throw SbomException::because('zip_too_many');
            }
            $content = $zip->getFromIndex($i, $maxBytes + 1);
            if ($content === false || strlen($content) > $maxBytes) {
                $zip->close();
                throw SbomException::because('zip_unsafe');
            }
            $files[$name] = $content;
        }
        $zip->close();

        return $files;
    }

    public static function isText(string $content): bool
    {
        $content = self::stripBom($content);

        return ! str_contains($content, "\0") && $content !== '';
    }

    /** UTF-8 text (Windows-1252 spreadsheets exported as CSV are converted). */
    public static function utf8(string $content): string
    {
        $content = self::stripBom($content);

        return mb_check_encoding($content, 'UTF-8') ? $content : (mb_convert_encoding($content, 'UTF-8', 'Windows-1252') ?: '');
    }
}
