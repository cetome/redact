<?php

namespace App\Reports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The Excel workbook of a version, in CRAted's look (spreadsheet.ts,
 * excel-export.ts): every header row bold white on cetome's blue (FF0044AA)
 * like the Word tables, widths in characters written as SheetJS did, Calibri 11,
 * empty strings leave no cell. Text wraps and the header row is frozen.
 *
 * Every text is written as a string, never as a formula, whatever it starts
 * with (= + - @): a value typed in the app can never run in Excel.
 */
final class ExcelReport
{
    public const BRAND_FILL = 'FF0044AA';

    private const IMPOSSIBLE_FILL = 'FFD9DEE5';

    /** @var array<string, true> */
    private array $sheetNames = [];

    public function __construct(
        private readonly ReportData $data,
        private readonly ReportKind $kind,
    ) {}

    public function save(string $path): void
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $book->getProperties()
            ->setCreator('REDact')
            ->setLastModifiedBy('REDact')
            ->setTitle($this->data->product['name'].' · '.$this->kind->label())
            ->setSubject($this->data->title())
            ->setCompany((string) $this->data->product['organisation']);

        $this->overview($book);
        if ($this->kind === ReportKind::Assessment) {
            $this->scoping($book);
            $this->changes($book);
            $this->risks($book);
            $this->inventory($book);
            $this->links($book);
        }
        if ($this->kind !== ReportKind::Review) {
            foreach ($this->data->standards as $standard) {
                $this->evaluations($book, $standard);
                $this->treesSummary($book, $standard);
                if ($this->kind === ReportKind::Assessment) {
                    $this->einfo($book, $standard);
                }
            }
        }
        if ($this->kind !== ReportKind::Trees) {
            $this->reviews($book);
        }
        if ($this->kind === ReportKind::Review) {
            $this->cases($book);
        }

        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    // ---- Helpers -------------------------------------------------------------------------------

    /** A width of N characters as SheetJS wrote it (N + 5/6, to 1/256), CRAted's sheetWidth. */
    public static function sheetWidth(float $characters): float
    {
        return round(($characters + 5 / 6) * 256) / 256;
    }

    /** A sheet name Excel accepts: no []:*?/\, at most 31 characters, unique in the workbook. */
    private function sheet(Spreadsheet $book, string $name): Worksheet
    {
        $name = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $name)) ?: 'Sheet';
        $base = mb_substr($name, 0, 31);
        $candidate = $base;
        for ($n = 2; isset($this->sheetNames[mb_strtolower($candidate)]); $n++) {
            $candidate = mb_substr($base, 0, 31 - strlen(' '.$n)).' '.$n;
        }
        $this->sheetNames[mb_strtolower($candidate)] = true;
        $sheet = $book->createSheet();
        $sheet->setTitle($candidate, false);

        return $sheet;
    }

    /**
     * Write one row from column A. Strings are always strings (never formulas), numbers stay
     * numbers, empty strings and nulls leave no cell.
     *
     * @param  list<string|int|float|bool|null>  $values
     */
    private function row(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $i => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $cell = Coordinate::stringFromColumnIndex($i + 1).$row;
            if (is_int($value) || is_float($value)) {
                $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
            } else {
                $text = is_bool($value) ? ($value ? __('common.yes') : __('common.no')) : (string) $value;
                $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
                $sheet->setCellValueExplicit($cell, mb_substr($text, 0, 32767), DataType::TYPE_STRING);
            }
        }
    }

    /** A header row: bold white text on cetome's blue, CRAted's brandHeader. */
    private function brandHeader(Worksheet $sheet, int $row, int $columns): void
    {
        $range = 'A'.$row.':'.Coordinate::stringFromColumnIndex($columns).$row;
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::BRAND_FILL);
    }

    /** @param  list<int|float>  $widths */
    private function widths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $i => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth(self::sheetWidth($width));
        }
    }

    /** Wrap the text of a block and align it to the top, so long texts stay readable. */
    private function wrap(Worksheet $sheet, int $fromRow, int $toRow, int $columns): void
    {
        if ($toRow < $fromRow) {
            return;
        }
        $sheet->getStyle('A'.$fromRow.':'.Coordinate::stringFromColumnIndex($columns).$toRow)
            ->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    }

    private function fill(Worksheet $sheet, string $cell, string $argb, ?string $fontArgb = null, bool $bold = false): void
    {
        $style = $sheet->getStyle($cell);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($argb);
        if ($fontArgb) {
            $style->getFont()->setBold($bold)->getColor()->setARGB($fontArgb);
        }
    }

    /**
     * A table sheet: the header row in the brand look, the rows wrapped, the header frozen.
     *
     * @param  list<string>  $header
     * @param  list<list<string|int|float|bool|null>>  $rows
     * @param  list<int|float>  $widths
     */
    private function tableSheet(Spreadsheet $book, string $name, array $header, array $rows, array $widths): Worksheet
    {
        $sheet = $this->sheet($book, $name);
        $this->row($sheet, 1, $header);
        $r = 2;
        foreach ($rows as $values) {
            $this->row($sheet, $r++, $values);
        }
        $this->widths($sheet, $widths);
        $this->wrap($sheet, 2, $r - 1, count($header));
        $this->brandHeader($sheet, 1, count($header));
        $sheet->freezePane('A2');

        return $sheet;
    }

    private function verdictText(?string $verdict): string
    {
        return $verdict ? __('verdicts.'.$verdict) : '';
    }

    // ---- Sheets --------------------------------------------------------------------------------

    private function overview(Spreadsheet $book): void
    {
        $data = $this->data;
        $sheet = $this->sheet($book, __('reports.sheets.overview'));
        $facts = [
            __('reports.fields.product') => $data->product['name'],
            __('reports.fields.reference') => $data->product['reference'],
            __('reports.fields.organisation') => $data->product['organisation'],
            __('reports.fields.models') => str_replace("\n", ', ', (string) $data->product['models']),
            __('reports.fields.version') => $data->version['label'],
            __('reports.fields.hardware_version') => $data->version['hardware_version'],
            __('reports.fields.software_version') => $data->version['software_version'],
            __('reports.fields.status') => $data->version['status'],
            __('reports.fields.author') => $data->version['author'],
            __('reports.fields.completion') => $data->version['completion'],
            __('reports.fields.content_pack') => $data->version['content_pack'],
            __('reports.fields.document') => $this->kind->label(),
            __('reports.fields.exported_by') => $data->exportedBy,
            __('reports.fields.exported_on') => $data->exportedAt,
            __('reports.fields.standards') => $data->scoping['standards'] !== [] ? implode(', ', $data->scoping['standards']) : __('reports.none_in_scope'),
            __('reports.fields.self_assessment') => $data->scoping['self_assessment'],
        ];
        $r = 1;
        foreach ($facts as $label => $value) {
            $this->row($sheet, $r++, [$label, $value]);
        }
        $sheet->getStyle('A1:A'.($r - 1))->getFont()->setBold(true);
        $this->wrap($sheet, 1, $r - 1, 2);
        $r++;
        $header = [
            __('reports.columns.standard'),
            __('reports.buckets.pass'),
            __('reports.buckets.fail'),
            __('reports.buckets.na'),
            __('reports.buckets.inconclusive'),
            __('reports.buckets.missing'),
            __('reports.buckets.invalid'),
            __('evaluations.follows.stat'),
        ];
        $headerRow = $r;
        $this->row($sheet, $r++, $header);
        foreach ($data->standards as $standard) {
            $this->row($sheet, $r++, [$standard['name'], ...array_values($standard['counts']), $standard['follows'] ?? 0]);
        }
        if ($data->standards === []) {
            $this->row($sheet, $r++, [__('reports.empty.standards')]);
        }
        // After the bold column A, so the header stays white on the blue (CRAted's order)
        $this->brandHeader($sheet, $headerRow, count($header));
        $this->widths($sheet, [26, 60, 12, 12, 16, 16]);
    }

    /** A version made from an earlier one: the significant change, decision first, then the signs. */
    private function changes(Spreadsheet $book): void
    {
        $change = $this->data->change;
        if ($change === null) {
            return;
        }
        $rows = [
            [__('changes.decision_state'), $change['decision'], ''],
            [__('changes.report.decided'), $change['decided'], ''],
            [__('changes.justification'), $change['justification'], ''],
            [__('changes.recheck_title'), $change['rechecks'], ''],
            ['', '', ''],
        ];
        foreach ($change['signs'] as $sign) {
            $rows[] = [$sign['kind'], $sign['what'], $sign['detail']];
        }
        $this->tableSheet($book, __('changes.report.sheet'), [__('changes.report.kind'), __('changes.col.what'), __('changes.col.change')], $rows, [26, 50, 50]);
    }

    private function scoping(Spreadsheet $book): void
    {
        $scoping = $this->data->scoping;
        $sheet = $this->sheet($book, __('reports.sheets.scoping'));
        $header = [__('reports.columns.section'), __('reports.columns.group'), __('reports.columns.question'), __('reports.columns.answer')];
        $this->row($sheet, 1, $header);
        $r = 2;
        foreach ($scoping['sections'] as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['questions'] as $question) {
                    $this->row($sheet, $r++, [$section['title'], $group['title'], $question['label'], $question['answered'] ? $question['answer'] : __('reports.not_answered')]);
                }
            }
        }
        $this->wrap($sheet, 2, $r - 1, 4);
        $this->brandHeader($sheet, 1, 4);
        $sheet->freezePane('A2');

        $r++;
        $articlesHeader = $r;
        $this->row($sheet, $r++, [__('reports.columns.article'), __('reports.columns.name'), __('reports.columns.in_scope')]);
        foreach ($scoping['articles'] as $article) {
            $this->row($sheet, $r++, [$article['code'], $article['name'], $article['in_scope']]);
        }
        $this->brandHeader($sheet, $articlesHeader, 3);
        $r++;
        $this->row($sheet, $r++, [__('reports.fields.standards'), $scoping['standards'] !== [] ? implode(', ', $scoping['standards']) : __('reports.none_in_scope')]);
        foreach ($scoping['restrictions'] as $restriction) {
            $this->row($sheet, $r++, [$restriction['label'], __('reports.restriction_'.$restriction['state'])]);
        }
        $this->row($sheet, $r++, [__('reports.fields.self_assessment'), $scoping['self_assessment']]);
        foreach ($scoping['messages'] as $message) {
            $this->row($sheet, $r++, ['', $message]);
        }
        $this->wrap($sheet, $articlesHeader + 1, $r - 1, 4);
        $this->widths($sheet, [22, 30, 60, 30]);
    }

    private function risks(Spreadsheet $book): void
    {
        $header = [
            '#', __('reports.columns.threat'), __('reports.columns.description'), __('reports.columns.stride'),
            __('reports.columns.risk'), __('reports.columns.impact'), __('reports.columns.likelihood'),
            __('reports.columns.score'), __('reports.columns.level'), __('reports.columns.articles'),
            __('reports.columns.requirements'), __('reports.columns.remediation'), __('reports.columns.implementation'),
            __('reports.columns.residual'), __('reports.columns.warning'),
        ];
        $rows = [];
        foreach ($this->data->risks['entries'] as $entry) {
            $rows[] = [
                $entry['number'], $entry['threat'], $entry['description'], $entry['stride_letters'],
                $entry['risk'], $entry['impact'], $entry['likelihood'], $entry['score'],
                $entry['level']['name'] ?? '', implode(', ', $entry['articles']), implode(', ', $entry['requirements']),
                $entry['remediation'], $entry['implementation'], $entry['residual']['name'] ?? '',
                $entry['residual_worse'] ? __('reports.residual_worse') : '',
            ];
        }
        $sheet = $this->tableSheet($book, __('reports.sheets.risks'), $header, $rows, [5, 30, 40, 10, 30, 9, 11, 8, 14, 14, 18, 40, 40, 14, 24]);
        foreach ($this->data->risks['entries'] as $i => $entry) {
            foreach (['I' => $entry['level'], 'N' => $entry['residual']] as $column => $level) {
                if ($level) {
                    $this->fill($sheet, $column.($i + 2), 'FF'.$level['color'], 'FFFFFFFF', true);
                }
            }
        }
    }

    private function inventory(Spreadsheet $book): void
    {
        foreach ($this->data->inventory as $list) {
            $header = [__('reports.columns.code'), __('reports.columns.name')];
            $widths = [14, 30];
            foreach ($list['fields'] as $field) {
                $header[] = $field['label'];
                $widths[] = 20;
            }
            $header[] = __('reports.columns.description');
            $widths[] = 60;
            $rows = [];
            foreach ($list['items'] as $item) {
                $values = [$item['code'], $item['name']];
                foreach ($list['fields'] as $field) {
                    $values[] = $item['values'][$field['key']] ?? '';
                }
                $values[] = $item['description'];
                $rows[] = $values;
            }
            $this->tableSheet($book, $list['label'], $header, $rows, $widths);
        }
    }

    private function links(Spreadsheet $book): void
    {
        $rows = [];
        foreach ($this->data->relations as $relation) {
            foreach ($relation['pairs'] as $pair) {
                $rows[] = [$relation['label'], $relation['from'], $pair['from'], $relation['to'], $pair['to']];
            }
        }
        $this->tableSheet($book, __('reports.sheets.links'), [
            __('reports.columns.relation'), __('reports.columns.from_list'), __('reports.columns.from'),
            __('reports.columns.to_list'), __('reports.columns.to'),
        ], $rows, [26, 16, 36, 16, 36]);
    }

    /** @param  array<string, mixed>  $standard */
    private function evaluations(Spreadsheet $book, array $standard): void
    {
        $rows = [];
        foreach ($standard['requirements'] as $requirement) {
            foreach ($requirement['rows'] as $row) {
                $rows[] = [
                    $requirement['code'],
                    $requirement['title'],
                    $row['object'],
                    $row['reference'],
                    $this->verdictText($row['verdict']) ?: __('reports.not_evaluated'),
                    $row['justification'],
                    $row['justified_label'],
                    $row['valid'] === null ? '' : ($row['valid'] ? __('common.yes') : __('common.no')),
                ];
            }
            // Not walked: the verdict follows from another walk (FAIL), or a link is still missing.
            foreach ($requirement['follows'] ?? [] as $follow) {
                $rows[] = [
                    $requirement['code'],
                    $requirement['title'],
                    $follow['object'],
                    'DT.'.$follow['source'],
                    $follow['state'] === 'fail' ? __('evaluations.follows.badge', ['code' => $follow['source']]) : __('evaluations.follows.unlinked'),
                    $follow['state'] === 'fail' ? __('evaluations.follows.help', ['code' => $follow['source']]) : __('evaluations.follows.unlinked_help', ['code' => $follow['source']]),
                    '',
                    '',
                ];
            }
        }
        $this->tableSheet($book, __('reports.sheets.edt', ['standard' => $standard['code']]), [
            __('reports.columns.requirement'), __('reports.columns.title'), __('reports.columns.object'),
            __('reports.columns.node'), __('reports.columns.verdict'), __('reports.columns.justification'), __('assessment.report.justified'), __('reports.columns.valid'),
        ], $rows, [14, 36, 30, 20, 16, 70, 14, 8]);
    }

    /** @param  array<string, mixed>  $standard */
    private function treesSummary(Spreadsheet $book, array $standard): void
    {
        $rows = [];
        $impossible = [];
        $r = 2;
        foreach ($standard['requirements'] as $requirement) {
            foreach ($requirement['summary'] as $node) {
                $values = [$requirement['code'], $node['reference']];
                foreach (ReportData::VERDICTS as $i => $verdict) {
                    $cell = $node['cells'][$verdict];
                    $values[] = implode("\n", $cell['objects']);
                    if (! $cell['allowed']) {
                        $impossible[] = Coordinate::stringFromColumnIndex(3 + $i).$r;
                    }
                }
                $rows[] = $values;
                $r++;
            }
        }
        $sheet = $this->tableSheet($book, __('reports.sheets.summary', ['standard' => $standard['code']]), [
            __('reports.columns.requirement'), __('reports.columns.node'),
            __('verdicts.PASS'), __('verdicts.FAIL'), __('verdicts.NOT_APPLICABLE'),
        ], $rows, [14, 22, 40, 40, 40]);
        foreach ($impossible as $cell) {
            $this->fill($sheet, $cell, self::IMPOSSIBLE_FILL);
        }
    }

    /** @param  array<string, mixed>  $standard */
    private function einfo(Spreadsheet $book, array $standard): void
    {
        $rows = [];
        foreach ($this->data->einfo[$standard['code']] ?? [] as $row) {
            $rows[] = [$row['code'], $row['label'], $row['requirement'], $row['object'], $row['missing'] ? __('reports.einfo_missing_short') : $row['text']];
        }
        $this->tableSheet($book, __('reports.sheets.einfo', ['standard' => $standard['code']]), [
            __('reports.columns.einfo'), __('reports.columns.description'), __('reports.columns.requirement'),
            __('reports.columns.object'), __('reports.columns.documentation'),
        ], $rows, [30, 40, 14, 30, 80]);
    }

    /** The assessment cases recorded in the Review step (review report). */
    private function cases(Spreadsheet $book): void
    {
        $rows = [];
        foreach ($this->data->cases as $standard) {
            foreach ($standard['requirements'] as $requirement) {
                foreach ($requirement['cases'] as $case) {
                    $rows[] = [
                        $standard['code'], $requirement['code'], $case['label'], $case['clause'],
                        $case['verdict'] ? $this->verdictText($case['verdict']) : __('assessment.report.not_recorded'),
                        $case['evidence'], $case['document'], $case['recorded_by'], $case['date'],
                        $case['independent'] === null ? '' : ($case['independent'] ? __('common.yes') : __('common.no')),
                    ];
                }
            }
        }
        if ($rows === []) {
            $rows[] = ['—', $this->data->unitsExternal ? __('assessment.external_report') : __('assessment.report.none')];
        }
        $this->tableSheet($book, __('assessment.report.sheet'), [
            __('reports.columns.standard'), __('reports.columns.requirement'), __('assessment.report.case'), __('assessment.report.clause'),
            __('reports.columns.verdict'), __('assessment.report.evidence'), __('assessment.report.document'), __('assessment.report.recorded_by'),
            __('assessment.report.date'), __('assessment.report.independent'),
        ], $rows, [14, 14, 30, 12, 16, 70, 30, 22, 18, 12]);
    }

    private function reviews(Spreadsheet $book): void
    {
        $sheet = $this->sheet($book, __('reports.sheets.reviews'));
        $header = [__('reports.columns.reviewer'), __('reports.columns.overall_verdict'), __('reports.columns.reviewed_on'), __('reports.columns.comment')];
        $this->row($sheet, 1, $header);
        $r = 2;
        foreach ($this->data->reviews['overall'] as $review) {
            $this->row($sheet, $r++, [$review['reviewer'], $review['label'], $review['date'], $review['comment']]);
        }
        if ($this->data->reviews['overall'] === []) {
            $this->row($sheet, $r++, ['—', __('reports.empty.overall')]);
        }
        $this->wrap($sheet, 2, $r - 1, 4);
        $this->brandHeader($sheet, 1, 4);

        $r++;
        $detailHeader = $r;
        $columns = [
            __('reports.columns.standard'), __('reports.columns.requirement'), __('reports.columns.object'),
            __('reports.columns.node'), __('reports.columns.verdict'), __('reports.columns.reviewer'),
            __('reports.columns.review'), __('reports.columns.comment'), __('reports.columns.reviewed_on'), __('reports.columns.out_of_date'),
        ];
        $this->row($sheet, $r++, $columns);
        foreach ($this->data->standards as $standard) {
            foreach ($standard['requirements'] as $requirement) {
                foreach ($requirement['rows'] as $row) {
                    $base = [$standard['code'], $requirement['code'], $row['object'], $row['reference'], $this->verdictText($row['verdict']) ?: __('reports.not_evaluated')];
                    if ($row['reviews'] === []) {
                        $this->row($sheet, $r++, [...$base, '', __('reports.review_counts.none')]);
                    }
                    foreach ($row['reviews'] as $review) {
                        $this->row($sheet, $r++, [...$base, $review['reviewer'], $review['label'], $review['comment'], $review['date'], $review['stale'] ? __('common.yes') : '']);
                    }
                }
            }
        }
        $this->wrap($sheet, $detailHeader + 1, $r - 1, count($columns));
        $this->brandHeader($sheet, $detailHeader, count($columns));
        $this->widths($sheet, [24, 22, 30, 20, 16, 22, 14, 60, 18, 12]);
    }
}
