<?php

namespace App\Sbom\Vex;

use App\Sbom\Component;
use App\Sbom\Identifiers;

/**
 * One VEX statement, whatever the format it came from: a vulnerability, the product or
 * component it is about, and the manufacturer's analysis (status, justification, detail,
 * action). Kept as a plain array (JSON column of vex_imports) and cleaned here.
 *
 * Statuses: not_affected, affected, fixed, under_investigation (OpenVEX / CSAF words; the
 * CycloneDX analysis states are mapped onto them).
 */
final class Statement
{
    public const STATUSES = ['not_affected', 'affected', 'fixed', 'under_investigation'];

    /** CycloneDX 1.4–1.6 justifications, OpenVEX 0.2 / CSAF 2.0 flags, and the CycloneDX "false positive" state. */
    public const JUSTIFICATIONS = [
        'code_not_present', 'code_not_reachable', 'requires_configuration', 'requires_dependency', 'requires_environment',
        'protected_by_compiler', 'protected_at_runtime', 'protected_at_perimeter', 'protected_by_mitigating_control',
        'component_not_present', 'vulnerable_code_not_present', 'vulnerable_code_not_in_execute_path',
        'vulnerable_code_cannot_be_controlled_by_adversary', 'inline_mitigations_already_exist', 'false_positive',
    ];

    /**
     * @param  list<mixed>  $aliases
     * @param  array{purl?: mixed, name?: mixed, version?: mixed, ref?: mixed, root?: bool}  $product
     * @return array<string, mixed>|null
     */
    public static function make(mixed $id, array $aliases, mixed $status, mixed $justification, mixed $detail, mixed $action, array $product, string $file, string $format, mixed $title = null): ?array
    {
        $ids = [];
        foreach ([$id, ...$aliases] as $candidate) {
            $candidate = Component::clean($candidate, 80);
            if ($candidate !== null && preg_match('/^[A-Za-z][A-Za-z0-9._:-]{2,79}$/', $candidate)) {
                $ids[strtoupper($candidate)] = str_starts_with(strtoupper($candidate), 'CVE-') ? strtoupper($candidate) : $candidate;
            }
        }
        $status = is_string($status) && in_array($status, self::STATUSES, true) ? $status : null;
        if ($ids === [] || $status === null) {
            return null;
        }
        $ids = array_values($ids);
        // The CVE is the key when there is one (the lookups use it too).
        $cve = array_values(array_filter($ids, fn ($i) => str_starts_with($i, 'CVE-')));
        $key = $cve[0] ?? $ids[0];

        $justification = is_string($justification) ? strtolower(trim($justification)) : null;
        $justification = $justification !== null && preg_match('/^[a-z_]{3,60}$/', $justification) ? $justification : null;

        $purl = Identifiers::purl($product['purl'] ?? null);

        return [
            'id' => $key,
            'aliases' => array_values(array_diff($ids, [$key])),
            'title' => Component::clean($title, 300),
            'status' => $status,
            'justification' => $justification,
            'detail' => Component::clean($detail, 4000, true),
            'action' => Component::clean($action, 4000, true),
            'file' => mb_substr($file, 0, 200),
            'format' => $format,
            'product' => array_filter([
                'purl' => $purl,
                'name' => Component::clean($product['name'] ?? null, 200) ?? ($purl ? Identifiers::nameFromPurl($purl) : null),
                'version' => Component::clean($product['version'] ?? null, 100) ?? ($purl ? Identifiers::versionFromPurl($purl) : null),
                'ref' => Component::clean($product['ref'] ?? null, 300),
                'root' => ($product['root'] ?? false) ?: null,
            ], fn ($v) => $v !== null),
        ];
    }

    /** The same statement twice (same vulnerability, same product): kept once, the last one wins. */
    public static function key(array $statement): string
    {
        $p = $statement['product'] ?? [];

        return $statement['id'].'|'.strtolower((string) ($p['purl'] ?? '')).'|'.Component::keyOf($p['name'] ?? null, $p['version'] ?? null).'|'.($p['ref'] ?? '');
    }

    /** Not affected with a reason, or fixed: the manufacturer analysed it. Affected or under investigation stays to do. */
    public static function analysed(string $status, ?string $justification, ?string $detail): bool
    {
        return $status === 'fixed' || ($status === 'not_affected' && (filled($justification) || filled($detail)));
    }

    public static function justificationLabel(?string $justification): ?string
    {
        if ($justification === null || $justification === '') {
            return null;
        }
        $key = 'sbom.vex.justification.'.$justification;
        $label = __($key);

        return $label === $key ? ucfirst(str_replace('_', ' ', $justification)) : $label;
    }

    /** CycloneDX analysis state → VEX status (no state: under investigation, it stays to do). */
    public static function fromCycloneDxState(?string $state): string
    {
        return match ($state) {
            'not_affected', 'false_positive' => 'not_affected',
            'resolved', 'resolved_with_pedigree' => 'fixed',
            'exploitable' => 'affected',
            default => 'under_investigation',
        };
    }
}
