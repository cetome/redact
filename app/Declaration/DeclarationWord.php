<?php

namespace App\Declaration;

use App\Reports\DocxFinisher;
use App\Reports\WordReport;

/**
 * The EU declaration of conformity as a Word document in one official language: the blocks of
 * DeclarationContent (Annex VI of Directive 2014/53/EU point by point) in the look of the REDact
 * reports, without the REDact logo (it is the manufacturer's document). The signature is left
 * blank; a draft carries the "DRAFT — not valid" notice and a watermark on every page.
 */
final class DeclarationWord
{
    public readonly DeclarationContent $content;

    public function __construct(DeclarationData $data, ?string $lang = null)
    {
        $this->content = new DeclarationContent($data, $lang);
    }

    public function save(string $path): void
    {
        $content = $this->content;
        $doc = new WordDoc($content->documentTitle(), $content->footer(), $content->creator(), '{PAGE} / {NUMPAGES}');

        foreach ($content->blocks() as $block) {
            match ($block['type']) {
                'notice' => $doc->notice($block['title'], $block['lines']),
                'title' => $this->title($doc, $block['text']),
                'point' => $this->point($doc, $block),
                'signature' => $this->signature($doc, $block),
                default => null,
            };
        }

        $doc->save($path, $content->watermark());
    }

    private function title(WordDoc $doc, string $text): void
    {
        $doc->title($text);
        $doc->spacer(120);
    }

    /** @param  array<string, mixed>  $block */
    private function point(WordDoc $doc, array $block): void
    {
        $doc->point($block['number'], $block['heading']);
        foreach ($block['lines'] as $line) {
            $doc->underLine($line['text'], $line['bold'], $line['placeholder']);
        }
        if ($block['image'] !== null) {
            [$w, $h] = WordDoc::imageSize($block['image'], 170);
            $doc->section->addImage($block['image'], ['width' => $w, 'height' => $h, 'spaceBefore' => 80, 'spaceAfter' => 80, 'marginLeft' => 20]);
        }
        if ($block['table'] !== null) {
            $table = $doc->table();
            if ($block['header'] !== null) {
                $doc->headerRow($table, [$block['header'][0] => 65, $block['header'][1] => 35]);
            }
            foreach ($block['table'] as [$reference, $requirement]) {
                $doc->row($table, [[$reference, 65], [$requirement, 35]]);
            }
        }
    }

    /** @param  array<string, mixed>  $block */
    private function signature(WordDoc $doc, array $block): void
    {
        $doc->spacer(200);
        foreach ($block['rows'] as [$heading, $value]) {
            $run = $doc->section->addTextRun(['spaceAfter' => 80, 'keepNext' => true]);
            $run->addText($doc->clean($heading.' '), ['bold' => true]);
            $run->addText($doc->clean($value));
        }
        // Room to sign, over a thin line.
        $indent = ['left' => (int) (WordReport::TEXT_WIDTH * 0.55)];
        $doc->section->addText('', [], ['spaceBefore' => 900, 'spaceAfter' => 0, 'keepNext' => true]);
        $doc->section->addText(DocxFinisher::TAB, [], ['borderTopSize' => 4, 'borderTopColor' => '475569', 'spaceAfter' => 0, 'indentation' => $indent]);
        if ($block['caption'] !== null) {
            $doc->text($block['caption'], ['size' => 8, 'color' => WordDoc::MUTED], ['indentation' => $indent]);
        }
    }

    /** "EU-DoC_Smart-lock_2.0_FR.docx": one file of the all-languages zip. */
    public static function fileName(DeclarationData $data, string $lang): string
    {
        return DeclarationContent::fileName($data, $lang, 'docx');
    }
}
