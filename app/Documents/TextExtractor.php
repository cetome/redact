<?php

namespace App\Documents;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory as WordFactory;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Plain text of an uploaded document, for the AI to read. PDF pages are
 * marked "[Page n]" so the AI can cite them; DOCX headings are kept as
 * "## Heading" so it can cite sections.
 */
class TextExtractor
{
    public const MAX_CHARS = 200_000;

    public function extract(string $path, string $kind): ?string
    {
        try {
            $text = match ($kind) {
                'pdf' => $this->pdf($path),
                'docx' => $this->docx($path),
                'xlsx' => $this->spreadsheet($path),
                default => (string) file_get_contents($path),
            };
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return self::clean($text);
    }

    public static function clean(string $text): string
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Control characters out (tabs and new lines stay), runs of blanks shortened.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        // (a tab stays a tab: spreadsheets and CSV keep their columns)
        $text = preg_replace('/ {2,}/u', ' ', $text) ?? '';
        $text = preg_replace('/ ?\t[ \t]*/u', "\t", $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

        return mb_substr(trim($text), 0, self::MAX_CHARS);
    }

    private function pdf(string $path): string
    {
        $config = new Config;
        $config->setRetainImageContent(false);
        $document = (new Parser([], $config))->parseFile($path);

        $out = [];
        foreach ($document->getPages() as $index => $page) {
            $out[] = '[Page '.($index + 1)."]\n".$page->getText();
            if (array_sum(array_map('strlen', $out)) > self::MAX_CHARS * 2) {
                break;
            }
        }

        return implode("\n\n", $out);
    }

    /**
     * Each sheet as "## Sheet: <name>" then its rows, cells separated by tabs (values as stored,
     * formulas' last results), within the character budget.
     */
    private function spreadsheet(string $path): string
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $book = $reader->load($path);
        $out = [];
        $size = 0;
        foreach ($book->getWorksheetIterator() as $sheet) {
            $out[] = '## Sheet: '.$sheet->getTitle();
            foreach ($sheet->getRowIterator() as $row) {
                $cells = [];
                $iterator = $row->getCellIterator();
                $iterator->setIterateOnlyExistingCells(true);
                foreach ($iterator as $cell) {
                    $value = $cell->isFormula() ? (string) $cell->getOldCalculatedValue() : (string) $cell->getValue();
                    $cells[] = str_replace(["\t", "\r", "\n"], ' ', trim($value));
                }
                $line = rtrim(implode("\t", $cells));
                if (trim($line) === '') {
                    continue;
                }
                $out[] = $line;
                $size += strlen($line);
                if ($size > self::MAX_CHARS) {
                    break 2;
                }
            }
        }
        $book->disconnectWorksheets();

        return implode("\n", $out);
    }

    private function docx(string $path): string
    {
        $word = WordFactory::createReader('Word2007')->load($path);
        $out = [];
        foreach ($word->getSections() as $section) {
            $this->walk($section, $out);
        }

        return implode("\n", $out);
    }

    /**
     * @param  list<string>  $out
     */
    private function walk(object $element, array &$out): void
    {
        if ($element instanceof Title) {
            $text = $element->getText();
            $out[] = '## '.(is_string($text) ? $text : $this->inline($text));

            return;
        }
        if ($element instanceof Table) {
            foreach ($element->getRows() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cells[] = trim($this->inline($cell));
                }
                $out[] = implode(' | ', $cells);
            }

            return;
        }
        if ($element instanceof AbstractContainer && ! $element instanceof TextRun) {
            foreach ($element->getElements() as $child) {
                $this->walk($child, $out);
            }

            return;
        }

        $line = $this->inline($element);
        if (trim($line) !== '') {
            $out[] = $line;
        }
    }

    /** The text of one paragraph-like element and everything inside it. */
    private function inline(mixed $element): string
    {
        if (is_string($element)) {
            return $element;
        }
        if (! is_object($element)) {
            return '';
        }
        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $parts[] = $this->inline($child);
            }

            return implode(' ', array_filter($parts, fn ($p) => $p !== ''));
        }
        if (method_exists($element, 'getTextObject')) {
            return $this->inline($element->getTextObject());
        }
        if (method_exists($element, 'getText')) {
            return $this->inline($element->getText());
        }

        return '';
    }
}
