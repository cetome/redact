<?php

namespace App\Sbom;

use App\Models\InventoryItem;

/**
 * Machine identifiers of components (package URL, CPE 2.3) and the `identifiers` JSON column
 * of inventory_items that keeps them (the content pack has no field for them).
 *
 * The column is read and written raw here, so the InventoryItem model needs no change.
 */
final class Identifiers
{
    public const PURL_PATTERN = '/^pkg:[a-z][a-z0-9.+-]*\/[^\s]+$/i';

    public const CPE_PATTERN = '/^cpe:2\.3:[aho*-]:[^\s:]+:[^\s:]+(:[^\s]*)?$/i';

    /** @return array<string, mixed> */
    public static function of(InventoryItem $item): array
    {
        $raw = $item->getAttributes()['identifiers'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public static function get(InventoryItem $item, string $key): mixed
    {
        return self::of($item)[$key] ?? null;
    }

    /**
     * Merge values into the column (null removes a key). Does not save.
     *
     * @param  array<string, mixed>  $values
     */
    public static function put(InventoryItem $item, array $values): void
    {
        $merged = array_filter(array_merge(self::of($item), $values), fn ($v) => $v !== null && $v !== '' && $v !== []);
        ksort($merged);
        $item->setRawAttributes(array_merge($item->getAttributes(), [
            'identifiers' => $merged === [] ? null : json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    /** A valid package URL, or null. */
    public static function purl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= 500 && preg_match(self::PURL_PATTERN, $value) ? $value : null;
    }

    /** A CPE 2.3 formatted string (a CPE 2.2 URI is converted), or null. */
    public static function cpe(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^cpe:\/([aho]):(.+)$/i', $value, $m)) {
            $parts = array_map(fn ($p) => $p === '' ? '*' : rawurldecode($p), explode(':', $m[2]));
            $parts = array_slice(array_pad($parts, 10, '*'), 0, 10);
            $value = 'cpe:2.3:'.strtolower($m[1]).':'.implode(':', $parts);
        }
        if ($value === '' || strlen($value) > 300 || ! preg_match(self::CPE_PATTERN, $value)) {
            return null;
        }
        $parts = explode(':', $value);

        return implode(':', array_pad($parts, 13, '*'));
    }

    /** @return array{type: string, namespace: string|null, name: string, version: string|null}|null */
    public static function parsePurl(string $purl): ?array
    {
        if (! preg_match('/^pkg:([^\/]+)\/([^?#]+)/i', $purl, $m)) {
            return null;
        }
        $path = $m[2];
        $version = null;
        if (($at = strrpos($path, '@')) !== false) {
            $version = rawurldecode(substr($path, $at + 1));
            $path = substr($path, 0, $at);
        }
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        if ($segments === []) {
            return null;
        }
        $name = rawurldecode((string) array_pop($segments));

        return [
            'type' => strtolower($m[1]),
            'namespace' => $segments === [] ? null : rawurldecode(implode('/', $segments)),
            'name' => $name,
            'version' => $version === '' ? null : $version,
        ];
    }

    public static function nameFromPurl(string $purl): ?string
    {
        $parsed = self::parsePurl($purl);

        return $parsed ? mb_substr($parsed['name'], 0, 200) : null;
    }

    public static function versionFromPurl(string $purl): ?string
    {
        return self::parsePurl($purl)['version'] ?? null;
    }

    /** The package URL without version, qualifiers or subpath: the same package in any version. */
    public static function purlBase(string $purl): string
    {
        $parsed = self::parsePurl($purl);
        if (! $parsed) {
            return strtolower($purl);
        }

        return strtolower('pkg:'.$parsed['type'].'/'.($parsed['namespace'] ? $parsed['namespace'].'/' : '').$parsed['name']);
    }

    /** The purl with this version (replacing any). */
    public static function purlWithVersion(string $purl, string $version): string
    {
        if (! preg_match('/^([^?#]+?)(@[^?#]*)?([?#].*)?$/', $purl, $m)) {
            return $purl;
        }

        return $m[1].'@'.rawurlencode($version).($m[3] ?? '');
    }

    /** @return array{part: string, vendor: string, product: string, version: string|null}|null */
    public static function parseCpe(string $cpe): ?array
    {
        $parts = preg_split('/(?<!\\\\):/', $cpe) ?: [];
        if (count($parts) < 6 || $parts[0] !== 'cpe' || $parts[1] !== '2.3') {
            return null;
        }
        $version = $parts[5];

        return [
            'part' => $parts[2],
            'vendor' => stripslashes($parts[3]),
            'product' => stripslashes($parts[4]),
            'version' => in_array($version, ['*', '-', ''], true) ? null : stripslashes($version),
        ];
    }
}
