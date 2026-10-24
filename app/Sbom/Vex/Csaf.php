<?php

namespace App\Sbom\Vex;

use App\Sbom\SbomException;

/**
 * CSAF 2.0, VEX profile (document.category "csaf_vex"): vulnerabilities[] with cve / ids,
 * product_status (known_affected, known_not_affected, fixed, under_investigation, ...), flags
 * (the justification of "not affected"), threats of category "impact" (why) and remediations
 * (what to do), each for product ids of the product tree. A relationship product ("component
 * X as part of product Y") is about component X.
 */
final class Csaf
{
    private const MAX_DEPTH = 12;

    private const STATUS = [
        'known_not_affected' => 'not_affected',
        'known_affected' => 'affected', 'first_affected' => 'affected', 'last_affected' => 'affected',
        'fixed' => 'fixed', 'first_fixed' => 'fixed',
        'under_investigation' => 'under_investigation',
    ];

    /** @param array<mixed> $data */
    public static function is(array $data): bool
    {
        return is_array($data['document'] ?? null) && isset($data['document']['csaf_version']);
    }

    /**
     * @param  array<mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function fromJson(array $data, string $file): array
    {
        if (($data['document']['category'] ?? null) !== 'csaf_vex') {
            throw SbomException::because('csaf_not_vex', ['name' => $file]);
        }
        $products = [];
        $tree = is_array($data['product_tree'] ?? null) ? $data['product_tree'] : [];
        foreach (is_array($tree['branches'] ?? null) ? $tree['branches'] : [] as $branch) {
            if (is_array($branch)) {
                self::branch($branch, $products, [], 0);
            }
        }
        foreach (is_array($tree['full_product_names'] ?? null) ? $tree['full_product_names'] : [] as $p) {
            if (is_array($p)) {
                self::product($p, $products, []);
            }
        }
        foreach (is_array($tree['relationships'] ?? null) ? $tree['relationships'] : [] as $r) {
            $id = is_array($r) ? ($r['full_product_name']['product_id'] ?? null) : null;
            $component = is_array($r) ? ($r['product_reference'] ?? null) : null;
            if (is_string($id) && is_string($component) && isset($products[$component])) {
                $products[$id] = $products[$component];
            }
        }

        $out = [];
        foreach (is_array($data['vulnerabilities'] ?? null) ? $data['vulnerabilities'] : [] as $v) {
            if (! is_array($v)) {
                continue;
            }
            $aliases = [];
            foreach (is_array($v['ids'] ?? null) ? $v['ids'] : [] as $i) {
                $aliases[] = is_array($i) ? ($i['text'] ?? null) : null;
            }
            $flags = self::byProduct($v['flags'] ?? null, fn (array $f) => $f['label'] ?? null);
            $impacts = self::byProduct(array_filter(is_array($v['threats'] ?? null) ? $v['threats'] : [], fn ($t) => is_array($t) && ($t['category'] ?? null) === 'impact'), fn (array $t) => $t['details'] ?? null);
            $remediations = self::byProduct($v['remediations'] ?? null, fn (array $r) => $r['details'] ?? null);

            foreach (is_array($v['product_status'] ?? null) ? $v['product_status'] : [] as $kind => $ids) {
                $status = self::STATUS[$kind] ?? null;
                if ($status === null || ! is_array($ids)) {
                    continue;
                }
                foreach ($ids as $pid) {
                    if (! is_string($pid)) {
                        continue;
                    }
                    $statement = Statement::make($v['cve'] ?? ($aliases[0] ?? null), $aliases, $status, $flags[$pid] ?? null,
                        $impacts[$pid] ?? null, $remediations[$pid] ?? null, $products[$pid] ?? ['ref' => $pid], $file, 'csaf', $v['title'] ?? null);
                    if ($statement) {
                        $out[] = $statement;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $branch
     * @param  array<string, array<string, mixed>>  $products
     * @param  array<string, string>  $carry  vendor / product name / version from the branches above
     */
    private static function branch(array $branch, array &$products, array $carry, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        $name = is_string($branch['name'] ?? null) ? $branch['name'] : null;
        if ($name !== null) {
            match ($branch['category'] ?? null) {
                'product_name', 'product_family' => $carry['name'] = $name,
                'product_version', 'product_version_range' => $carry['version'] = $name,
                default => null,
            };
        }
        if (is_array($branch['product'] ?? null)) {
            self::product($branch['product'], $products, $carry);
        }
        foreach (is_array($branch['branches'] ?? null) ? $branch['branches'] : [] as $child) {
            if (is_array($child)) {
                self::branch($child, $products, $carry, $depth + 1);
            }
        }
    }

    /**
     * @param  array<mixed>  $p
     * @param  array<string, array<string, mixed>>  $products
     * @param  array<string, string>  $carry
     */
    private static function product(array $p, array &$products, array $carry): void
    {
        $id = $p['product_id'] ?? null;
        if (! is_string($id)) {
            return;
        }
        $helper = is_array($p['product_identification_helper'] ?? null) ? $p['product_identification_helper'] : [];
        $products[$id] = [
            'purl' => $helper['purl'] ?? null,
            'name' => $carry['name'] ?? ($p['name'] ?? null),
            'version' => $carry['version'] ?? null,
            'ref' => $id,
        ];
    }

    /**
     * @param  callable(array<mixed>): mixed  $value
     * @return array<string, string> product id => text
     */
    private static function byProduct(mixed $entries, callable $value): array
    {
        $out = [];
        foreach (is_array($entries) ? $entries : [] as $e) {
            $text = is_array($e) ? $value($e) : null;
            if (! is_string($text) || trim($text) === '') {
                continue;
            }
            foreach (is_array($e['product_ids'] ?? null) ? $e['product_ids'] : [] as $pid) {
                if (is_string($pid)) {
                    $out[$pid] = isset($out[$pid]) ? $out[$pid]."\n".trim($text) : trim($text);
                }
            }
        }

        return $out;
    }
}
