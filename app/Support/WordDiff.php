<?php

namespace App\Support;

/**
 * A readable word-level diff of two texts (longest common subsequence of the words).
 *
 * Returns segments: `same` (kept), `del` (removed), `ins` (added) and `gap` (unchanged words left
 * out). Long unchanged runs keep only `$context` words around each change, long removed or added
 * runs are cut at `$maxChars`, and when the changed middle is too large for the LCS table
 * (`$maxCells`) it is shown as one removal and one addition.
 */
final class WordDiff
{
    /**
     * @return list<array{type: 'same'|'del'|'ins'|'gap', text: string}>
     */
    public static function segments(?string $old, ?string $new, int $context = 12, int $maxChars = 800, int $maxCells = 160_000): array
    {
        $a = self::tokens((string) $old);
        $b = self::tokens((string) $new);

        $prefix = 0;
        while ($prefix < count($a) && $prefix < count($b) && self::key($a[$prefix]) === self::key($b[$prefix])) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < count($a) - $prefix && $suffix < count($b) - $prefix
            && self::key($a[count($a) - 1 - $suffix]) === self::key($b[count($b) - 1 - $suffix])) {
            $suffix++;
        }

        $midA = array_slice($a, $prefix, count($a) - $prefix - $suffix);
        $midB = array_slice($b, $prefix, count($b) - $prefix - $suffix);

        /** @var list<array{0: 'same'|'del'|'ins', 1: string}> $ops */
        $ops = [];
        foreach (array_slice($b, 0, $prefix) as $token) {
            $ops[] = ['same', $token];
        }
        if (count($midA) * count($midB) <= $maxCells) {
            array_push($ops, ...self::lcs($midA, $midB));
        } else {
            foreach ($midA as $token) {
                $ops[] = ['del', $token];
            }
            foreach ($midB as $token) {
                $ops[] = ['ins', $token];
            }
        }
        foreach (array_slice($b, count($b) - $suffix) as $token) {
            $ops[] = ['same', $token];
        }

        return self::shape($ops, $context, $maxChars);
    }

    /** @return list<string> words (letters, digits, inner - / ' ’) or single signs, with their trailing white space */
    private static function tokens(string $text): array
    {
        preg_match_all("/[\\p{L}\\p{N}](?:[\\p{L}\\p{N}_'’\\/.-]*[\\p{L}\\p{N}])?\\s*|[^\\p{L}\\p{N}\\s]\\s*/u", ltrim($text), $matches);

        return $matches[0];
    }

    private static function key(string $token): string
    {
        return rtrim($token);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: 'same'|'del'|'ins', 1: string}>
     */
    private static function lcs(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $w = $m + 1;
        $len = array_fill(0, ($n + 1) * $w, 0);
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $len[$i * $w + $j] = self::key($a[$i]) === self::key($b[$j])
                    ? $len[($i + 1) * $w + $j + 1] + 1
                    : max($len[($i + 1) * $w + $j], $len[$i * $w + $j + 1]);
            }
        }

        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if (self::key($a[$i]) === self::key($b[$j])) {
                $ops[] = ['same', $b[$j]];
                $i++;
                $j++;
            } elseif ($len[($i + 1) * $w + $j] >= $len[$i * $w + $j + 1]) {
                $ops[] = ['del', $a[$i++]];
            } else {
                $ops[] = ['ins', $b[$j++]];
            }
        }
        while ($i < $n) {
            $ops[] = ['del', $a[$i++]];
        }
        while ($j < $m) {
            $ops[] = ['ins', $b[$j++]];
        }

        return $ops;
    }

    /**
     * Groups the word operations: removals before additions within a change, unchanged runs
     * shortened to their context, long changes cut.
     *
     * @param  list<array{0: 'same'|'del'|'ins', 1: string}>  $ops
     * @return list<array{type: 'same'|'del'|'ins'|'gap', text: string}>
     */
    private static function shape(array $ops, int $context, int $maxChars): array
    {
        /** @var list<array{type: 'same'|'change', words: list<string>, del: list<string>, ins: list<string>}> $runs */
        $runs = [];
        foreach ($ops as [$type, $token]) {
            $last = array_key_last($runs);
            if ($type === 'same') {
                if ($last !== null && $runs[$last]['type'] === 'same') {
                    $runs[$last]['words'][] = $token;
                } else {
                    $runs[] = ['type' => 'same', 'words' => [$token], 'del' => [], 'ins' => []];
                }

                continue;
            }
            if ($last === null || $runs[$last]['type'] !== 'change') {
                $runs[] = ['type' => 'change', 'words' => [], 'del' => [], 'ins' => []];
                $last = array_key_last($runs);
            }
            $runs[$last][$type][] = $token;
        }

        $segments = [];
        $count = count($runs);
        foreach ($runs as $index => $run) {
            if ($run['type'] === 'change') {
                // Removed then added, a space between them, then the white space that followed.
                foreach (['del', 'ins'] as $type) {
                    if ($run[$type] === []) {
                        continue;
                    }
                    $text = implode('', $run[$type]);
                    // A word right after a word (the kept one lost its space): keep them apart.
                    $before = end($segments);
                    if ($before !== false && preg_match('/[\p{L}\p{N}]$/u', $before['text']) && preg_match('/^[\p{L}\p{N}]/u', $text)) {
                        $segments[] = ['type' => 'same', 'text' => ' '];
                    }
                    $segments[] = ['type' => $type, 'text' => self::cut(rtrim($text), $maxChars)];
                    $segments[] = ['type' => 'same', 'text' => $type === 'del' && $run['ins'] !== [] ? ' ' : substr($text, strlen(rtrim($text)))];
                }

                continue;
            }

            $words = $run['words'];
            $first = $index === 0;
            $lastRun = $index === $count - 1;
            if ($count === 1) {
                $segments[] = ['type' => 'same', 'text' => implode('', $words)];
            } elseif ($first && count($words) > $context) {
                $segments[] = ['type' => 'gap', 'text' => '…'];
                $segments[] = ['type' => 'same', 'text' => ' '.implode('', array_slice($words, -$context))];
            } elseif ($lastRun && count($words) > $context) {
                $segments[] = ['type' => 'same', 'text' => rtrim(implode('', array_slice($words, 0, $context))).' '];
                $segments[] = ['type' => 'gap', 'text' => '…'];
            } elseif (! $first && ! $lastRun && count($words) > 2 * $context + 2) {
                $segments[] = ['type' => 'same', 'text' => rtrim(implode('', array_slice($words, 0, $context))).' '];
                $segments[] = ['type' => 'gap', 'text' => '…'];
                $segments[] = ['type' => 'same', 'text' => ' '.implode('', array_slice($words, -$context))];
            } else {
                $segments[] = ['type' => 'same', 'text' => implode('', $words)];
            }
        }

        // Merge neighbouring unchanged text (white space after changes), without doubled spaces.
        $merged = [];
        foreach ($segments as $segment) {
            if ($segment['type'] === 'same' && $segment['text'] === '') {
                continue;
            }
            $last = array_key_last($merged);
            if ($last !== null && $segment['type'] === 'same' && $merged[$last]['type'] === 'same') {
                $tail = $merged[$last]['text'];
                $merged[$last]['text'] = $tail.(preg_match('/\s$/u', $tail) ? ltrim($segment['text'], ' ') : $segment['text']);
            } else {
                $merged[] = $segment;
            }
        }
        $last = array_key_last($merged);
        if ($last !== null && $merged[$last]['type'] === 'same') {
            $merged[$last]['text'] = rtrim($merged[$last]['text']);
            if ($merged[$last]['text'] === '') {
                array_pop($merged);
            }
        }

        return $merged;
    }

    private static function cut(string $text, int $maxChars): string
    {
        return mb_strlen($text) > $maxChars ? rtrim(mb_substr($text, 0, $maxChars)).'…' : $text;
    }
}
