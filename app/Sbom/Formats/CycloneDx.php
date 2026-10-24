<?php

namespace App\Sbom\Formats;

use App\Sbom\Component;
use DOMDocument;
use DOMElement;

/**
 * CycloneDX 1.2 – 1.6, JSON and XML. SBOM and HBOM: a component of type "device" (1.6
 * hardware BOM) is hardware, everything else software. Nested components are flattened;
 * metadata.component is the product itself (kept, flagged as root, unticked in the preview).
 */
final class CycloneDx
{
    private const MAX_DEPTH = 12;

    /** @param array<mixed> $data */
    public static function isJson(array $data): bool
    {
        return ($data['bomFormat'] ?? null) === 'CycloneDX'
            || (isset($data['specVersion']) && (isset($data['components']) || isset($data['metadata'])) && ! isset($data['spdxVersion']));
    }

    /**
     * @param  array<mixed>  $data
     * @return list<Component>
     */
    public static function fromJson(array $data): array
    {
        $out = [];
        $root = $data['metadata']['component'] ?? null;
        if (is_array($root)) {
            self::jsonComponent($root, $out, 0, true);
        }
        foreach (is_array($data['components'] ?? null) ? $data['components'] : [] as $component) {
            if (is_array($component)) {
                self::jsonComponent($component, $out, 0);
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $c
     * @param  list<Component>  $out
     */
    private static function jsonComponent(array $c, array &$out, int $depth, bool $root = false): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        $licences = [];
        foreach (is_array($c['licenses'] ?? null) ? $c['licenses'] : [] as $l) {
            if (! is_array($l)) {
                continue;
            }
            $licences[] = $l['expression'] ?? $l['license']['id'] ?? $l['license']['name'] ?? null;
        }
        $supplier = $c['supplier']['name'] ?? $c['manufacturer']['name'] ?? $c['publisher'] ?? $c['author']
            ?? (is_array($c['authors'] ?? null) ? ($c['authors'][0]['name'] ?? null) : null);

        $component = Component::make(
            name: $c['name'] ?? null,
            version: $c['version'] ?? null,
            supplier: is_string($supplier) ? $supplier : null,
            type: ($c['type'] ?? null) === 'device' ? 'hardware' : 'software',
            purl: $c['purl'] ?? null,
            cpe: $c['cpe'] ?? null,
            licence: implode(', ', array_filter($licences, 'is_string')),
            description: $c['description'] ?? null,
            ref: $c['bom-ref'] ?? null,
            root: $root,
        );
        if ($component) {
            $out[] = $component;
        }
        foreach (is_array($c['components'] ?? null) ? $c['components'] : [] as $child) {
            if (is_array($child)) {
                self::jsonComponent($child, $out, $depth + 1);
            }
        }
    }

    public static function isXml(DOMDocument $dom): bool
    {
        $root = $dom->documentElement;

        return $root !== null && $root->localName === 'bom' && str_contains((string) $root->namespaceURI, 'cyclonedx.org');
    }

    /** @return list<Component> */
    public static function fromXml(DOMDocument $dom): array
    {
        $out = [];
        $bom = $dom->documentElement;
        if (! $bom) {
            return [];
        }
        $metadata = Xml::child($bom, 'metadata');
        $root = $metadata ? Xml::child($metadata, 'component') : null;
        if ($root) {
            self::xmlComponent($root, $out, 0, true);
        }
        $components = Xml::child($bom, 'components');
        foreach ($components ? Xml::children($components, 'component') : [] as $element) {
            self::xmlComponent($element, $out, 0);
        }

        return $out;
    }

    /** @param  list<Component>  $out */
    private static function xmlComponent(DOMElement $c, array &$out, int $depth, bool $root = false): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        $licences = [];
        $licenceList = Xml::child($c, 'licenses');
        if ($licenceList) {
            foreach (Xml::children($licenceList, 'license') as $l) {
                $licences[] = Xml::text($l, 'id') ?? Xml::text($l, 'name');
            }
            $licences[] = Xml::text($licenceList, 'expression');
        }
        $type = $c->getAttribute('type');

        $component = Component::make(
            name: Xml::text($c, 'name'),
            version: Xml::text($c, 'version'),
            supplier: Xml::text($c, 'supplier', 'name') ?? Xml::text($c, 'manufacturer', 'name') ?? Xml::text($c, 'publisher') ?? Xml::text($c, 'author'),
            type: $type === 'device' ? 'hardware' : 'software',
            purl: Xml::text($c, 'purl'),
            cpe: Xml::text($c, 'cpe'),
            licence: implode(', ', array_filter($licences)),
            description: Xml::text($c, 'description'),
            ref: $c->getAttribute('bom-ref') ?: null,
            root: $root,
        );
        if ($component) {
            $out[] = $component;
        }
        $nested = Xml::child($c, 'components');
        foreach ($nested ? Xml::children($nested, 'component') : [] as $child) {
            self::xmlComponent($child, $out, $depth + 1);
        }
    }
}
