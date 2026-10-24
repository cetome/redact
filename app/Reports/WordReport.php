<?php

namespace App\Reports;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Style\Tab;

/**
 * The Word documents of a version, in CRAted's exact look (REPORT.md section 5,
 * word-export.ts): A4 with 2 cm margins, Gill Sans MT titles and Leelawadee UI
 * text, cetome's blue #0044AA, a cover with the 9.5 cm blue-bordered title box
 * and the disclaimer pinned at the bottom, a link-only contents page (no TOC
 * field), the running header from page 2 and "Page N of M" in the footer.
 * The only fields are PAGE and NUMPAGES, so Word never asks to update fields.
 *
 * No AI badge in the document: identification lives in the app. The disclaimer
 * says that parts may have been drafted with AI assistance and accepted by the
 * people named in the document.
 */
final class WordReport
{
    public const BRAND = '0044AA';

    public const BRAND_TINT = 'E5ECF7';

    public const TITLE_FONT = 'Gill Sans MT';

    public const TEXT_FONT = 'Leelawadee UI';

    private const GRID = 'E2E8F0';

    private const MUTED = '64748B';

    private const PLACEHOLDER = '94A3B8';

    private const LABEL = '475569';

    private const LIGHT = 'F1F5F9';

    private const ROW = 'F8FAFC';

    private const RED = 'B3261E';

    private const AMBER = '9A5B00';

    /** A combination a decision node cannot lead to, in the Summary of Decision Trees. */
    private const IMPOSSIBLE = 'D9DEE5';

    /** fill, text: the verdict badges of CRAted. */
    private const VERDICT_FILL = [
        'PASS' => ['E4F5EC', '1A7F4B'],
        'NOT_APPLICABLE' => ['334155', 'FFFFFF'],
        'FAIL' => ['FDE7E5', 'B3261E'],
        'INCONCLUSIVE' => ['FFF2D9', '9A5B00'],
        'pending' => ['F1F5F9', '64748B'],
    ];

    private const BUCKET_FILL = [
        'pass' => 'PASS',
        'fail' => 'FAIL',
        'na' => 'NOT_APPLICABLE',
        'inconclusive' => 'INCONCLUSIVE',
        'missing' => 'pending',
        'invalid' => 'INCONCLUSIVE',
    ];

    private const REVIEW_FILL = [
        'agree' => 'PASS',
        'disagree' => 'FAIL',
        'question' => 'INCONCLUSIVE',
        'approved' => 'PASS',
        'changes_requested' => 'FAIL',
        'none' => 'pending',
        'stale' => 'INCONCLUSIVE',
    ];

    /** A4 portrait, 2 cm margins, header and footer 1.25 cm from the edge (twips). */
    private const PAGE = [
        'pageSizeW' => 11906,
        'pageSizeH' => 16838,
        'marginTop' => 1134,
        'marginRight' => 1134,
        'marginBottom' => 1134,
        'marginLeft' => 1134,
        'headerHeight' => 708,
        'footerHeight' => 708,
    ];

    /** The width of the text, in twips (17 cm). */
    public const TEXT_WIDTH = 11906 - 2 * 1134;

    /** The side of the cover's title box (9.5 cm) and the room inside its border, in twips. */
    private const TITLE_BOX = 5400;

    private const TITLE_BOX_MARGIN = 360;

    private const DESCRIPTION_MAX = 700;

    /** @var list<array{title: string, level: int, anchor: string}> */
    private array $entries = [];

    private int $anchors = 0;

    private bool $bodyStarted = false;

    public function __construct(
        private readonly ReportData $data,
        private readonly ReportKind $kind,
    ) {}

    public static function logoPath(): string
    {
        return resource_path('report-assets/redact-logo.png');
    }

    public function save(string $path): void
    {
        Settings::setOutputEscapingEnabled(true);

        // First pass: the outline of the body, for the contents page that comes before it
        $this->reset();
        $scratch = new PhpWord;
        $this->styles($scratch);
        $this->body($scratch->addSection(self::PAGE));
        $outline = $this->entries;

        $this->reset();
        $word = new PhpWord;
        $this->styles($word);
        $info = $word->getDocInfo();
        $info->setCreator('REDact');
        $info->setLastModifiedBy('REDact');
        $info->setTitle($this->data->product['name'].' · '.$this->kind->label());
        $info->setSubject($this->data->title());
        $info->setCompany((string) $this->data->product['organisation']);

        $section = $word->addSection(self::PAGE);
        $this->frame($section);
        $this->cover($section);
        $this->contents($section, $outline);
        $this->body($section);

        IOFactory::createWriter($word, 'Word2007')->save($path);
        DocxFinisher::finish($path, self::TEXT_WIDTH);
    }

    private function reset(): void
    {
        $this->entries = [];
        $this->anchors = 0;
        $this->bodyStarted = false;
    }

    private function styles(PhpWord $word): void
    {
        $word->setDefaultFontName(self::TEXT_FONT);
        $word->setDefaultFontSize(10);
        $spacing = ['spaceBefore' => 240, 'spaceAfter' => 120, 'keepNext' => true];
        $word->addTitleStyle(1, ['name' => self::TITLE_FONT, 'size' => 14, 'bold' => true, 'color' => self::BRAND], $spacing);
        $word->addTitleStyle(2, ['name' => self::TEXT_FONT, 'size' => 11, 'bold' => true, 'color' => self::BRAND], $spacing);
        $word->addTitleStyle(3, ['name' => self::TEXT_FONT, 'size' => 10, 'bold' => true, 'color' => self::BRAND], $spacing);
        $word->addParagraphStyle(DocxFinisher::DISCLAIMER_STYLE, ['spaceBefore' => 0, 'spaceAfter' => 0]);
    }

    // ---- Text helpers --------------------------------------------------------------------------

    /** Text without the characters XML refuses and without the finisher's markers. */
    private function clean(string $text): string
    {
        $text = str_replace([DocxFinisher::TAB, DocxFinisher::BREAK, "\r"], ['', '', ''], $text);

        return (string) preg_replace('/[^\x{9}\x{A}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    /**
     * @param  array{bold?: bool, color?: string, size?: float|int, italic?: bool, font?: string, underline?: string}  $look
     * @return array<string, mixed>
     */
    private function font(array $look): array
    {
        return array_filter([
            'bold' => $look['bold'] ?? null,
            'color' => $look['color'] ?? null,
            'size' => $look['size'] ?? null,
            'italic' => $look['italic'] ?? null,
            'name' => $look['font'] ?? null,
            'underline' => $look['underline'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * A paragraph of one run (CRAted's `paragraph`), 6 pt after unless told otherwise.
     *
     * @param  array<string, mixed>  $look
     * @param  array<string, mixed>  $paragraph
     */
    private function paragraph(AbstractContainer $container, string $text, array $look = [], array $paragraph = []): void
    {
        $container->addText($this->clean($text), $this->font($look), ['spaceAfter' => $look['after'] ?? 120, 'spaceBefore' => $look['before'] ?? 0] + $paragraph);
    }

    /**
     * The lines of a text as paragraphs, or a grey placeholder when it is empty (CRAted's `lines`).
     *
     * @param  array<string, mixed>  $look
     */
    private function lines(AbstractContainer $container, string $text, array $look, string $empty): void
    {
        $text = trim($this->clean($text));
        if ($text === '') {
            $this->paragraph($container, $empty, ['italic' => true, 'color' => self::PLACEHOLDER, 'after' => 0, 'size' => $look['size'] ?? null]);

            return;
        }
        foreach (preg_split('/\n+/', $text) ?: [] as $line) {
            $this->paragraph($container, $line, $look);
        }
    }

    /** A bold label then its value, in one paragraph ("Product description: …"). */
    private function labelled(AbstractContainer $container, string $label, string $value, int $after = 120): void
    {
        $run = $container->addTextRun(['spaceAfter' => $after, 'spaceBefore' => 0]);
        $run->addText($this->clean($label.' '), ['bold' => true]);
        $run->addText($this->clean($value));
    }

    private function meta(AbstractContainer $container, string $text, int $after = 120, bool $keepNext = false): void
    {
        $this->paragraph($container, $text, ['color' => self::MUTED, 'size' => 9, 'after' => $after], $keepNext ? ['keepNext' => true] : []);
    }

    private function placeholder(AbstractContainer $container, string $text): void
    {
        $this->paragraph($container, $text, ['italic' => true, 'color' => self::PLACEHOLDER, 'size' => 9]);
    }

    private function spacer(AbstractContainer $container, int $after = 60): void
    {
        $container->addText('', [], ['spaceAfter' => $after, 'spaceBefore' => 0]);
    }

    /** A heading with its bookmark, recorded for the contents page. */
    private function heading(Section $section, string $title, int $level): void
    {
        if ($level === 1) {
            if ($this->bodyStarted) {
                $section->addPageBreak();
            }
            $this->bodyStarted = true;
        }
        $this->anchors++;
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $title), '_');
        $anchor = substr('h_'.$this->anchors.'_'.$slug, 0, 40);
        $this->entries[] = ['title' => $title, 'level' => $level, 'anchor' => $anchor];

        $run = $section->addTextRun('Heading'.$level);
        $run->addBookmark($anchor);
        $run->addText($this->clean($title));
    }

    // ---- Table helpers -------------------------------------------------------------------------

    private function width(float $percent): int
    {
        return (int) round(self::TEXT_WIDTH * $percent / 100);
    }

    /** A table across the text: 0.5 pt #E2E8F0 grid, cell margins 60 / 100 twips. */
    private function table(AbstractContainer $container): Table
    {
        return $container->addTable([
            'width' => self::TEXT_WIDTH,
            'unit' => 'dxa',
            'layout' => 'fixed',
            'borderSize' => 4,
            'borderColor' => self::GRID,
            'cellMarginTop' => 60,
            'cellMarginBottom' => 60,
            'cellMarginLeft' => 100,
            'cellMarginRight' => 100,
        ]);
    }

    /** @param  array<string, mixed>  $style */
    private function cell(Table $table, float $percent, ?string $fill = null, array $style = []): Cell
    {
        return $table->addCell($this->width($percent), ['noWrap' => false, 'valign' => 'top'] + ($fill ? ['bgColor' => $fill] : []) + $style);
    }

    /**
     * The header row: bold 9 pt white on the blue, repeated on every page the table runs on.
     *
     * @param  array<string, int|float>  $columns  label => width in percent
     */
    private function headerRow(Table $table, array $columns): void
    {
        $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($columns as $label => $percent) {
            $this->paragraph($this->cell($table, $percent, self::BRAND), (string) $label, ['bold' => true, 'size' => 9, 'color' => 'FFFFFF', 'after' => 0]);
        }
    }

    /** A cell holding a badge: centred bold 9 pt on the badge's fill. */
    private function badgeCell(Table $table, float $percent, string $label, string $look): void
    {
        [$fill, $color] = self::VERDICT_FILL[$look] ?? self::VERDICT_FILL['pending'];
        $this->paragraph($this->cell($table, $percent, $fill), $label, ['bold' => true, 'size' => 9, 'color' => $color, 'after' => 0], ['alignment' => 'center']);
    }

    private function verdictCell(Table $table, float $percent, ?string $verdict): void
    {
        $this->badgeCell($table, $percent, $verdict ? __('verdicts.'.$verdict) : __('reports.not_evaluated'), $verdict ?? 'pending');
    }

    /** A plain text cell. */
    private function textCell(Table $table, float $percent, string $text, array $look = [], ?string $fill = null, string $empty = '—'): void
    {
        $this->lines($this->cell($table, $percent, $fill), $text, ['after' => 0] + $look, $empty);
    }

    /**
     * Two columns, the label bold on the light row fill (30 / 70).
     *
     * @param  array<string, string>  $rows
     */
    private function facts(AbstractContainer $container, array $rows): void
    {
        $table = $this->table($container);
        foreach ($rows as $label => $value) {
            $table->addRow(null, ['cantSplit' => true]);
            $this->paragraph($this->cell($table, 30, self::ROW), $label, ['bold' => true, 'after' => 0]);
            $this->textCell($table, 70, $value);
        }
        $this->spacer($container);
    }

    /**
     * A counts row as CRAted's summary: one header row, then the figures at 14 pt on their fill.
     *
     * @param  list<array{label: string, value: int, look: string}>  $figures
     */
    private function counts(AbstractContainer $container, array $figures): void
    {
        $table = $this->table($container);
        $percent = 100 / max(1, count($figures));
        $this->headerRow($table, array_combine(array_column($figures, 'label'), array_fill(0, count($figures), $percent)));
        $table->addRow(null, ['cantSplit' => true]);
        foreach ($figures as $figure) {
            [$fill, $color] = self::VERDICT_FILL[$figure['look']] ?? self::VERDICT_FILL['pending'];
            $this->paragraph($this->cell($table, $percent, $fill), (string) $figure['value'], ['bold' => true, 'size' => 14, 'color' => $color, 'after' => 0], ['alignment' => 'center']);
        }
        $this->spacer($container);
    }

    // ---- Frame, cover, contents ----------------------------------------------------------------

    /** @return array{0: float, 1: float} width and height in points for a width in pixels */
    private function logoSize(int $pixels): array
    {
        [$w, $h] = getimagesize(self::logoPath()) ?: [1200, 238];
        $width = $pixels * 0.75;

        return [$width, round($width * $h / max(1, $w), 2)];
    }

    /**
     * From page 2: the logo at the left, "<kind> · <product>" over "Exported by …" at the right,
     * a rule under it. Every page: "Produced with REDact" and "Page N of M" over a rule.
     */
    private function frame(Section $section): void
    {
        $grey = ['size' => 8, 'color' => self::MUTED];
        $first = $section->addHeader('first');
        $first->addText('', [], ['spaceAfter' => 0]);

        $header = $section->addHeader();
        [$logoWidth, $logoHeight] = $this->logoSize(110);
        $logoColumn = 110 * 15 + 300;
        $table = $header->addTable([
            'width' => self::TEXT_WIDTH,
            'unit' => 'dxa',
            'layout' => 'fixed',
            'borderSize' => 0,
            'borderColor' => DocxFinisher::NO_BORDER,
            'cellMargin' => 0,
        ]);
        $table->addRow();
        $table->addCell($logoColumn, ['valign' => 'center', 'noWrap' => false])
            ->addImage(self::logoPath(), ['width' => $logoWidth, 'height' => $logoHeight]);
        $right = $table->addCell(self::TEXT_WIDTH - $logoColumn, ['valign' => 'center', 'noWrap' => false]);
        $line = $right->addTextRun(['alignment' => 'right', 'spaceBefore' => 0, 'spaceAfter' => 0]);
        $line->addText($this->clean($this->kind->label()), $grey + ['bold' => true]);
        $line->addText($this->clean(' · '.$this->data->title()), $grey);
        $right->addText($this->clean($this->exportedLine(false)), $grey, ['alignment' => 'right', 'spaceBefore' => 0, 'spaceAfter' => 0]);
        $header->addText('', [], ['borderBottomSize' => 4, 'borderBottomColor' => DocxFinisher::RULE, 'spaceBefore' => 60, 'spaceAfter' => 240]);

        foreach (['default', 'first'] as $type) {
            $footer = $type === 'default' ? $section->addFooter() : $section->addFooter('first');
            $footer->addText('', [], ['borderTopSize' => 4, 'borderTopColor' => DocxFinisher::RULE, 'spaceBefore' => 240, 'spaceAfter' => 0]);
            $footer->addPreserveText(
                __('reports.produced_with').DocxFinisher::TAB.__('reports.page_of', ['page' => '{PAGE}', 'pages' => '{NUMPAGES}']),
                $grey,
                ['tabs' => [new Tab('right', self::TEXT_WIDTH)], 'spaceAfter' => 0, 'spaceBefore' => 0],
            );
        }
    }

    private function exportedLine(bool $sentence = true): string
    {
        return __($sentence ? 'reports.exported_sentence' : 'reports.exported_by', ['name' => $this->data->exportedBy, 'date' => $this->data->exportedAt]);
    }

    private function cover(Section $section): void
    {
        // The logo at the very top of the page, 180 px wide
        [$logoWidth, $logoHeight] = $this->logoSize(180);
        $section->addImage(self::logoPath(), ['width' => $logoWidth, 'height' => $logoHeight, 'spaceAfter' => 0, 'spaceBefore' => 0]);
        // 3.5 cm between the logo and the box
        $section->addText('', [], ['spaceBefore' => 2000, 'spaceAfter' => 0]);
        $this->titleBox($section);
        $section->addText('', [], ['spaceBefore' => 0, 'spaceAfter' => 360]);
        $this->productBlock($section);

        $disclaimer = $section->addTextRun(DocxFinisher::DISCLAIMER_STYLE);
        $disclaimer->addText(
            $this->clean($this->kind->disclaimer().' '.__('reports.ai_disclaimer')).DocxFinisher::BREAK.$this->clean($this->exportedLine()),
            ['size' => 9],
        );
        $section->addPageBreak();
    }

    /** The square title box: 2 pt blue border, the kind, the product in Gill Sans MT, "version N", the day. */
    private function titleBox(Section $section): void
    {
        $box = $section->addTable([
            'width' => self::TITLE_BOX,
            'unit' => 'dxa',
            'layout' => 'fixed',
            'borderSize' => 16,
            'borderColor' => self::BRAND,
            'cellMargin' => self::TITLE_BOX_MARGIN,
        ]);
        $box->addRow(self::TITLE_BOX - 2 * self::TITLE_BOX_MARGIN);
        $cell = $box->addCell(self::TITLE_BOX, ['valign' => 'top', 'noWrap' => false]);
        $this->paragraph($cell, $this->kind->label(), ['bold' => true, 'size' => 12, 'color' => self::BRAND, 'after' => 0]);
        $this->paragraph($cell, $this->data->product['name'], ['bold' => true, 'size' => 28, 'color' => self::BRAND, 'font' => self::TITLE_FONT, 'before' => 1200, 'after' => 0]);
        if ($this->data->version['label'] !== '') {
            $this->paragraph($cell, __('reports.version_word', ['label' => $this->data->version['label']]), ['size' => 14, 'color' => self::BRAND, 'before' => 120, 'after' => 0]);
        }
        $this->paragraph($cell, $this->data->exportedOn, ['size' => 12, 'before' => 600, 'after' => 0]);
    }

    /** Under the title box: the organisation, the reference, the standards in scope and the description. */
    private function productBlock(Section $section): void
    {
        $product = $this->data->product;
        if ($product['organisation'] !== '') {
            $this->labelled($section, __('reports.cover.organisation'), $product['organisation']);
        }
        if ($product['reference'] !== '') {
            $this->labelled($section, __('reports.cover.reference'), $product['reference']);
        }
        $standards = $this->data->scoping['standards'];
        $this->labelled($section, __('reports.cover.standards'), $standards !== [] ? implode(', ', $standards) : __('reports.none_in_scope'));

        $description = trim(str_replace("\r", '', $product['description']));
        if ($description !== '') {
            if (mb_strlen($description) > self::DESCRIPTION_MAX) {
                $description = preg_replace('/\s+\S*$/u', '', mb_substr($description, 0, self::DESCRIPTION_MAX)).'…';
            }
            foreach (preg_split('/\n+/', $description) ?: [] as $i => $line) {
                $run = $section->addTextRun(['spaceAfter' => 80, 'spaceBefore' => 0]);
                if ($i === 0) {
                    $run->addText($this->clean(__('reports.cover.description').' '), ['bold' => true]);
                }
                $run->addText($this->clean($line), ['size' => 10]);
            }
        }
    }

    /**
     * The contents on its own page: every heading as a link to its bookmark, no page numbers
     * (only a field could compute them, and fields make Word ask to update the document).
     *
     * @param  list<array{title: string, level: int, anchor: string}>  $outline
     */
    private function contents(Section $section, array $outline): void
    {
        $this->paragraph($section, __('reports.contents'), ['bold' => true, 'size' => 16, 'after' => 240]);
        foreach ($outline as $entry) {
            $top = $entry['level'] === 1;
            $section->addLink(
                $entry['anchor'],
                $this->clean($entry['title']),
                ['size' => $top ? 11 : 10, 'bold' => $top, 'color' => self::BRAND, 'underline' => 'single'],
                ['indentation' => ['left' => ($entry['level'] - 1) * 360], 'spaceAfter' => $top ? 80 : 40, 'spaceBefore' => $top ? 120 : 0],
                true,
            );
        }
        $section->addPageBreak();
    }

    // ---- Body ----------------------------------------------------------------------------------

    private function body(Section $section): void
    {
        match ($this->kind) {
            ReportKind::Assessment => $this->assessmentBody($section),
            ReportKind::Trees => $this->decisionTrees($section),
            ReportKind::Review => $this->reviewBody($section),
        };
    }

    private function assessmentBody(Section $section): void
    {
        $this->productAndScope($section);
        $this->riskAssessment($section);
        $this->inventory($section);
        $this->decisionTrees($section);
        $this->einfo($section);
        $this->reviewStatus($section);
    }

    private function productAndScope(Section $section): void
    {
        $data = $this->data;
        $this->heading($section, __('reports.sections.product_scope'), 1);

        $this->heading($section, __('reports.sections.product'), 2);
        $this->facts($section, array_filter([
            __('reports.fields.product') => $data->product['name'],
            __('reports.fields.reference') => $data->product['reference'],
            __('reports.fields.organisation') => $data->product['organisation'],
            __('reports.fields.description') => $data->product['description'],
            __('reports.fields.models') => $data->product['models'],
            __('reports.fields.intended_use') => $data->version['intended_use'],
        ], fn ($v, $k) => $v !== '' || $k === __('reports.fields.product'), ARRAY_FILTER_USE_BOTH));

        $this->heading($section, __('reports.sections.version'), 2);
        $this->facts($section, [
            __('reports.fields.version') => $data->version['label'],
            ...array_filter([
                __('reports.fields.hardware_version') => $data->version['hardware_version'],
                __('reports.fields.software_version') => $data->version['software_version'],
            ], fn ($v) => $v !== ''),
            __('reports.fields.status') => $data->version['status'],
            __('reports.fields.author') => $data->version['author'],
            __('reports.fields.completion') => $data->version['completion'],
            __('reports.fields.content_pack') => $data->version['content_pack'],
        ]);

        $this->heading($section, __('reports.sections.team'), 2);
        $table = $this->table($section);
        $this->headerRow($table, [__('reports.columns.name') => 45, __('reports.columns.role') => 30, __('reports.columns.membership') => 25]);
        foreach ($data->team as $person) {
            $table->addRow(null, ['cantSplit' => true]);
            $this->textCell($table, 45, $person['name']);
            $this->textCell($table, 30, $person['role']);
            $this->textCell($table, 25, $person['external'] ? __('roles.external') : __('roles.internal'));
        }
        $this->spacer($section);

        $this->heading($section, __('reports.sections.scoping'), 2);
        $scoping = $data->scoping;
        if ($scoping['sections'] === []) {
            $this->placeholder($section, __('reports.empty.scoping'));
        }
        foreach ($scoping['sections'] as $part) {
            if ($part['title'] !== '') {
                $this->paragraph($section, $part['title'], ['bold' => true, 'color' => self::BRAND, 'before' => 120], ['keepNext' => true]);
            }
            $table = $this->table($section);
            $this->headerRow($table, [__('reports.columns.question') => 70, __('reports.columns.answer') => 30]);
            foreach ($part['groups'] as $group) {
                if ($group['title'] !== '') {
                    $table->addRow(null, ['cantSplit' => true]);
                    $this->paragraph($this->cell($table, 100, self::ROW, ['gridSpan' => 2]), $group['title'], ['bold' => true, 'after' => 0]);
                }
                foreach ($group['questions'] as $question) {
                    $table->addRow(null, ['cantSplit' => true]);
                    $this->textCell($table, 70, $question['label']);
                    $this->textCell($table, 30, $question['answer'], [], null, __('reports.not_answered'));
                }
            }
            $this->spacer($section);
        }

        $this->heading($section, __('reports.sections.scoping_outcome'), 2);
        $table = $this->table($section);
        $this->headerRow($table, [__('reports.columns.article') => 20, __('reports.columns.name') => 60, __('reports.columns.in_scope') => 20]);
        foreach ($scoping['articles'] as $article) {
            $table->addRow(null, ['cantSplit' => true]);
            $this->textCell($table, 20, $article['code']);
            $this->textCell($table, 60, $article['name']);
            $this->badgeCell($table, 20, $article['in_scope'] ? __('common.yes') : __('common.no'), $article['in_scope'] ? 'PASS' : 'pending');
        }
        $this->spacer($section);
        $this->labelled($section, __('reports.cover.standards'), $scoping['standards'] !== [] ? implode(', ', $scoping['standards']) : __('reports.none_in_scope'));
        foreach ($scoping['restrictions'] as $restriction) {
            $this->labelled($section, $restriction['label'].':', __('reports.restriction_'.$restriction['state']));
        }
        $this->paragraph($section, $scoping['self_assessment'], ['bold' => true]);
        foreach ($scoping['messages'] as $message) {
            $this->meta($section, $message);
        }
    }

    private function riskAssessment(Section $section): void
    {
        $risks = $this->data->risks;
        $this->heading($section, __('reports.sections.risks'), 1);

        $this->heading($section, __('reports.sections.risk_matrix'), 2);
        $matrix = $risks['matrix'];
        if ($matrix['cells'] === []) {
            $this->placeholder($section, __('reports.empty.matrix'));
        } else {
            $columns = count($matrix['likelihoods']);
            $side = 24;
            $each = (100 - $side) / max(1, $columns);
            $table = $this->table($section);
            $header = [__('reports.columns.impact_likelihood') => $side];
            foreach ($matrix['likelihoods'] as $likelihood) {
                $header[(string) $likelihood] = $each;
            }
            $this->headerRow($table, $header);
            foreach ($matrix['impacts'] as $impact) {
                $table->addRow(null, ['cantSplit' => true]);
                $this->paragraph($this->cell($table, $side, self::ROW), (string) $impact, ['bold' => true, 'after' => 0], ['alignment' => 'center']);
                foreach ($matrix['likelihoods'] as $likelihood) {
                    $level = $matrix['levels'][$matrix['cells'][$impact][$likelihood] ?? 0] ?? null;
                    $this->paragraph(
                        $this->cell($table, $each, $level['color'] ?? self::LIGHT),
                        $level['name'] ?? '—',
                        ['bold' => true, 'size' => 8, 'color' => $level ? 'FFFFFF' : self::MUTED, 'after' => 0],
                        ['alignment' => 'center'],
                    );
                }
            }
            $this->paragraph($section, __('reports.matrix_note'), ['color' => self::MUTED, 'size' => 9, 'before' => 60]);
        }

        $this->heading($section, __('reports.sections.risk_register'), 2);
        if ($risks['entries'] === []) {
            $this->placeholder($section, __('reports.empty.risks'));

            return;
        }
        $table = $this->table($section);
        $this->headerRow($table, [
            '#' => 5,
            __('reports.columns.threat') => 27,
            __('reports.columns.stride') => 10,
            __('reports.columns.score') => 11,
            __('reports.columns.level') => 13,
            __('reports.columns.remediation') => 21,
            __('reports.columns.residual') => 13,
        ]);
        foreach ($risks['entries'] as $entry) {
            $table->addRow(null, ['cantSplit' => true]);
            $this->textCell($table, 5, (string) $entry['number'], ['size' => 9]);

            $threat = $this->cell($table, 27);
            $this->paragraph($threat, $entry['threat'], ['bold' => true, 'size' => 9, 'after' => 40]);
            foreach (array_filter([$entry['description'], $entry['risk']]) as $text) {
                $this->lines($threat, $text, ['size' => 8, 'after' => 40], '');
            }
            $refs = array_filter([
                $entry['articles'] !== [] ? __('reports.articles_list', ['list' => implode(', ', $entry['articles'])]) : '',
                $entry['requirements'] !== [] ? __('reports.requirements_list', ['list' => implode(', ', $entry['requirements'])]) : '',
            ]);
            if ($refs !== []) {
                $this->paragraph($threat, implode(' · ', $refs), ['size' => 8, 'color' => self::MUTED, 'after' => 0]);
            }

            $this->textCell($table, 10, implode(', ', $entry['stride']), ['size' => 8]);
            $this->textCell($table, 11, $entry['score'] !== null ? $entry['impact'].' × '.$entry['likelihood'].' = '.$entry['score'] : '', ['size' => 9]);
            $this->levelCell($table, 13, $entry['level']);

            $remediation = $this->cell($table, 21);
            $this->lines($remediation, $entry['remediation'], ['size' => 9, 'after' => 40], '—');
            if ($entry['implementation'] !== '') {
                $this->paragraph($remediation, __('reports.columns.implementation'), ['bold' => true, 'size' => 8, 'color' => self::LABEL, 'after' => 20]);
                $this->lines($remediation, $entry['implementation'], ['size' => 8, 'after' => 20], '');
            }

            $residual = $this->levelCell($table, 13, $entry['residual']);
            if ($entry['residual_worse']) {
                $this->paragraph($residual, __('reports.residual_worse'), ['bold' => true, 'size' => 8, 'color' => self::RED, 'after' => 0], ['alignment' => 'center']);
            }
        }
        $this->spacer($section);
    }

    /** @param  array{name: string, color: string}|null  $level */
    private function levelCell(Table $table, float $percent, ?array $level): Cell
    {
        $cell = $this->cell($table, $percent, $level['color'] ?? null);
        $this->paragraph($cell, $level['name'] ?? '—', ['bold' => true, 'size' => 9, 'color' => $level ? 'FFFFFF' : self::PLACEHOLDER, 'after' => 0], ['alignment' => 'center']);

        return $cell;
    }

    private function inventory(Section $section): void
    {
        $this->heading($section, __('reports.sections.inventory'), 1);
        foreach ($this->data->inventory as $list) {
            $this->heading($section, $list['label'], 2);
            if ($list['items'] === []) {
                $this->placeholder($section, __('reports.empty.list'));

                continue;
            }
            $weights = count($list['fields']) + 2;
            $each = 64 / $weights;
            $columns = [__('reports.columns.code') => 14, __('reports.columns.name') => 22];
            foreach ($list['fields'] as $field) {
                $columns[$field['label']] = $each;
            }
            $columns[__('reports.columns.description')] = $each * 2;
            $table = $this->table($section);
            $this->headerRow($table, $columns);
            foreach ($list['items'] as $item) {
                $table->addRow(null, ['cantSplit' => true]);
                $this->textCell($table, 14, $item['code'], ['bold' => true, 'size' => 9]);
                $this->textCell($table, 22, $item['name'], ['size' => 9]);
                foreach ($list['fields'] as $field) {
                    $this->textCell($table, $each, $item['values'][$field['key']] ?? '', ['size' => 9]);
                }
                $this->textCell($table, $each * 2, $item['description'], ['size' => 9]);
            }
            $this->spacer($section);
        }

        if ($this->data->relations !== []) {
            $this->heading($section, __('reports.sections.relations'), 2);
            foreach ($this->data->relations as $relation) {
                $this->paragraph($section, $relation['from'].' → '.$relation['to'].' · '.$relation['label'], ['bold' => true, 'color' => self::BRAND, 'before' => 120], ['keepNext' => true]);
                if ($relation['pairs'] === []) {
                    $this->placeholder($section, __('reports.empty.links'));

                    continue;
                }
                $table = $this->table($section);
                $this->headerRow($table, [$relation['from'] => 50, $relation['to'] => 50]);
                foreach ($relation['pairs'] as $pair) {
                    $table->addRow(null, ['cantSplit' => true]);
                    $this->textCell($table, 50, $pair['from'], ['size' => 9]);
                    $this->textCell($table, 50, $pair['to'], ['size' => 9]);
                }
                $this->spacer($section);
            }
        }
    }

    /** E.DT and E.Just: the counts, the Summary of Decision Trees and every requirement's detail. */
    private function decisionTrees(Section $section): void
    {
        $this->heading($section, __('reports.sections.trees'), 1);
        if ($this->data->standards === []) {
            $this->placeholder($section, __('reports.empty.standards'));

            return;
        }
        foreach ($this->data->standards as $standard) {
            $this->heading($section, $standard['name'], 2);
            $figures = $this->bucketFigures($standard['counts']);
            if (($standard['follows'] ?? 0) > 0) {
                // FAIL verdicts that follow from another walk (not walked, not in the FAIL bucket).
                $figures[] = ['label' => __('evaluations.follows.stat'), 'value' => $standard['follows'], 'look' => 'FAIL'];
            }
            $this->counts($section, $figures);

            if (count($standard['families']) > 0) {
                $table = $this->table($section);
                $columns = [__('reports.columns.family') => 34];
                foreach (ReportData::BUCKETS as $bucket) {
                    $columns[__('reports.buckets.'.$bucket)] = 11;
                }
                $this->headerRow($table, $columns);
                foreach ($standard['families'] as $family) {
                    $table->addRow(null, ['cantSplit' => true]);
                    $this->textCell($table, 34, trim($family['code'].' · '.$family['name'], ' ·'));
                    foreach (ReportData::BUCKETS as $bucket) {
                        $count = $family['counts'][$bucket];
                        [$fill, $color] = $count > 0 ? self::VERDICT_FILL[self::BUCKET_FILL[$bucket]] : [null, self::PLACEHOLDER];
                        $this->paragraph($this->cell($table, 11, $fill), (string) $count, ['bold' => true, 'size' => 9, 'color' => $color, 'after' => 0], ['alignment' => 'center']);
                    }
                }
                $this->spacer($section);
            }

            $this->heading($section, __('reports.sections.trees_summary'), 3);
            $this->treesSummary($section, $standard);

            foreach ($standard['requirements'] as $requirement) {
                $this->heading($section, $requirement['code'].' · '.$requirement['title'], 3);
                $this->requirementMeta($section, $requirement);
                $table = $this->table($section);
                $this->headerRow($table, [
                    ($requirement['targets_label'] ?? __('reports.columns.object')) => 22,
                    __('reports.columns.node') => 18,
                    __('reports.columns.verdict') => 14,
                    __('reports.columns.justification') => 46,
                ]);
                foreach ($requirement['rows'] as $row) {
                    $table->addRow(null, ['cantSplit' => true]);
                    $this->textCell($table, 22, $row['object'], ['size' => 9]);
                    $node = $this->cell($table, 18);
                    $this->paragraph($node, $row['reference'] ?: '—', ['size' => 9, 'after' => 0, 'color' => $row['reference'] ? null : self::PLACEHOLDER]);
                    if ($row['bucket'] === 'invalid') {
                        $this->paragraph($node, __('reports.invalid_end'), ['bold' => true, 'size' => 8, 'color' => self::RED, 'after' => 0]);
                    }
                    $this->verdictCell($table, 14, $row['verdict']);
                    $justification = $this->cell($table, 46);
                    $this->lines($justification, $row['justification'], ['size' => 9, 'after' => 0], __('reports.no_justification'));
                    if ($row['justified'] === false || $row['to_check']) {
                        $this->paragraph($justification, $row['justified_label'], ['bold' => true, 'size' => 8, 'color' => self::AMBER, 'after' => 0]);
                    }
                }
                // Not walked: the verdict follows from another walk, or a link is still missing.
                foreach ($requirement['follows'] ?? [] as $follow) {
                    $table->addRow(null, ['cantSplit' => true]);
                    $this->textCell($table, 22, $follow['object'], ['size' => 9]);
                    $this->textCell($table, 18, 'DT.'.$follow['source'], ['size' => 9]);
                    $this->verdictCell($table, 14, $follow['state'] === 'fail' ? 'FAIL' : null);
                    $this->textCell($table, 46, $follow['state'] === 'fail'
                        ? __('evaluations.follows.badge', ['code' => $follow['source']]).'. '.__('evaluations.follows.help', ['code' => $follow['source']])
                        : __('evaluations.follows.unlinked_help', ['code' => $follow['source']]), ['size' => 9]);
                }
                $this->spacer($section);
            }
        }
    }

    /** @param  array<string, mixed>  $requirement */
    private function requirementMeta(Section $section, array $requirement): void
    {
        $meta = array_filter([
            $requirement['clause'] !== '' ? __('reports.clause', ['clause' => $requirement['clause']]) : '',
            $requirement['family_name'] !== '' ? __('reports.family', ['family' => $requirement['family_name']]) : '',
        ]);
        if ($meta !== []) {
            $this->meta($section, implode(' · ', $meta), 120, true);
        }
    }

    /**
     * One table per standard: a row per decision node, the objects grouped by the verdict they
     * reached, the combinations a node cannot lead to in grey.
     *
     * @param  array<string, mixed>  $standard
     */
    private function treesSummary(Section $section, array $standard): void
    {
        if ($standard['requirements'] === []) {
            $this->placeholder($section, __('reports.empty.requirements'));

            return;
        }
        $table = $this->table($section);
        $this->headerRow($table, [
            __('reports.columns.node') => 22,
            __('verdicts.PASS') => 26,
            __('verdicts.FAIL') => 26,
            __('verdicts.NOT_APPLICABLE') => 26,
        ]);
        foreach ($standard['requirements'] as $requirement) {
            $table->addRow(null, ['cantSplit' => true]);
            $this->paragraph($this->cell($table, 100, self::ROW, ['gridSpan' => 4]), $requirement['code'].' · '.$requirement['title'], ['bold' => true, 'size' => 9, 'after' => 0]);
            foreach ($requirement['summary'] as $node) {
                $table->addRow(null, ['cantSplit' => true]);
                $this->textCell($table, 22, $node['reference'], ['size' => 9]);
                foreach (ReportData::VERDICTS as $verdict) {
                    $cell = $node['cells'][$verdict];
                    $box = $this->cell($table, 26, $cell['allowed'] ? null : self::IMPOSSIBLE);
                    if ($cell['objects'] === []) {
                        $this->paragraph($box, $cell['allowed'] ? '—' : '', ['size' => 9, 'color' => self::PLACEHOLDER, 'after' => 0]);
                    }
                    foreach ($cell['objects'] as $object) {
                        $this->paragraph($box, $object, ['size' => 9, 'after' => 0, 'color' => $cell['allowed'] ? null : self::RED, 'bold' => $cell['allowed'] ? null : true]);
                    }
                }
            }
        }
        $this->meta($section, __('reports.trees_summary_note'), 240);
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{label: string, value: int, look: string}>
     */
    private function bucketFigures(array $counts): array
    {
        $figures = [];
        foreach (ReportData::BUCKETS as $bucket) {
            $figures[] = ['label' => __('reports.buckets.'.$bucket), 'value' => $counts[$bucket] ?? 0, 'look' => self::BUCKET_FILL[$bucket]];
        }

        return $figures;
    }

    private function einfo(Section $section): void
    {
        $this->heading($section, __('reports.sections.einfo'), 1);
        if ($this->data->standards === []) {
            $this->placeholder($section, __('reports.empty.standards'));

            return;
        }
        foreach ($this->data->standards as $standard) {
            $this->heading($section, $standard['name'], 2);
            $rows = $this->data->einfo[$standard['code']] ?? [];
            if ($rows === []) {
                $this->placeholder($section, __('reports.empty.einfo'));

                continue;
            }
            $table = $this->table($section);
            $this->headerRow($table, [
                __('reports.columns.object') => 22,
                __('reports.columns.einfo') => 30,
                __('reports.columns.documentation') => 48,
            ]);
            foreach ($rows as $row) {
                $table->addRow(null, ['cantSplit' => true]);
                $this->textCell($table, 22, $row['object'], ['size' => 9]);
                $code = $this->cell($table, 30);
                $this->paragraph($code, $row['code'], ['bold' => true, 'size' => 8, 'color' => self::BRAND, 'after' => 20]);
                if ($row['label'] !== '') {
                    $this->paragraph($code, $row['label'], ['size' => 8, 'color' => self::LABEL, 'after' => 0]);
                }
                $this->textCell($table, 48, $row['text'], ['size' => 9], null, __('reports.einfo_missing'));
            }
            $this->spacer($section);
        }
    }

    /** The overall verdicts of the reviewers (CRAted's summary cards). */
    private function overallVerdicts(Section $section): void
    {
        $overall = $this->data->reviews['overall'];
        if ($overall === []) {
            $this->paragraph($section, __('reports.empty.overall'), ['italic' => true, 'color' => self::MUTED]);

            return;
        }
        foreach ($overall as $review) {
            $table = $this->table($section);
            $table->addRow(null, ['cantSplit' => true]);
            $who = $this->cell($table, 30);
            $this->paragraph($who, $review['reviewer'], ['bold' => true, 'after' => 0]);
            $this->paragraph($who, $review['date'], ['color' => self::MUTED, 'size' => 9, 'after' => 0]);
            $this->badgeCell($table, 20, $review['label'], self::REVIEW_FILL[$review['verdict']] ?? 'pending');
            $this->lines($this->cell($table, 50), $review['comment'], ['after' => 60], __('reports.no_comment'));
            $this->spacer($section);
        }
    }

    /** @return list<array{label: string, value: int, look: string}> */
    private function reviewCounts(): array
    {
        $figures = [];
        foreach ($this->data->reviews['counts'] as $key => $value) {
            $figures[] = ['label' => __('reports.review_counts.'.$key), 'value' => $value, 'look' => self::REVIEW_FILL[$key]];
        }

        return $figures;
    }

    /** In the assessment: the decisions and the reviews of the evaluations, briefly. */
    private function reviewStatus(Section $section): void
    {
        $this->heading($section, __('reports.sections.review_status'), 1);
        $this->heading($section, __('reports.sections.overall'), 2);
        $this->overallVerdicts($section);
        $this->heading($section, __('reports.sections.evaluation_reviews'), 2);
        $this->counts($section, $this->reviewCounts());
        $this->reviewTables($section, 3);
    }

    /** The review document: the decisions, the counts, then every evaluation's reviews. */
    private function reviewBody(Section $section): void
    {
        $this->heading($section, __('reports.sections.summary'), 1);
        $this->heading($section, __('reports.sections.overall'), 2);
        $this->overallVerdicts($section);
        $this->heading($section, __('reports.sections.evaluation_reviews'), 2);
        $this->counts($section, $this->reviewCounts());
        $this->meta($section, __('reports.review_note'));

        $this->heading($section, __('reports.sections.reviews_by_requirement'), 1);
        $this->reviewTables($section, 2);

        $this->heading($section, __('assessment.report.cases'), 1);
        $this->caseTables($section, 2);
    }

    /** Per standard: the assessment cases recorded in the Review step, with evidence and independence. */
    private function caseTables(Section $section, int $level): void
    {
        if ($this->data->cases === []) {
            $this->placeholder($section, $this->data->unitsExternal ? __('assessment.external_report') : __('assessment.report.none'));

            return;
        }
        $this->meta($section, __('assessment.report.cases_note'));
        foreach ($this->data->cases as $standard) {
            $this->heading($section, $standard['name'], $level);
            $table = $this->table($section);
            $this->headerRow($table, [
                __('assessment.report.case') => 24,
                __('reports.columns.verdict') => 14,
                __('assessment.report.evidence') => 40,
                __('assessment.report.recorded_by') => 22,
            ]);
            foreach ($standard['requirements'] as $requirement) {
                $table->addRow(null, ['cantSplit' => true]);
                $this->paragraph($this->cell($table, 100, self::ROW, ['gridSpan' => 4]), $requirement['code'].' · '.$requirement['title'], ['bold' => true, 'size' => 9, 'after' => 0]);
                foreach ($requirement['cases'] as $case) {
                    $table->addRow(null, ['cantSplit' => true]);
                    $name = $this->cell($table, 24);
                    $this->paragraph($name, $case['label'], ['size' => 9, 'after' => 0]);
                    if ($case['clause'] !== '') {
                        $this->paragraph($name, $case['clause'], ['size' => 8, 'color' => self::MUTED, 'after' => 0]);
                    }
                    if ($case['verdict'] === null) {
                        $this->badgeCell($table, 14, __('assessment.report.not_recorded'), 'pending');
                        $this->paragraph($this->cell($table, 40), '—', ['color' => self::PLACEHOLDER, 'after' => 0]);
                        $this->paragraph($this->cell($table, 22), '—', ['color' => self::PLACEHOLDER, 'after' => 0]);

                        continue;
                    }
                    $this->verdictCell($table, 14, $case['verdict']);
                    $evidence = $this->cell($table, 40);
                    $this->lines($evidence, $case['evidence'], ['size' => 9, 'after' => 0], '—');
                    if ($case['document'] !== '') {
                        $this->paragraph($evidence, __('assessment.report.document').': '.$case['document'], ['size' => 8, 'color' => self::MUTED, 'after' => 0]);
                    }
                    $who = $this->cell($table, 22);
                    $this->paragraph($who, $case['recorded_by'] ?: '—', ['size' => 9, 'after' => 0]);
                    $this->paragraph($who, $case['date'], ['size' => 8, 'color' => self::MUTED, 'after' => 0]);
                    $this->paragraph($who, $case['independent'] ? __('assessment.independent') : __('assessment.self'), ['size' => 8, 'bold' => true, 'color' => $case['independent'] ? self::MUTED : self::AMBER, 'after' => 0]);
                    if ($case['stale']) {
                        $this->paragraph($who, __('assessment.stale'), ['size' => 8, 'color' => self::AMBER, 'after' => 0]);
                    }
                }
            }
            $this->spacer($section);
        }
    }

    /** Per standard: every evaluation with its verdict and each reviewer's verdict and comment. */
    private function reviewTables(Section $section, int $level): void
    {
        if ($this->data->standards === []) {
            $this->placeholder($section, __('reports.empty.standards'));

            return;
        }
        foreach ($this->data->standards as $standard) {
            $this->heading($section, $standard['name'], $level);
            if ($standard['requirements'] === []) {
                $this->placeholder($section, __('reports.empty.requirements'));

                continue;
            }
            $table = $this->table($section);
            $this->headerRow($table, [
                __('reports.columns.requirement_object') => 26,
                __('reports.columns.verdict') => 14,
                __('reports.columns.reviewer') => 18,
                __('reports.columns.review') => 14,
                __('reports.columns.comment') => 28,
            ]);
            foreach ($standard['requirements'] as $requirement) {
                $table->addRow(null, ['cantSplit' => true]);
                $this->paragraph($this->cell($table, 100, self::ROW, ['gridSpan' => 5]), $requirement['code'].' · '.$requirement['title'], ['bold' => true, 'size' => 9, 'after' => 0]);
                foreach ($requirement['rows'] as $row) {
                    $reviews = $row['reviews'] ?: [null];
                    foreach ($reviews as $i => $review) {
                        $table->addRow(null, ['cantSplit' => true]);
                        $object = $this->cell($table, 26);
                        if ($i === 0) {
                            $this->paragraph($object, $row['object'], ['size' => 9, 'after' => 0]);
                            if ($row['reference'] !== '') {
                                $this->paragraph($object, $row['reference'], ['size' => 8, 'color' => self::MUTED, 'after' => 0]);
                            }
                            $this->verdictCell($table, 14, $row['verdict']);
                        } else {
                            $this->paragraph($object, '', ['after' => 0]);
                            $this->paragraph($this->cell($table, 14), '', ['after' => 0]);
                        }
                        if ($review === null) {
                            $this->paragraph($this->cell($table, 18), '—', ['color' => self::PLACEHOLDER, 'after' => 0]);
                            $this->badgeCell($table, 14, __('reports.review_counts.none'), 'pending');
                            $this->paragraph($this->cell($table, 28), '—', ['color' => self::PLACEHOLDER, 'after' => 0]);

                            continue;
                        }
                        $who = $this->cell($table, 18);
                        $this->paragraph($who, $review['reviewer'], ['size' => 9, 'after' => 0]);
                        $this->paragraph($who, $review['date'], ['size' => 8, 'color' => self::MUTED, 'after' => 0]);
                        if ($review['stale']) {
                            $this->paragraph($who, __('reports.review_stale'), ['size' => 8, 'color' => self::AMBER, 'after' => 0]);
                        }
                        $this->badgeCell($table, 14, $review['label'], self::REVIEW_FILL[$review['verdict']] ?? 'pending');
                        $this->textCell($table, 28, $review['comment'], ['size' => 9]);
                    }
                }
            }
            $this->spacer($section);
        }
    }
}
