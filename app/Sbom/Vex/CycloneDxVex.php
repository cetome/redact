<?php

namespace App\Sbom\Vex;

use App\Sbom\Formats\Xml;
use DOMDocument;
use DOMElement;

/**
 * CycloneDX 1.4–1.6 vulnerabilities (VEX), JSON and XML: a VEX-only BOM, or an SBOM that
 * embeds its `vulnerabilities`. `affects[].ref` points at a component of the same file by
 * bom-ref (or a BOM-Link "urn:cdx:…#ref"), resolved here to its purl, name and version.
 */
final class CycloneDxVex
{
    private const MAX_DEPTH = 12;

    /** @param array<mixed> $data */
    public static function hasJson(array $data): bool
    {
        return is_array($data['vulnerabilities'] ?? null) && $data['vulnerabilities'] !== [];
    }

    /** @param array<mixed> $data */
    public static function onlyVexJson(array $data): bool
    {
        return self::hasJson($data) && (! is_array($data['components'] ?? null) || $data['components'] === []);
    }

    /**
     * @param  array<mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function fromJson(array $data, string $file): array
    {
        $refs = [];
        if (is_array($data['metadata']['component'] ?? null)) {
            self::jsonRefs($data['metadata']['component'], $refs, 0, true);
        }
        foreach (is_array($data['components'] ?? null) ? $data['components'] : [] as $c) {
            if (is_array($c)) {
                self::jsonRefs($c, $refs, 0);
            }
        }

        $out = [];
        foreach ($data['vulnerabilities'] as $v) {
            if (! is_array($v)) {
                continue;
            }
            $aliases = [];
            foreach (is_array($v['references'] ?? null) ? $v['references'] : [] as $r) {
                $aliases[] = is_array($r) ? ($r['id'] ?? null) : null;
            }
            $analysis = is_array($v['analysis'] ?? null) ? $v['analysis'] : [];
            $state = is_string($analysis['state'] ?? null) ? $analysis['state'] : null;
            $responses = array_filter(is_array($analysis['response'] ?? null) ? $analysis['response'] : [], 'is_string');
            $action = self::action($responses, $v['recommendation'] ?? null, $v['workaround'] ?? null);
            $justification = $analysis['justification'] ?? ($state === 'false_positive' ? 'false_positive' : null);

            $targets = [];
            foreach (is_array($v['affects'] ?? null) ? $v['affects'] : [] as $a) {
                if (is_array($a) && is_string($a['ref'] ?? null)) {
                    $targets[] = self::resolve($a['ref'], $refs);
                }
            }
            foreach ($targets ?: [['root' => true] + (array_values(array_filter($refs, fn ($r) => $r['root'] ?? false))[0] ?? [])] as $product) {
                $statement = Statement::make($v['id'] ?? null, $aliases, Statement::fromCycloneDxState($state), $justification,
                    $analysis['detail'] ?? null, $action, $product, $file, 'cyclonedx-json', $v['description'] ?? null);
                if ($statement) {
                    $out[] = $statement;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $c
     * @param  array<string, array<string, mixed>>  $refs
     */
    private static function jsonRefs(array $c, array &$refs, int $depth, bool $root = false): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        if (is_string($c['bom-ref'] ?? null)) {
            $refs[$c['bom-ref']] = ['purl' => $c['purl'] ?? null, 'name' => $c['name'] ?? null, 'version' => $c['version'] ?? null, 'ref' => $c['bom-ref'], 'root' => $root];
        }
        foreach (is_array($c['components'] ?? null) ? $c['components'] : [] as $child) {
            if (is_array($child)) {
                self::jsonRefs($child, $refs, $depth + 1);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $refs
     * @return array<string, mixed>
     */
    private static function resolve(string $ref, array $refs): array
    {
        $local = str_contains($ref, '#') && str_starts_with($ref, 'urn:cdx:') ? substr($ref, (int) strrpos($ref, '#') + 1) : $ref;
        if (isset($refs[$local])) {
            return $refs[$local];
        }

        return str_starts_with($local, 'pkg:') ? ['purl' => $local, 'ref' => $local] : ['ref' => $local];
    }

    /** @param  array<mixed>  $responses */
    private static function action(array $responses, mixed $recommendation, mixed $workaround): ?string
    {
        $parts = [];
        foreach ($responses as $r) {
            $key = 'sbom.vex.response.'.$r;
            $label = __($key);
            $parts[] = $label === $key ? str_replace('_', ' ', (string) $r) : $label;
        }
        $text = $parts === [] ? '' : implode(', ', $parts).'.';
        foreach ([$recommendation, $workaround] as $more) {
            if (is_string($more) && trim($more) !== '') {
                $text = trim($text.' '.trim($more));
            }
        }

        return $text === '' ? null : $text;
    }

    public static function hasXml(DOMDocument $dom): bool
    {
        $bom = $dom->documentElement;
        $list = $bom ? Xml::child($bom, 'vulnerabilities') : null;

        return $list !== null && Xml::children($list, 'vulnerability') !== [];
    }

    public static function onlyVexXml(DOMDocument $dom): bool
    {
        $bom = $dom->documentElement;
        $components = $bom ? Xml::child($bom, 'components') : null;

        return self::hasXml($dom) && ($components === null || Xml::children($components, 'component') === []);
    }

    /** @return list<array<string, mixed>> */
    public static function fromXml(DOMDocument $dom, string $file): array
    {
        $bom = $dom->documentElement;
        if (! $bom) {
            return [];
        }
        $refs = [];
        $metadata = Xml::child($bom, 'metadata');
        $root = $metadata ? Xml::child($metadata, 'component') : null;
        if ($root) {
            self::xmlRefs($root, $refs, 0, true);
        }
        $components = Xml::child($bom, 'components');
        foreach ($components ? Xml::children($components, 'component') : [] as $c) {
            self::xmlRefs($c, $refs, 0);
        }

        $out = [];
        $list = Xml::child($bom, 'vulnerabilities');
        foreach ($list ? Xml::children($list, 'vulnerability') : [] as $v) {
            $aliases = [];
            $references = Xml::child($v, 'references');
            foreach ($references ? Xml::children($references, 'reference') : [] as $r) {
                $aliases[] = Xml::text($r, 'id');
            }
            $analysis = Xml::child($v, 'analysis');
            $state = $analysis ? Xml::text($analysis, 'state') : null;
            $responses = [];
            $responseList = $analysis ? Xml::child($analysis, 'responses') : null;
            foreach ($responseList ? Xml::children($responseList, 'response') : [] as $r) {
                $responses[] = trim($r->textContent);
            }
            $justification = ($analysis ? Xml::text($analysis, 'justification') : null) ?? ($state === 'false_positive' ? 'false_positive' : null);

            $targets = [];
            $affects = Xml::child($v, 'affects');
            foreach ($affects ? Xml::children($affects, 'target') : [] as $t) {
                $ref = Xml::text($t, 'ref');
                if ($ref !== null) {
                    $targets[] = self::resolve($ref, $refs);
                }
            }
            foreach ($targets ?: [['root' => true] + (array_values(array_filter($refs, fn ($r) => $r['root'] ?? false))[0] ?? [])] as $product) {
                $statement = Statement::make(Xml::text($v, 'id'), $aliases, Statement::fromCycloneDxState($state), $justification,
                    $analysis ? Xml::text($analysis, 'detail') : null, self::action($responses, Xml::text($v, 'recommendation'), Xml::text($v, 'workaround')),
                    $product, $file, 'cyclonedx-xml', Xml::text($v, 'description'));
                if ($statement) {
                    $out[] = $statement;
                }
            }
        }

        return $out;
    }

    /** @param  array<string, array<string, mixed>>  $refs */
    private static function xmlRefs(DOMElement $c, array &$refs, int $depth, bool $root = false): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        $ref = $c->getAttribute('bom-ref');
        if ($ref !== '') {
            $refs[$ref] = ['purl' => Xml::text($c, 'purl'), 'name' => Xml::text($c, 'name'), 'version' => Xml::text($c, 'version'), 'ref' => $ref, 'root' => $root];
        }
        $nested = Xml::child($c, 'components');
        foreach ($nested ? Xml::children($nested, 'component') : [] as $child) {
            self::xmlRefs($child, $refs, $depth + 1);
        }
    }
}
