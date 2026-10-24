<?php

namespace App\Sbom\Formats;

use App\Sbom\Component;

/**
 * SPDX 2.2 / 2.3 (JSON and tag-value) and SPDX 3.0 (JSON-LD). Packages become components;
 * the package the document DESCRIBES is the product itself (root). primaryPackagePurpose
 * DEVICE (2.3) or software_primaryPurpose "device" (3.0) is hardware.
 */
final class Spdx
{
    /** @param array<mixed> $data */
    public static function isJson(array $data): bool
    {
        return is_string($data['spdxVersion'] ?? null) && str_starts_with($data['spdxVersion'], 'SPDX-2');
    }

    /**
     * @param  array<mixed>  $data
     * @return list<Component>
     */
    public static function fromJson(array $data): array
    {
        $described = array_filter((array) ($data['documentDescribes'] ?? []), 'is_string');
        foreach (is_array($data['relationships'] ?? null) ? $data['relationships'] : [] as $r) {
            if (is_array($r) && ($r['relationshipType'] ?? null) === 'DESCRIBES' && ($r['spdxElementId'] ?? null) === 'SPDXRef-DOCUMENT') {
                $described[] = (string) ($r['relatedSpdxElement'] ?? '');
            }
        }
        $described = array_flip($described);

        $out = [];
        foreach (is_array($data['packages'] ?? null) ? $data['packages'] : [] as $p) {
            if (! is_array($p)) {
                continue;
            }
            $purl = $cpe = null;
            foreach (is_array($p['externalRefs'] ?? null) ? $p['externalRefs'] : [] as $ref) {
                if (! is_array($ref)) {
                    continue;
                }
                $type = strtolower((string) ($ref['referenceType'] ?? ''));
                $purl ??= $type === 'purl' ? ($ref['referenceLocator'] ?? null) : null;
                $cpe ??= in_array($type, ['cpe23type', 'cpe22type'], true) ? ($ref['referenceLocator'] ?? null) : null;
            }
            $component = Component::make(
                name: $p['name'] ?? null,
                version: $p['versionInfo'] ?? null,
                supplier: self::party($p['supplier'] ?? $p['originator'] ?? null),
                type: strtoupper((string) ($p['primaryPackagePurpose'] ?? '')) === 'DEVICE' ? 'hardware' : 'software',
                purl: $purl,
                cpe: $cpe,
                licence: self::licence($p['licenseConcluded'] ?? null) ?? self::licence($p['licenseDeclared'] ?? null),
                description: $p['description'] ?? $p['summary'] ?? null,
                ref: $p['SPDXID'] ?? null,
                root: isset($described[(string) ($p['SPDXID'] ?? '')]),
            );
            if ($component) {
                $out[] = $component;
            }
        }

        return $out;
    }

    public static function isTagValue(string $content): bool
    {
        return (bool) preg_match('/^\s*SPDXVersion:\s*SPDX-2\./m', $content);
    }

    /** @return list<Component> */
    public static function fromTagValue(string $content): array
    {
        $packages = [];
        $current = null;
        $described = [];
        $inText = false;
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            // Multi-line <text>…</text> values (descriptions, comments) are skipped.
            if ($inText) {
                $inText = ! str_contains($line, '</text>');

                continue;
            }
            if (! preg_match('/^\s*([A-Za-z]+):\s?(.*)$/', $line, $m)) {
                continue;
            }
            [$tag, $value] = [$m[1], trim($m[2])];
            if (str_starts_with($value, '<text>')) {
                $single = str_contains($value, '</text>');
                $inText = ! $single;
                $value = $single ? trim(str_replace(['<text>', '</text>'], '', $value)) : '';
            }
            if ($tag === 'PackageName') {
                if ($current !== null) {
                    $packages[] = $current;
                }
                $current = ['name' => $value];

                continue;
            }
            if ($tag === 'Relationship' && preg_match('/^SPDXRef-DOCUMENT\s+DESCRIBES\s+(\S+)/', $value, $r)) {
                $described[$r[1]] = true;
            }
            if ($tag === 'DocumentDescribes') {
                foreach (preg_split('/[\s,]+/', $value) ?: [] as $id) {
                    $described[$id] = true;
                }
            }
            if ($current === null) {
                continue;
            }
            if (in_array($tag, ['FileName', 'SnippetSPDXID'], true)) {
                $packages[] = $current;
                $current = null;

                continue;
            }
            match ($tag) {
                'SPDXID' => $current['id'] ??= $value,
                'PackageVersion' => $current['version'] = $value,
                'PackageSupplier' => $current['supplier'] = $value,
                'PackageOriginator' => $current['originator'] = $value,
                'PackageLicenseConcluded' => $current['licence'] = $value,
                'PackageLicenseDeclared' => $current['declared'] = $value,
                'PackageSummary' => $current['summary'] = $value,
                'PackageDescription' => $current['description'] = $value,
                'PrimaryPackagePurpose' => $current['purpose'] = $value,
                'ExternalRef' => self::externalRef($current, $value),
                default => null,
            };
        }
        if ($current !== null) {
            $packages[] = $current;
        }

        $out = [];
        foreach ($packages as $p) {
            $component = Component::make(
                name: $p['name'] ?? null,
                version: $p['version'] ?? null,
                supplier: self::party($p['supplier'] ?? $p['originator'] ?? null),
                type: strtoupper($p['purpose'] ?? '') === 'DEVICE' ? 'hardware' : 'software',
                purl: $p['purl'] ?? null,
                cpe: $p['cpe'] ?? null,
                licence: self::licence($p['licence'] ?? null) ?? self::licence($p['declared'] ?? null),
                description: $p['description'] ?? $p['summary'] ?? null,
                ref: $p['id'] ?? null,
                root: isset($described[$p['id'] ?? '']),
            );
            if ($component) {
                $out[] = $component;
            }
        }

        return $out;
    }

    /** @param array<string, string> $package */
    private static function externalRef(array &$package, string $value): null
    {
        $parts = preg_split('/\s+/', $value, 3) ?: [];
        $type = strtolower($parts[1] ?? '');
        if ($type === 'purl') {
            $package['purl'] ??= $parts[2] ?? '';
        } elseif (in_array($type, ['cpe23type', 'cpe22type'], true)) {
            $package['cpe'] ??= $parts[2] ?? '';
        }

        return null;
    }

    /** @param array<mixed> $data */
    public static function isJsonLd(array $data): bool
    {
        $context = $data['@context'] ?? null;
        $context = is_array($context) ? implode(' ', array_filter($context, 'is_string')) : (string) $context;

        return str_contains($context, 'spdx.org/rdf/3') && is_array($data['@graph'] ?? null);
    }

    /**
     * @param  array<mixed>  $data
     * @return list<Component>
     */
    public static function fromJsonLd(array $data): array
    {
        $graph = array_values(array_filter($data['@graph'], 'is_array'));
        $byId = [];
        foreach ($graph as $element) {
            $id = $element['spdxId'] ?? $element['@id'] ?? null;
            if (is_string($id)) {
                $byId[$id] = $element;
            }
        }
        $licences = [];
        $described = [];
        foreach ($graph as $element) {
            $type = self::type($element);
            if ($type === 'Relationship') {
                $kind = (string) ($element['relationshipType'] ?? '');
                $from = (string) ($element['from'] ?? '');
                foreach ((array) ($element['to'] ?? []) as $to) {
                    if (in_array($kind, ['hasConcludedLicense', 'hasDeclaredLicense'], true) && is_string($to)) {
                        $licences[$from] ??= $byId[$to]['simplelicensing_licenseExpression'] ?? null;
                    }
                }
            }
            if (in_array($type, ['SpdxDocument', 'software_Sbom', 'Bom'], true)) {
                foreach ((array) ($element['rootElement'] ?? []) as $root) {
                    if (is_string($root)) {
                        $described[$root] = true;
                    }
                }
            }
        }

        $out = [];
        foreach ($graph as $element) {
            if (self::type($element) !== 'software_Package') {
                continue;
            }
            $id = (string) ($element['spdxId'] ?? $element['@id'] ?? '');
            $purl = $element['software_packageUrl'] ?? null;
            $cpe = null;
            foreach (is_array($element['externalIdentifier'] ?? null) ? $element['externalIdentifier'] : [] as $identifier) {
                if (! is_array($identifier)) {
                    continue;
                }
                $kind = (string) ($identifier['externalIdentifierType'] ?? '');
                $purl ??= $kind === 'packageUrl' ? ($identifier['identifier'] ?? null) : null;
                $cpe ??= in_array($kind, ['cpe23', 'cpe22'], true) ? ($identifier['identifier'] ?? null) : null;
            }
            $supplier = $element['suppliedBy'] ?? null;
            if (is_array($supplier)) {
                $supplier = $supplier[0] ?? null;
            }
            $supplier = is_string($supplier) ? ($byId[$supplier]['name'] ?? null) : null;
            $purpose = strtolower((string) ($element['software_primaryPurpose'] ?? ''));

            $component = Component::make(
                name: $element['name'] ?? null,
                version: $element['software_packageVersion'] ?? null,
                supplier: $supplier,
                type: $purpose === 'device' ? 'hardware' : 'software',
                purl: $purl,
                cpe: $cpe,
                licence: is_string($licences[$id] ?? null) ? $licences[$id] : null,
                description: $element['description'] ?? $element['summary'] ?? null,
                ref: $id ?: null,
                root: isset($described[$id]),
            );
            if ($component) {
                $out[] = $component;
            }
        }

        return $out;
    }

    /** @param array<mixed> $element */
    private static function type(array $element): string
    {
        $type = $element['type'] ?? $element['@type'] ?? '';

        return str_replace(['software:', 'Core:'], ['software_', ''], is_string($type) ? $type : '');
    }

    /** "Organization: ACME Corp (security@acme.example)" → "ACME Corp" */
    private static function party(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = preg_replace('/^\s*(Organization|Organisation|Person|Tool)\s*:\s*/i', '', $value) ?? $value;
        $value = preg_replace('/\s*\([^)]*@[^)]*\)\s*$/', '', $value) ?? $value;

        return Component::clean($value, 300);
    }

    private static function licence(mixed $value): ?string
    {
        return is_string($value) ? Component::clean($value, 200) : null;
    }
}
