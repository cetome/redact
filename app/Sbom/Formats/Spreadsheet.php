<?php

namespace App\Sbom\Formats;

use App\Sbom\Component;
use App\Sbom\Safe;
use App\Sbom\SbomException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Workbook;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Throwable;

/**
 * Excel (.xlsx) and CSV component lists: our template, or any sheet whose header row uses
 * common column names (Component, Package, Vendor, Manufacturer, Licence, PURL, CPE ...).
 */
final class Spreadsheet
{
    /** Template columns, in order. */
    public const COLUMNS = ['name', 'version', 'supplier', 'type', 'purl', 'cpe', 'licence', 'notes'];

    /** Header aliases (normalised: lower case, letters and digits only). */
    private const ALIASES = [
        'name' => ['name', 'componentname', 'component', 'packagename', 'package', 'software', 'softwarename', 'product', 'productname',
            'part', 'partname', 'item', 'library', 'module', 'title', 'nom', 'composant', 'nomducomposant', 'logiciel', 'produit'],
        'version' => ['version', 'ver', 'componentversion', 'packageversion', 'versioninfo', 'release', 'revision', 'rev', 'firmwareversion',
            'swversion', 'hwversion', 'hardwareversion'],
        'supplier' => ['supplier', 'vendor', 'manufacturer', 'publisher', 'author', 'maker', 'editor', 'originator', 'suppliername',
            'vendorname', 'fournisseur', 'fabricant', 'editeur', 'constructeur', 'supplierreferenceurl', 'reference'],
        'type' => ['type', 'kind', 'category', 'componenttype', 'softwareorhardware', 'swhw', 'hwsw', 'nature', 'logicieloumateriel'],
        'purl' => ['purl', 'packageurl', 'pkgurl'],
        'cpe' => ['cpe', 'cpe23', 'cpe22', 'cpename', 'cpeid'],
        'licence' => ['licence', 'license', 'licences', 'licenses', 'licenseconcluded', 'licencedeclared', 'licensedeclared', 'spdxlicense', 'spdxlicence'],
        'notes' => ['notes', 'note', 'description', 'comment', 'comments', 'remarks', 'remark', 'details', 'commentaire', 'commentaires', 'remarques'],
    ];

    private const MAX_COLUMNS = 60;

    /**
     * @param  list<string>  $warnings
     *
     * @param-out list<string>  $warnings
     *
     * @return list<Component>
     */
    public static function fromXlsx(string $path, array &$warnings): array
    {
        // xlsx is a zip: checked like any other archive before PhpSpreadsheet opens it.
        Safe::checkZip($path, 2000)->close();
        try {
            $reader = new XlsxReader;
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $book = $reader->load($path);
        } catch (Throwable) {
            throw SbomException::because('bad_xlsx');
        }

        $sheet = $book->getSheetByName('Components') ?? $book->getSheet(0);
        $max = (int) config('sbom.import.max_components', 5000) + 20;
        $rows = [];
        $highestRow = min($sheet->getHighestDataRow(), $max);
        $highestColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), self::MAX_COLUMNS);
        for ($r = 1; $r <= $highestRow; $r++) {
            $row = [];
            for ($c = 1; $c <= $highestColumn; $c++) {
                $value = $sheet->getCell([$c, $r])->getValue();
                $row[] = is_scalar($value) ? (string) $value : '';
            }
            $rows[] = $row;
        }
        $book->disconnectWorksheets();

        return self::fromRows($rows, $warnings);
    }

    /**
     * @param  list<string>  $warnings
     *
     * @param-out list<string>  $warnings
     *
     * @return list<Component>
     */
    public static function fromCsv(string $content, array &$warnings): array
    {
        $content = Safe::utf8($content);
        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiters = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($delimiters);
        $delimiter = (string) array_key_first($delimiters);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw SbomException::because('bad_csv');
        }
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];
        $max = (int) config('sbom.import.max_components', 5000) + 20;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false && count($rows) < $max) {
            $rows[] = array_map(fn ($v) => (string) $v, array_slice($row, 0, self::MAX_COLUMNS));
        }
        fclose($handle);

        return self::fromRows($rows, $warnings);
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  list<string>  $warnings
     *
     * @param-out list<string>  $warnings
     *
     * @return list<Component>
     */
    public static function fromRows(array $rows, array &$warnings): array
    {
        // The header row: among the first ten, the one that maps the most columns (a name column is required).
        $best = null;
        foreach (array_slice($rows, 0, 10, true) as $index => $row) {
            $map = self::map($row);
            if (isset($map['name']) && ($best === null || count($map) > count($best[1]))) {
                $best = [$index, $map];
            }
        }
        if ($best === null) {
            throw SbomException::because('no_header');
        }
        [$headerIndex, $map] = $best;
        $unmapped = array_diff_key($rows[$headerIndex], array_flip($map));
        $unmapped = array_filter(array_map('trim', $unmapped));
        if ($unmapped !== []) {
            $warnings[] = (string) __('sbom.warnings.ignored_columns', ['columns' => implode(', ', array_slice($unmapped, 0, 8))]);
        }

        $out = [];
        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $get = fn (string $key) => isset($map[$key]) ? ($row[$map[$key]] ?? null) : null;
            $component = Component::make(
                name: $get('name'),
                version: $get('version'),
                supplier: $get('supplier'),
                type: $get('type'),
                purl: $get('purl'),
                cpe: $get('cpe'),
                licence: $get('licence'),
                description: $get('notes'),
            );
            if ($component) {
                $out[] = $component;
            }
        }

        return $out;
    }

    /**
     * Column key => index for one header row.
     *
     * @param  list<string>  $row
     * @return array<string, int>
     */
    public static function map(array $row): array
    {
        $map = [];
        foreach ($row as $index => $header) {
            $normal = self::normalise($header);
            if ($normal === '') {
                continue;
            }
            foreach (self::ALIASES as $key => $aliases) {
                if (! isset($map[$key]) && in_array($normal, $aliases, true)) {
                    $map[$key] = $index;

                    continue 2;
                }
            }
        }

        return $map;
    }

    private static function normalise(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = strtr($header, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'ç' => 'c']);

        return preg_replace('/[^a-z0-9]/', '', $header) ?? '';
    }

    /** The downloadable template: one "Components" sheet with the columns, examples, a type list. */
    public static function template(): string
    {
        $book = new Workbook;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Components');
        $sheet->fromArray([self::COLUMNS], null, 'A1');
        $sheet->fromArray([
            ['Example firmware', '2.4.1', 'Example Corp', 'software', '', '', 'Proprietary', __('sbom.template.example_firmware')],
            ['openssl', '3.0.13', 'OpenSSL', 'software', 'pkg:generic/openssl@3.0.13', 'cpe:2.3:a:openssl:openssl:3.0.13:*:*:*:*:*:*:*', 'Apache-2.0', ''],
            ['Example radio SoC', 'B2', 'Example Semiconductors', 'hardware', '', '', '', __('sbom.template.example_hardware')],
        ], null, 'A2');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setWidth(in_array($column, ['E', 'F', 'H'], true) ? 40 : 18);
        }
        $validation = $sheet->getCell('D2')->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)->setFormula1('"software,hardware"');
        $sheet->setDataValidation('D2:D5000', $validation);

        $help = $book->createSheet();
        $help->setTitle(mb_substr(__('sbom.template.help_sheet'), 0, 31));
        $lines = [[__('sbom.template.column'), __('sbom.template.meaning')]];
        foreach (self::COLUMNS as $column) {
            $lines[] = [$column, __('sbom.template.columns.'.$column)];
        }
        $help->fromArray($lines, null, 'A1');
        $help->getStyle('A1:B1')->getFont()->setBold(true);
        $help->getColumnDimension('A')->setWidth(14);
        $help->getColumnDimension('B')->setWidth(100);
        $book->setActiveSheetIndex(0);

        $file = tempnam(sys_get_temp_dir(), 'sbom');
        (new XlsxWriter($book))->save((string) $file);
        $content = (string) file_get_contents((string) $file);
        @unlink((string) $file);
        $book->disconnectWorksheets();

        return $content;
    }
}
