<?php

namespace App\Sbom;

use App\Sbom\Formats\CycloneDx;
use App\Sbom\Formats\Spdx;
use App\Sbom\Formats\Spreadsheet;
use App\Sbom\Formats\Swid;

/**
 * Reads uploaded bills of materials, whatever their format, recognised by content (never by
 * the file name the browser sent): CycloneDX JSON/XML, SPDX 2 JSON/tag-value, SPDX 3 JSON-LD,
 * SWID tags (files or a zip), Excel (.xlsx) and CSV.
 */
final class SbomReader
{
    public const FORMATS = ['cyclonedx-json', 'cyclonedx-xml', 'spdx-json', 'spdx-tag', 'spdx3-jsonld', 'swid', 'xlsx', 'csv'];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, true> */
    private array $formats = [];

    /**
     * @param  list<array{name: string, path: string}>  $files
     * @return array{format: string, kind: string, components: list<Component>, warnings: list<string>}
     */
    public function read(array $files): array
    {
        $components = [];
        foreach ($files as $file) {
            $content = (string) file_get_contents($file['path']);
            if (Safe::isZip($content) && ! $this->isXlsx($file['path'])) {
                foreach (Safe::zipFiles($file['path'], (int) config('sbom.import.max_files', 50) * 10) as $name => $entry) {
                    if (Safe::isZip($entry)) {
                        $this->warnings[] = (string) __('sbom.warnings.nested_zip', ['name' => basename($name)]);

                        continue;
                    }
                    array_push($components, ...$this->readOne($entry, null, basename($name)));
                }

                continue;
            }
            array_push($components, ...$this->readOne($content, $file['path'], $file['name']));
        }

        $max = (int) config('sbom.import.max_components', 5000);
        // The same component listed twice (several paths in a firmware image): kept once.
        $unique = [];
        foreach ($components as $component) {
            $key = $component->key();
            if (! isset($unique[$key])) {
                $unique[$key] = $component;
            } elseif ($component->root && ! $unique[$key]->root) {
                $unique[$key] = $component;
            }
        }
        $duplicates = count($components) - count($unique);
        if ($duplicates > 0) {
            $this->warnings[] = (string) trans_choice('sbom.warnings.duplicates', $duplicates, ['count' => $duplicates]);
        }
        if (count($unique) > $max) {
            throw SbomException::because('too_many', ['max' => $max]);
        }
        if ($unique === []) {
            throw SbomException::because('empty');
        }

        $types = array_values(array_unique(array_map(fn (Component $c) => $c->type, $unique)));

        return [
            'format' => count($this->formats) === 1 ? (string) array_key_first($this->formats) : 'mixed',
            'kind' => count($types) > 1 ? 'mixed' : ($types === ['hardware'] ? 'hbom' : 'sbom'),
            'components' => array_values($unique),
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    /** @return list<Component> */
    private function readOne(string $content, ?string $path, string $name): array
    {
        $trimmed = ltrim(Safe::stripBom($content));

        if ($path !== null && Safe::isZip($content)) {
            $this->formats['xlsx'] = true;

            return Spreadsheet::fromXlsx($path, $this->warnings);
        }
        if (! Safe::isText($content)) {
            throw SbomException::because('unknown_format', ['name' => $name]);
        }
        if (str_starts_with($trimmed, '{')) {
            $data = Safe::json($content);
            if (CycloneDx::isJson($data)) {
                $this->formats['cyclonedx-json'] = true;

                return CycloneDx::fromJson($data);
            }
            if (Spdx::isJson($data)) {
                $this->formats['spdx-json'] = true;

                return Spdx::fromJson($data);
            }
            if (Spdx::isJsonLd($data)) {
                $this->formats['spdx3-jsonld'] = true;

                return Spdx::fromJsonLd($data);
            }

            throw SbomException::because('unknown_format', ['name' => $name]);
        }
        if (str_starts_with($trimmed, '<')) {
            $dom = Safe::xml($content);
            if (CycloneDx::isXml($dom)) {
                $this->formats['cyclonedx-xml'] = true;

                return CycloneDx::fromXml($dom);
            }
            if (Swid::is($dom)) {
                $this->formats['swid'] = true;

                return Swid::parse($dom);
            }

            throw SbomException::because('unknown_format', ['name' => $name]);
        }
        if (Spdx::isTagValue($content)) {
            $this->formats['spdx-tag'] = true;

            return Spdx::fromTagValue(Safe::utf8($content));
        }
        $this->formats['csv'] = true;

        return Spreadsheet::fromCsv($content, $this->warnings);
    }

    private function isXlsx(string $path): bool
    {
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        $is = $zip->locateName('xl/workbook.xml') !== false;
        $zip->close();

        return $is;
    }
}
