<?php

namespace App\Declaration;

use App\Reports\WordReport;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The EU declaration of conformity as a PDF (A4) in one official language: the same blocks as
 * the Word file (DeclarationContent), laid out by resources/views/declaration/pdf.blade.php and
 * rendered by dompdf (pure PHP) with DejaVu Sans, which covers the Cyrillic, Greek and Latin
 * Extended letters of the 23 languages of the Directive. Same rules as Word: no REDact logo,
 * the signature left blank over a line, "DRAFT — not valid" notice and watermark on every page
 * while anything is open, "N / M" page counter.
 */
final class DeclarationPdf
{
    public readonly DeclarationContent $content;

    public function __construct(DeclarationData $data, ?string $lang = null)
    {
        $this->content = new DeclarationContent($data, $lang);
    }

    public function html(): string
    {
        $content = $this->content;

        return view('declaration.pdf', [
            'lang' => $content->lang,
            'title' => $content->documentTitle(),
            'blocks' => $content->blocks(),
            'watermark' => $content->watermark(),
            'brand' => '#'.WordReport::BRAND,
            'image' => fn (string $path) => 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)),
        ])->render();
    }

    public function save(string $path): void
    {
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setTempDir(sys_get_temp_dir());
        $options->setDefaultPaperSize('a4');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->html(), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->addInfo('Title', $this->content->documentTitle());
        $pdf->addInfo('Author', $this->content->creator());
        $pdf->addInfo('Creator', 'REDact');
        $pdf->render();

        // The running footer: the declaration and product at the left, "N / M" at the right.
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $grey = [0.39, 0.45, 0.55];
        $width = $canvas->get_width();
        $y = $canvas->get_height() - 34;
        $footer = $this->content->footer();
        if (mb_strlen($footer) > 90) {
            $footer = mb_substr($footer, 0, 89).'…';
        }
        $canvas->page_text(57, $y, $footer, $font, 7, $grey);
        $canvas->page_text($width - 90, $y, '{PAGE_NUM} / {PAGE_COUNT}', $font, 7, $grey);

        file_put_contents($path, (string) $pdf->output());
    }

    /** "EU-DoC_Smart-lock_2.0_FR.pdf": one file of the all-languages zip. */
    public static function fileName(DeclarationData $data, string $lang): string
    {
        return DeclarationContent::fileName($data, $lang, 'pdf');
    }
}
