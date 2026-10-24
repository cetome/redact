<?php

namespace App\Sbom\Vex;

use App\Sbom\Formats\CycloneDx;
use App\Sbom\Safe;
use App\Sbom\SbomException;

/**
 * Finds the VEX statements of uploaded files, recognised by content: CycloneDX VEX (JSON or
 * XML, alone or embedded in an SBOM), OpenVEX, CSAF 2.0 VEX. The same defensive parsing as
 * the bills of materials (App\Sbom\Safe: JSON depth limit, XML without DOCTYPE, no network).
 * Files with neither statements nor VEX format are left to App\Sbom\SbomReader.
 */
final class VexReader
{
    /**
     * @param  list<array{name: string, path: string}>  $files
     * @return array{format: string|null, statements: list<array<string, mixed>>, vex_only: list<int>}
     */
    public function read(array $files): array
    {
        $statements = [];
        $vexOnly = [];
        $formats = [];
        foreach ($files as $n => $file) {
            $content = (string) file_get_contents($file['path']);
            if (Safe::isZip($content) || ! Safe::isText($content)) {
                continue;
            }
            $trimmed = ltrim(Safe::stripBom($content));
            $found = null;
            $only = false;
            if (str_starts_with($trimmed, '{')) {
                $data = Safe::json($content);
                if (OpenVex::is($data)) {
                    [$found, $only] = [OpenVex::fromJson($data, $file['name']), true];
                } elseif (Csaf::is($data)) {
                    [$found, $only] = [Csaf::fromJson($data, $file['name']), true];
                } elseif (CycloneDx::isJson($data) && CycloneDxVex::hasJson($data)) {
                    [$found, $only] = [CycloneDxVex::fromJson($data, $file['name']), CycloneDxVex::onlyVexJson($data)];
                }
            } elseif (str_starts_with($trimmed, '<')) {
                $dom = Safe::xml($content);
                if (CycloneDx::isXml($dom) && CycloneDxVex::hasXml($dom)) {
                    [$found, $only] = [CycloneDxVex::fromXml($dom, $file['name']), CycloneDxVex::onlyVexXml($dom)];
                }
            }
            if ($found === null) {
                continue;
            }
            if ($only) {
                $vexOnly[] = $n;
                if ($found === []) {
                    throw SbomException::because('vex_empty', ['name' => $file['name']]);
                }
            }
            foreach ($found as $statement) {
                $formats[$statement['format']] = true;
                $statements[Statement::key($statement)] = $statement;
            }
        }

        $max = (int) config('sbom.import.max_statements', 5000);
        if (count($statements) > $max) {
            throw SbomException::because('too_many_statements', ['max' => $max]);
        }

        return [
            'format' => $formats === [] ? null : (count($formats) === 1 ? (string) array_key_first($formats) : 'mixed'),
            'statements' => array_values($statements),
            'vex_only' => $vexOnly,
        ];
    }
}
