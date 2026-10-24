<?php

namespace App\Vulnerabilities;

/**
 * Loose version comparison for name-and-version matches (EUVD gives affected versions as free
 * text such as "3.0.0 <3.0.20" or "Fixed in 1.0.2p (Affected 1.0.2b-1.0.2o)"). Unknown means
 * "cannot tell", which the checker treats as no match, so the list stays short; anything it
 * keeps is shown as a possible match for a person to confirm.
 */
final class VersionRange
{
    /** -1, 0, 1 like version_compare, but tolerant of letters (1.0.2b), "v" prefixes and dashes. */
    public static function compare(string $a, string $b): int
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        $n = max(count($ta), count($tb));
        for ($i = 0; $i < $n; $i++) {
            $cmp = self::token($ta[$i] ?? null, $tb[$i] ?? null);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    private const PRE_RELEASE = ['alpha', 'a', 'beta', 'b', 'rc', 'pre', 'preview', 'dev', 'snapshot', 'm'];

    private static function token(int|string|null $x, int|string|null $y): int
    {
        if ($x === $y) {
            return 0;
        }
        if ($x === null) {
            // 1.0 < 1.0.1, 1.0rc1 < 1.0, 1.0.2 < 1.0.2b (OpenSSL letter releases)
            return is_string($y) && in_array($y, self::PRE_RELEASE, true) && ! self::isLetterRelease($y) ? 1 : -1;
        }
        if ($y === null) {
            return -self::token($y, $x);
        }
        if (is_int($x) && is_int($y)) {
            return $x <=> $y;
        }
        if (is_int($x) !== is_int($y)) {
            return is_int($x) ? 1 : -1;
        }

        return strcmp((string) $x, (string) $y) <=> 0;
    }

    /** Single letters after a number are later releases (1.0.2b), except a/b followed by digits handled as pre-release words. */
    private static function isLetterRelease(string $token): bool
    {
        return strlen($token) === 1 && $token !== 'm';
    }

    /** @return list<int|string> */
    private static function tokens(string $version): array
    {
        $version = strtolower(trim($version));
        $version = (string) preg_replace('/^v(?=\d)/', '', $version);
        preg_match_all('/\d+|[a-z]+/', $version, $m);

        return array_map(fn ($t) => ctype_digit($t) ? (int) $t : $t, $m[0]);
    }

    /** Does this free-text affected-version statement cover $version? null: cannot tell. */
    public static function covers(string $statement, string $version): ?bool
    {
        $s = trim($statement);
        $version = trim($version);
        if ($s === '' || $version === '') {
            return null;
        }
        // "(Affected 1.0.2b-1.0.2o)"
        if (preg_match('/affected\s+([0-9][\w.\-]*?)\s*-\s*([0-9][\w.\-]*)/i', $s, $m)) {
            return self::compare($version, $m[1]) >= 0 && self::compare($version, $m[2]) <= 0;
        }
        // "3.0.0 <3.0.20", "0 <=2.4", "<3.0.20", ">=1.2 <1.4"
        if (preg_match('/^(?:>=?\s*)?([0-9][\w.\-+]*)?\s*(<=?)\s*([0-9][\w.\-+]*)$/', $s, $m)) {
            $from = $m[1];
            if ($from !== '' && $from !== '0' && self::compare($version, $from) < 0) {
                return false;
            }
            $cmp = self::compare($version, $m[3]);

            return $m[2] === '<=' ? $cmp <= 0 : $cmp < 0;
        }
        // "3.0.x", "3.0.*"
        if (preg_match('/^([0-9][\w.]*?)\.[x*]$/i', $s, $m)) {
            return str_starts_with($version.'.', $m[1].'.');
        }
        // A plain version: that one only.
        if (preg_match('/^v?[0-9][\w.\-+]*$/i', $s)) {
            return self::compare($version, $s) === 0;
        }

        return null;
    }

    /** The "fixed in" version a statement gives ("<3.0.20" → 3.0.20, "Fixed in 1.0.2p-dev" → 1.0.2p-dev). */
    public static function fixedIn(string $statement): ?string
    {
        if (preg_match('/<\s*([0-9][\w.\-+]*)\s*$/', trim($statement), $m)) {
            return $m[1];
        }
        if (preg_match('/fixed in\s+(?:[A-Za-z][\w\-]*\s+)?v?([0-9][\w.\-+]*)/i', $statement, $m)) {
            return $m[1];
        }

        return null;
    }
}
