<?php

namespace App\Workbooks;

use App\Models\ProductIdentifier;
use App\Models\ProductVersion;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Product identification from a spreadsheet (xlsx or csv), added to the rows already there.
 *
 * Two layouts, recognised by the header row:
 *  (a) Kind | Value | Note columns: the kind matched on the kind labels (en, fr) or keys;
 *  (b) one column per kind (header = a kind label, e.g. "Model", "Trade name"), each non-empty
 *      cell one row.
 * Kinds are the models (product level) and ProductIdentifier::VERSION_KINDS. What the product card
 * holds (reference, hardware and software versions, HVIN, FVIN) is never imported: those columns
 * are left out and the result says where they go. An unknown kind becomes "other", with the
 * header (or the kind read) as its note. Exact duplicates (kind + value, case ignored) are skipped.
 */
final class IdentifierImport
{
    public const MAX_ROWS = 500;

    /** The card's fields: never imported as identifiers. */
    public const CARD_KINDS = ['reference', 'hvin', 'fvin', 'hardware_version', 'software_version'];

    /** @return list<string> kinds the import writes */
    public static function kinds(): array
    {
        return [ProductIdentifier::MODEL, ...ProductIdentifier::VERSION_KINDS];
    }

    /**
     * Rows of the first sheet of a workbook (checked as a zip first) or of a CSV file.
     *
     * @return list<list<string>>
     */
    public static function table(string $path, string $extension): array
    {
        if ($extension === 'csv') {
            return self::csv($path);
        }
        XlsxPackage::open($path)->close(); // limits, encrypted files, not a workbook: WorkbookException
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $book = $reader->load($path);
        $sheet = $book->getSheet(0);
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                $cells[] = trim(is_scalar($value) ? (string) $value : (string) ($value?->__toString() ?? ''));
            }
            $rows[] = $cells;
        }
        $book->disconnectWorksheets();

        return $rows;
    }

    /** @return list<list<string>> */
    private static function csv(string $path): array
    {
        $content = (string) file_get_contents($path, false, null, 0, 2 * 1024 * 1024);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $first = $lines[0] ?? '';
        $delimiter = collect([';' => substr_count($first, ';'), "\t" => substr_count($first, "\t"), ',' => substr_count($first, ',')])
            ->sortDesc()->keys()->first() ?? ',';
        $rows = [];
        foreach (array_slice($lines, 0, self::MAX_ROWS + 1) as $line) {
            $rows[] = array_map('trim', str_getcsv($line, (string) $delimiter, '"', ''));
        }

        return $rows;
    }

    /**
     * The identifier rows found in a table, and what was left out.
     *
     * @param  list<list<string>>  $table
     * @return array{rows: list<array{kind: string, value: string, notes: string|null}>, card_columns: list<string>}
     */
    public static function rows(array $table): array
    {
        $table = array_values(array_filter($table, fn (array $row) => implode('', $row) !== ''));
        if ($table === []) {
            return ['rows' => [], 'card_columns' => []];
        }
        $header = array_map(fn ($c) => self::normalise($c), $table[0]);
        $kindCol = self::column($header, ['kind', __('identification.kind', [], 'en'), __('identification.kind', [], 'fr')]);
        $valueCol = self::column($header, ['value', __('identification.value', [], 'en'), __('identification.value', [], 'fr'), 'name', 'identifier', 'identifiant']);
        $noteCol = self::column($header, ['note', 'notes', __('identification.notes', [], 'en'), __('identification.notes', [], 'fr')]);

        $rows = [];
        $card = [];
        if ($kindCol !== null && $valueCol !== null) {
            foreach (array_slice($table, 1) as $line) {
                $value = trim($line[$valueCol] ?? '');
                $read = trim($line[$kindCol] ?? '');
                if ($value === '') {
                    continue;
                }
                $kind = self::kindOf($read);
                if (in_array($kind, self::CARD_KINDS, true)) {
                    $card[$read] = true;

                    continue;
                }
                $note = $noteCol !== null ? trim($line[$noteCol] ?? '') : '';
                if ($kind === null) {
                    $kind = 'other';
                    $note = trim($read.($note !== '' ? ' · '.$note : ''));
                }
                $rows[] = ['kind' => $kind, 'value' => $value, 'notes' => $note !== '' ? $note : null];
            }
        } else {
            foreach ($table[0] as $index => $title) {
                $title = trim($title);
                if ($title === '') {
                    continue;
                }
                $kind = self::kindOf($title);
                if (in_array($kind, self::CARD_KINDS, true)) {
                    $card[$title] = true;

                    continue;
                }
                foreach (array_slice($table, 1) as $line) {
                    $value = trim($line[$index] ?? '');
                    if ($value !== '') {
                        $rows[] = ['kind' => $kind ?? 'other', 'value' => $value, 'notes' => $kind === null ? $title : null];
                    }
                }
            }
        }

        return [
            'rows' => array_map(fn ($r) => ['kind' => $r['kind'], 'value' => mb_substr($r['value'], 0, 160), 'notes' => $r['notes'] !== null ? mb_substr($r['notes'], 0, 255) : null], $rows),
            'card_columns' => array_keys($card),
        ];
    }

    /**
     * Add the rows to the product's models and the version's identifiers, skipping duplicates.
     *
     * @param  list<array{kind: string, value: string, notes: string|null}>  $rows
     * @return array{added: int, existing: int}
     */
    public static function apply(ProductVersion $version, array $rows): array
    {
        $added = 0;
        $existing = 0;
        DB::transaction(function () use ($version, $rows, &$added, &$existing) {
            $product = $version->product;
            $seen = [];
            foreach ([...$product->models()->get(), ...$version->identifiers()->get()] as $row) {
                $seen[$row->kind.'|'.mb_strtolower(trim((string) $row->value))] = true;
            }
            $modelPosition = (int) $product->models()->max('position') + 1;
            $position = (int) $version->identifiers()->max('position') + 1;
            foreach ($rows as $row) {
                $key = $row['kind'].'|'.mb_strtolower($row['value']);
                if (isset($seen[$key])) {
                    $existing++;

                    continue;
                }
                $seen[$key] = true;
                $data = ['kind' => $row['kind'], 'value' => $row['value'], 'notes' => $row['notes']];
                $row['kind'] === ProductIdentifier::MODEL
                    ? $product->models()->create($data + ['position' => $modelPosition++])
                    : $version->identifiers()->create($data + ['position' => $position++]);
                $added++;
            }
            $product->unsetRelation('models');
        });

        return ['added' => $added, 'existing' => $existing];
    }

    /** The template: Kind (dropdown of the kind labels) | Value | Note. */
    public static function template(string $target): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle(mb_substr(__('identification.title'), 0, 31));
        $sheet->fromArray([[__('identification.kind'), __('identification.value'), __('identification.notes')]]);
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(40);
        $labels = array_map(fn ($kind) => str_replace(',', ' ', __('identification.kinds.'.$kind)), self::kinds());
        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST)->setFormula1('"'.implode(',', $labels).'"')
            ->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true);
        $sheet->setDataValidation('A2:A500', $validation);
        $sheet->freezePane('A2');
        (new Xlsx($book))->save($target);
        $book->disconnectWorksheets();
    }

    /** @param  list<string>  $names */
    private static function column(array $header, array $names): ?int
    {
        $names = array_map([self::class, 'normalise'], $names);
        foreach ($header as $index => $cell) {
            if ($cell !== '' && in_array($cell, $names, true)) {
                return $index;
            }
        }

        return null;
    }

    /** The kind of a label or key (English or French, case ignored), null when unknown. */
    public static function kindOf(string $text): ?string
    {
        $wanted = self::normalise($text);
        if ($wanted === '') {
            return null;
        }
        foreach (ProductIdentifier::KINDS as $kind) {
            $names = [$kind, str_replace('_', ' ', $kind), __('identification.kinds.'.$kind, [], 'en'), __('identification.kinds.'.$kind, [], 'fr')];
            if (in_array($wanted, array_map([self::class, 'normalise'], $names), true)) {
                return $kind;
            }
        }

        // "Models", "Brands", "Hardware version (HVIN)"...
        return match (true) {
            str_starts_with($wanted, 'model') || str_starts_with($wanted, 'modèle') => ProductIdentifier::MODEL,
            str_contains($wanted, 'hvin') || str_contains($wanted, 'hardware') || str_contains($wanted, 'matériel') => 'hardware_version',
            str_contains($wanted, 'fvin') || str_contains($wanted, 'software') || str_contains($wanted, 'firmware') || str_contains($wanted, 'logiciel') => 'software_version',
            str_starts_with($wanted, 'brand') || str_starts_with($wanted, 'marque') => 'brand',
            str_starts_with($wanted, 'trade name') || str_starts_with($wanted, 'nom commercial') => 'trade_name',
            str_starts_with($wanted, 'reference') || str_starts_with($wanted, 'référence') || $wanted === 'sku' => 'reference',
            default => null,
        };
    }

    private static function normalise(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }
}
