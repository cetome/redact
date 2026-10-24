<?php

namespace App\Vulnerabilities;

/**
 * CVSS helpers: the v3.0/3.1 base score from a vector (OSV gives vectors, not scores) and
 * the qualitative severity of a score (FIRST rating scale).
 */
final class Cvss
{
    private const WEIGHTS = [
        'AV' => ['N' => 0.85, 'A' => 0.62, 'L' => 0.55, 'P' => 0.2],
        'AC' => ['L' => 0.77, 'H' => 0.44],
        'UI' => ['N' => 0.85, 'R' => 0.62],
        'C' => ['H' => 0.56, 'L' => 0.22, 'N' => 0.0],
        'I' => ['H' => 0.56, 'L' => 0.22, 'N' => 0.0],
        'A' => ['H' => 0.56, 'L' => 0.22, 'N' => 0.0],
    ];

    public static function scoreV3(string $vector): ?float
    {
        if (! preg_match('/^CVSS:3\.[01]\//', $vector)) {
            return null;
        }
        $m = [];
        foreach (explode('/', substr($vector, 9)) as $part) {
            [$k, $v] = array_pad(explode(':', $part, 2), 2, '');
            $m[$k] = $v;
        }
        foreach (['AV', 'AC', 'PR', 'UI', 'S', 'C', 'I', 'A'] as $required) {
            if (! isset($m[$required])) {
                return null;
            }
        }
        $changed = $m['S'] === 'C';
        $pr = match ($m['PR']) {
            'N' => 0.85,
            'L' => $changed ? 0.68 : 0.62,
            'H' => $changed ? 0.5 : 0.27,
            default => null,
        };
        $w = fn (string $k) => self::WEIGHTS[$k][$m[$k]] ?? null;
        if ($pr === null || in_array(null, [$w('AV'), $w('AC'), $w('UI'), $w('C'), $w('I'), $w('A')], true)) {
            return null;
        }
        $iss = 1 - ((1 - $w('C')) * (1 - $w('I')) * (1 - $w('A')));
        $impact = $changed ? 7.52 * ($iss - 0.029) - 3.25 * (($iss - 0.02) ** 15) : 6.42 * $iss;
        $exploitability = 8.22 * $w('AV') * $w('AC') * $pr * $w('UI');
        if ($impact <= 0) {
            return 0.0;
        }

        return $changed
            ? self::roundUp(min(1.08 * ($impact + $exploitability), 10))
            : self::roundUp(min($impact + $exploitability, 10));
    }

    /** CVSS v3.1 Roundup (avoids floating-point surprises). */
    private static function roundUp(float $value): float
    {
        $int = (int) round($value * 100000);

        return $int % 10000 === 0 ? $int / 100000.0 : (floor($int / 10000) + 1) / 10.0;
    }

    public static function severity(?float $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score >= 9.0 => 'critical',
            $score >= 7.0 => 'high',
            $score >= 4.0 => 'medium',
            $score > 0.0 => 'low',
            default => 'none',
        };
    }

    /** Source wording (GitHub "MODERATE", NVD "HIGH") to ours. */
    public static function normaliseSeverity(mixed $label): ?string
    {
        $label = strtolower(trim((string) (is_scalar($label) ? $label : '')));

        return match ($label) {
            'critical' => 'critical',
            'high', 'important' => 'high',
            'moderate', 'medium' => 'medium',
            'low' => 'low',
            default => null,
        };
    }
}
