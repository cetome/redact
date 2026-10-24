<?php

namespace App\Declaration;

use App\Reports\DocxFinisher;
use App\Reports\WordReport;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Style\Tab;

/**
 * A short Word document in the look of the REDact reports (WordReport: A4, 2 cm margins,
 * Gill Sans MT titles, Leelawadee UI text, cetome blue #0044AA, 0.5 pt #E2E8F0 grids,
 * "Page N of M" footer), for the EU declaration of conformity, the index of the technical file
 * and the documents generated for it. No cover, no contents page.
 *
 * A text watermark ("DRAFT — not valid") is written into the header once saved (Watermark).
 */
final class WordDoc
{
    public const MUTED = '64748B';

    public const PLACEHOLDER = '94A3B8';

    public const ROW = 'F8FAFC';

    public const RED = 'B3261E';

    public const STATUS_FILL = [
        'present' => ['E4F5EC', '1A7F4B'],
        'missing' => ['FDE7E5', 'B3261E'],
        'draft' => ['FFF2D9', '9A5B00'],
        'na' => ['F1F5F9', '64748B'],
    ];

    private const PAGE = [
        'pageSizeW' => 11906, 'pageSizeH' => 16838,
        'marginTop' => 1134, 'marginRight' => 1134, 'marginBottom' => 1134, 'marginLeft' => 1134,
        'headerHeight' => 708, 'footerHeight' => 708,
    ];

    public readonly PhpWord $word;

    public readonly Section $section;

    /** $pageOf: the page counter of the footer, with {PAGE} and {NUMPAGES} (default "Page N of M" in the app's language). */
    public function __construct(string $title, string $footer, string $creator = 'REDact', ?string $pageOf = null)
    {
        Settings::setOutputEscapingEnabled(true);
        $this->word = new PhpWord;
        $this->word->setDefaultFontName(WordReport::TEXT_FONT);
        $this->word->setDefaultFontSize(10);
        $spacing = ['spaceBefore' => 240, 'spaceAfter' => 120, 'keepNext' => true];
        $this->word->addTitleStyle(1, ['name' => WordReport::TITLE_FONT, 'size' => 16, 'bold' => true, 'color' => WordReport::BRAND], $spacing);
        $this->word->addTitleStyle(2, ['name' => WordReport::TEXT_FONT, 'size' => 11, 'bold' => true, 'color' => WordReport::BRAND], $spacing);
        $info = $this->word->getDocInfo();
        $info->setCreator($creator);
        $info->setLastModifiedBy($creator);
        $info->setTitle($title);

        $this->section = $this->word->addSection(self::PAGE);
        // An empty header: the watermark goes in it.
        $this->section->addHeader()->addText('', [], ['spaceAfter' => 0]);
        $foot = $this->section->addFooter();
        $foot->addText('', [], ['borderTopSize' => 4, 'borderTopColor' => DocxFinisher::RULE, 'spaceBefore' => 240, 'spaceAfter' => 0]);
        $foot->addPreserveText(
            $this->clean($footer).DocxFinisher::TAB.($pageOf ?? __('reports.page_of', ['page' => '{PAGE}', 'pages' => '{NUMPAGES}'])),
            ['size' => 8, 'color' => self::MUTED],
            ['tabs' => [new Tab('right', WordReport::TEXT_WIDTH)], 'spaceAfter' => 0, 'spaceBefore' => 0],
        );
    }

    public function save(string $path, ?string $watermark = null): void
    {
        IOFactory::createWriter($this->word, 'Word2007')->save($path);
        DocxFinisher::finish($path, WordReport::TEXT_WIDTH);
        if ($watermark !== null) {
            Watermark::apply($path, $watermark);
        }
    }

    /** Text without the characters XML refuses and without the finisher's markers. */
    public function clean(string $text): string
    {
        $text = str_replace([DocxFinisher::TAB, DocxFinisher::BREAK, "\r"], '', $text);

        return (string) preg_replace('/[^\x{9}\x{A}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    /** @param  array<string, mixed>  $font */
    public function title(string $text, array $font = []): void
    {
        $this->section->addText($this->clean($text), ['name' => WordReport::TITLE_FONT, 'size' => 18, 'bold' => true, 'color' => WordReport::BRAND] + $font, ['spaceAfter' => 120]);
    }

    public function heading(string $text, int $level = 2): void
    {
        $this->section->addTitle($this->clean($text), $level);
    }

    /**
     * @param  array<string, mixed>  $font
     * @param  array<string, mixed>  $paragraph
     */
    public function text(string $text, array $font = [], array $paragraph = [], ?AbstractContainer $in = null): void
    {
        ($in ?? $this->section)->addText($this->clean($text), $font, ['spaceAfter' => 100, 'spaceBefore' => 0] + $paragraph);
    }

    /**
     * Each line its own paragraph; a grey placeholder when empty.
     *
     * @param  array<string, mixed>  $font
     */
    public function lines(string $text, string $empty = '', array $font = [], ?AbstractContainer $in = null): void
    {
        $text = trim($this->clean($text));
        if ($text === '') {
            if ($empty !== '') {
                $this->text($empty, ['italic' => true, 'color' => self::PLACEHOLDER] + $font, [], $in);
            }

            return;
        }
        foreach (preg_split('/\n/', $text) ?: [] as $line) {
            $this->text($line, $font, ['spaceAfter' => 40], $in);
        }
    }

    public function muted(string $text): void
    {
        $this->text($text, ['size' => 9, 'color' => self::MUTED]);
    }

    public function spacer(int $after = 80): void
    {
        $this->section->addText('', [], ['spaceAfter' => $after, 'spaceBefore' => 0]);
    }

    /** A numbered point of a legal model: "1." in blue, then its heading in bold. */
    public function point(string $number, string $heading): void
    {
        $run = $this->section->addTextRun(['spaceBefore' => 200, 'spaceAfter' => 80, 'keepNext' => true, 'indentation' => ['left' => 400, 'hanging' => 400]]);
        $run->addText($this->clean($number).DocxFinisher::TAB, ['bold' => true, 'color' => WordReport::BRAND]);
        $run->addText($this->clean($heading), ['bold' => true]);
    }

    /** One line indented under a point; a placeholder is grey and italic. */
    public function underLine(string $text, bool $bold = false, bool $placeholder = false): void
    {
        $font = $placeholder ? ['italic' => true, 'color' => self::PLACEHOLDER] : ($bold ? ['bold' => true] : []);
        $this->section->addText($this->clean($text), $font, ['spaceAfter' => 40, 'spaceBefore' => 0, 'indentation' => ['left' => 400]]);
    }

    /** Text indented under a point. */
    public function under(string $text, string $empty = '', bool $bold = false): void
    {
        $text = trim($this->clean($text));
        if ($text === '') {
            $text = $empty;
            $font = ['italic' => true, 'color' => self::PLACEHOLDER];
        } else {
            $font = $bold ? ['bold' => true] : [];
        }
        foreach (preg_split('/\n/', $text) ?: [] as $line) {
            $this->section->addText($line, $font, ['spaceAfter' => 40, 'spaceBefore' => 0, 'indentation' => ['left' => 400]]);
        }
    }

    public function table(): Table
    {
        return $this->section->addTable([
            'width' => WordReport::TEXT_WIDTH, 'unit' => 'dxa', 'layout' => 'fixed',
            'borderSize' => 4, 'borderColor' => 'E2E8F0',
            'cellMarginTop' => 60, 'cellMarginBottom' => 60, 'cellMarginLeft' => 100, 'cellMarginRight' => 100,
        ]);
    }

    public function width(float $percent): int
    {
        return (int) round(WordReport::TEXT_WIDTH * $percent / 100);
    }

    /** @param  array<string, int|float>  $columns  label => width in percent */
    public function headerRow(Table $table, array $columns): void
    {
        $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($columns as $label => $percent) {
            $cell = $table->addCell($this->width($percent), ['bgColor' => WordReport::BRAND, 'valign' => 'top']);
            $cell->addText($this->clean((string) $label), ['bold' => true, 'size' => 9, 'color' => 'FFFFFF'], ['spaceAfter' => 0]);
        }
    }

    /**
     * One row of text cells.
     *
     * @param  list<array{0: string, 1: int|float}>  $cells  text, width in percent
     */
    public function row(Table $table, array $cells, ?string $fill = null): void
    {
        $table->addRow(null, ['cantSplit' => true]);
        foreach ($cells as [$text, $percent]) {
            $cell = $table->addCell($this->width($percent), ['valign' => 'top'] + ($fill ? ['bgColor' => $fill] : []));
            $this->lines($text, '—', ['size' => 9], $cell);
        }
    }

    public function statusCell(Table $table, float $percent, string $status, string $label): void
    {
        [$fill, $color] = self::STATUS_FILL[$status] ?? self::STATUS_FILL['na'];
        $cell = $table->addCell($this->width($percent), ['bgColor' => $fill, 'valign' => 'top']);
        $cell->addText($this->clean($label), ['bold' => true, 'size' => 9, 'color' => $color], ['spaceAfter' => 0, 'alignment' => 'center']);
    }

    /**
     * Label / value rows (30 / 70).
     *
     * @param  array<string, string>  $rows
     */
    public function facts(array $rows): void
    {
        $table = $this->table();
        foreach ($rows as $label => $value) {
            $table->addRow(null, ['cantSplit' => true]);
            $table->addCell($this->width(30), ['bgColor' => self::ROW, 'valign' => 'top'])
                ->addText($this->clean($label), ['bold' => true], ['spaceAfter' => 0]);
            $this->lines($value, '—', [], $table->addCell($this->width(70), ['valign' => 'top']));
        }
        $this->spacer();
    }

    /** A boxed, tinted notice (the draft banner). */
    public function notice(string $title, array $lines, string $color = self::RED, string $fill = 'FDE7E5'): void
    {
        $table = $this->section->addTable([
            'width' => WordReport::TEXT_WIDTH, 'unit' => 'dxa', 'layout' => 'fixed',
            'borderSize' => 8, 'borderColor' => $color, 'cellMargin' => 120,
        ]);
        $table->addRow();
        $cell = $table->addCell(WordReport::TEXT_WIDTH, ['bgColor' => $fill, 'valign' => 'top']);
        $cell->addText($this->clean($title), ['bold' => true, 'color' => $color, 'size' => 11], ['spaceAfter' => 60]);
        foreach ($lines as $line) {
            $cell->addListItem($this->clean($line), 0, ['size' => 9, 'color' => $color], null, ['spaceAfter' => 0]);
        }
        $this->spacer(160);
    }

    /** @return array{0: float, 1: float} width and height in points, at most $max points wide or high */
    public static function imageSize(string $path, float $max): array
    {
        [$w, $h] = getimagesize($path) ?: [400, 300];
        $scale = $max / max(1, $w, $h);

        return [round($w * $scale, 1), round($h * $scale, 1)];
    }
}
