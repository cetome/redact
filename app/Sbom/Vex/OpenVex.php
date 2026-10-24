<?php

namespace App\Sbom\Vex;

/**
 * OpenVEX 0.2 (and the 0.0.x drafts): statements with vulnerability.name (or a plain string),
 * products[] (@id, usually a purl, identifiers.purl, subcomponents[]), status, justification,
 * impact_statement, action_statement. A product with subcomponents is about those components.
 */
final class OpenVex
{
    /** @param array<mixed> $data */
    public static function is(array $data): bool
    {
        $context = $data['@context'] ?? null;

        return (is_string($context) && str_contains($context, 'openvex'))
            || (is_array($data['statements'] ?? null) && isset($data['@id']) && ! isset($data['bomFormat']));
    }

    /**
     * @param  array<mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function fromJson(array $data, string $file): array
    {
        $out = [];
        foreach (is_array($data['statements'] ?? null) ? $data['statements'] : [] as $s) {
            if (! is_array($s)) {
                continue;
            }
            $vulnerability = $s['vulnerability'] ?? null;
            $id = is_array($vulnerability) ? ($vulnerability['name'] ?? $vulnerability['@id'] ?? null) : $vulnerability;
            $aliases = is_array($vulnerability) && is_array($vulnerability['aliases'] ?? null) ? $vulnerability['aliases'] : [];
            $title = is_array($vulnerability) ? ($vulnerability['description'] ?? null) : null;
            $detail = trim(implode("\n", array_filter([
                is_string($s['impact_statement'] ?? null) ? $s['impact_statement'] : null,
                is_string($s['status_notes'] ?? null) ? $s['status_notes'] : null,
            ])));

            $products = [];
            foreach (is_array($s['products'] ?? null) ? $s['products'] : [] as $p) {
                if (is_string($p)) {
                    $products[] = self::product($p, null);

                    continue;
                }
                if (! is_array($p)) {
                    continue;
                }
                $subs = array_filter(is_array($p['subcomponents'] ?? null) ? $p['subcomponents'] : [], fn ($c) => is_array($c) || is_string($c));
                foreach ($subs ?: [$p] as $c) {
                    $products[] = is_string($c) ? self::product($c, null) : self::product($c['@id'] ?? null, $c['identifiers']['purl'] ?? null);
                }
            }
            foreach ($products ?: [['root' => true]] as $product) {
                $statement = Statement::make($id, $aliases, $s['status'] ?? null, $s['justification'] ?? null, $detail,
                    $s['action_statement'] ?? null, $product, $file, 'openvex', $title);
                if ($statement) {
                    $out[] = $statement;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function product(mixed $id, mixed $purl): array
    {
        $id = is_string($id) ? trim($id) : null;
        $purl = is_string($purl) ? $purl : ($id !== null && str_starts_with($id, 'pkg:') ? $id : null);

        return ['purl' => $purl, 'ref' => $id];
    }
}
