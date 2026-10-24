<?php

namespace App\Workbooks;

use DOMDocument;
use DOMElement;

/**
 * One worksheet part, read and written cell by cell in its XML. Values go in as inline strings,
 * numbers or booleans, keeping each cell's style (s=""). A formula cell is never written unless
 * the caller says so; formulas are written as text with no cached value, Excel computes them.
 */
final class Sheet
{
    /** Excel's limit for the text of one cell. */
    public const MAX_TEXT = 32767;

    /** @var array<int, DOMElement> */
    private array $rows = [];

    /** @var array<string, DOMElement> */
    private array $cells = [];

    private bool $changed = false;

    private ?DOMElement $sheetData = null;

    public function __construct(
        private readonly XlsxPackage $package,
        public readonly string $part,
        private readonly DOMDocument $dom,
    ) {
        $sheetData = $dom->getElementsByTagNameNS(XlsxPackage::NS, 'sheetData')->item(0);
        if ($sheetData instanceof DOMElement) {
            $this->sheetData = $sheetData;
            foreach ($sheetData->childNodes as $row) {
                if ($row instanceof DOMElement && $row->localName === 'row') {
                    $this->rows[(int) $row->getAttribute('r')] = $row;
                    foreach ($row->childNodes as $cell) {
                        if ($cell instanceof DOMElement && $cell->localName === 'c') {
                            $this->cells[strtoupper($cell->getAttribute('r'))] = $cell;
                        }
                    }
                }
            }
        }
    }

    public function changed(): bool
    {
        return $this->changed;
    }

    public function dom(): DOMDocument
    {
        return $this->dom;
    }

    public function markChanged(): void
    {
        $this->changed = true;
    }

    // ---- Reading -------------------------------------------------------------------------------

    /** The value of a cell (its cached value for a formula): string, float, bool or null. */
    public function get(string $ref): string|float|bool|null
    {
        $cell = $this->cells[strtoupper($ref)] ?? null;
        if (! $cell) {
            return null;
        }
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') {
            $is = $this->child($cell, 'is');

            return $is ? XlsxPackage::text($is) : null;
        }
        $v = $this->child($cell, 'v');
        if (! $v) {
            return null;
        }
        $raw = $v->textContent;

        return match ($type) {
            's' => $this->package->sharedStrings()[(int) $raw] ?? null,
            'str' => XlsxPackage::unescape($raw),
            'b' => $raw === '1' || strtolower($raw) === 'true',
            'e' => null,
            default => $raw === '' ? null : (is_numeric($raw) ? (float) $raw : $raw),
        };
    }

    /** The value as trimmed text ('' when empty). */
    public function text(string $ref): string
    {
        $value = $this->get($ref);

        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_float($value) => floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value,
            default => trim(str_replace(["\r\n", "\r"], "\n", $value)),
        };
    }

    /** The formula text of a cell, '' for a dependent of a shared formula, null when none. */
    public function formula(string $ref): ?string
    {
        $cell = $this->cells[strtoupper($ref)] ?? null;
        $f = $cell ? $this->child($cell, 'f') : null;

        return $f ? $f->textContent : null;
    }

    public function hasFormula(string $ref): bool
    {
        return $this->formula($ref) !== null;
    }

    // ---- Writing -------------------------------------------------------------------------------

    /**
     * Write a value (null clears it). A formula cell is left alone and false returned, unless
     * $overFormula: then the formula is replaced by the value.
     */
    public function set(string $ref, string|int|float|bool|null $value, bool $overFormula = false): bool
    {
        $ref = strtoupper($ref);
        $cell = $this->cells[$ref] ?? null;
        $f = $cell ? $this->child($cell, 'f') : null;
        if ($f) {
            if (! $overFormula) {
                return false;
            }
            if ($f->getAttribute('t') === 'shared') {
                // Only plain formulas are replaced (shared ones were unshared first).
                return false;
            }
        }
        if ($value === null || $value === '') {
            if (! $cell) {
                return true;
            }
            $this->clear($cell, $overFormula);
            $this->changed = true;

            return true;
        }

        $cell ??= $this->create($ref);
        $this->clear($cell, true);
        if (is_bool($value)) {
            $cell->setAttribute('t', 'b');
            $cell->appendChild($this->dom->createElementNS(XlsxPackage::NS, 'v', $value ? '1' : '0'));
        } elseif (is_int($value) || is_float($value)) {
            $cell->appendChild($this->dom->createElementNS(XlsxPackage::NS, 'v', (string) $value));
        } else {
            $cell->setAttribute('t', 'inlineStr');
            $is = $this->dom->createElementNS(XlsxPackage::NS, 'is');
            $t = $this->dom->createElementNS(XlsxPackage::NS, 't');
            $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $t->appendChild($this->dom->createTextNode(self::cleanText($value)));
            $is->appendChild($t);
            $cell->appendChild($is);
        }
        $this->changed = true;

        return true;
    }

    /** Write a formula (without "="), with no cached value: Excel computes it on opening. */
    public function setFormula(string $ref, string $formula): void
    {
        $ref = strtoupper($ref);
        $cell = $this->cells[$ref] ?? $this->create($ref);
        $this->clear($cell, true);
        $f = $this->dom->createElementNS(XlsxPackage::NS, 'f');
        $f->appendChild($this->dom->createTextNode($formula));
        $cell->appendChild($f);
        $this->changed = true;
    }

    /** Replace the text of a plain formula. */
    public function replaceFormula(string $ref, string $formula): void
    {
        $cell = $this->cells[strtoupper($ref)] ?? null;
        $f = $cell ? $this->child($cell, 'f') : null;
        if (! $f) {
            return;
        }
        while ($f->firstChild) {
            $f->removeChild($f->firstChild);
        }
        $f->appendChild($this->dom->createTextNode($formula));
        $v = $this->child($cell, 'v');
        if ($v) {
            $cell->removeChild($v);
        }
        $this->changed = true;
    }

    /**
     * Shared formulas without any cell reference (e.g. a constant formula filled down) become one
     * plain formula per cell, so that one of them can be replaced by a value safely.
     */
    public function unshareConstantFormulas(): void
    {
        $masters = [];
        foreach ($this->cells as $cell) {
            $f = $this->child($cell, 'f');
            if ($f && $f->getAttribute('t') === 'shared' && $f->hasAttribute('ref') && trim($f->textContent) !== '') {
                if (preg_match('/\$?[A-Z]{1,3}\$?\d+|[A-Za-z_][\w.]*\[/', $f->textContent) !== 1) {
                    $masters[$f->getAttribute('si')] = $f->textContent;
                }
            }
        }
        if ($masters === []) {
            return;
        }
        foreach ($this->cells as $cell) {
            $f = $this->child($cell, 'f');
            if ($f && $f->getAttribute('t') === 'shared' && isset($masters[$f->getAttribute('si')])) {
                $text = $masters[$f->getAttribute('si')];
                foreach (['t', 'si', 'ref'] as $attribute) {
                    $f->removeAttribute($attribute);
                }
                if (trim($f->textContent) === '') {
                    $f->appendChild($this->dom->createTextNode($text));
                }
                $this->changed = true;
            }
        }
    }

    /** Remove the sheet protection (the owner's rule: downloaded workbooks are not protected). */
    public function removeProtection(): bool
    {
        $removed = false;
        foreach (iterator_to_array($this->dom->getElementsByTagNameNS(XlsxPackage::NS, 'sheetProtection')) as $element) {
            $element->parentNode?->removeChild($element);
            $removed = true;
        }
        if ($removed) {
            $this->changed = true;
        }

        return $removed;
    }

    public function isProtected(): bool
    {
        return $this->dom->getElementsByTagNameNS(XlsxPackage::NS, 'sheetProtection')->length > 0;
    }

    /** @return list<DOMElement> the sheet's own data validations (not the x14 extension ones) */
    public function validations(): array
    {
        $out = [];
        foreach ($this->dom->getElementsByTagNameNS(XlsxPackage::NS, 'dataValidation') as $dv) {
            $out[] = $dv;
        }

        return $out;
    }

    public function removeValidation(DOMElement $dv): void
    {
        $parent = $dv->parentNode;
        if ($parent instanceof DOMElement) {
            $parent->removeChild($dv);
            $left = 0;
            foreach ($parent->childNodes as $child) {
                $left += $child instanceof DOMElement ? 1 : 0;
            }
            if ($left === 0) {
                $parent->parentNode?->removeChild($parent);
            } else {
                $parent->setAttribute('count', (string) $left);
            }
        }
        $this->changed = true;
    }

    // ---- Helpers -------------------------------------------------------------------------------

    public static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\x{9}\x{A}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
        // A literal "_x000A_" must stay text: Excel reads _xHHHH_ as an escape.
        $text = preg_replace('/_(x[0-9A-Fa-f]{4})_/', '_x005F_$1_', $text) ?? $text;

        return mb_substr($text, 0, self::MAX_TEXT);
    }

    /** "AB" => 28 */
    public static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $char) {
            $n = $n * 26 + (ord($char) - 64);
        }

        return $n;
    }

    /** 28 => "AB" */
    public static function columnLetters(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $index = intdiv($index - $mod, 26);
        }

        return $letters;
    }

    /** @return array{0: string, 1: int} "AB12" => ["AB", 12] */
    public static function split(string $ref): array
    {
        preg_match('/^\$?([A-Z]{1,3})\$?(\d+)$/i', $ref, $m);

        return [strtoupper($m[1] ?? 'A'), (int) ($m[2] ?? 1)];
    }

    /**
     * Every cell of a range ("A1:B3" or "A1").
     *
     * @return list<string>
     */
    public static function range(string $range): array
    {
        $parts = explode(':', $range);
        [$c1, $r1] = self::split($parts[0]);
        [$c2, $r2] = self::split($parts[1] ?? $parts[0]);
        $out = [];
        for ($r = min($r1, $r2); $r <= max($r1, $r2); $r++) {
            for ($c = min(self::columnIndex($c1), self::columnIndex($c2)); $c <= max(self::columnIndex($c1), self::columnIndex($c2)); $c++) {
                $out[] = self::columnLetters($c).$r;
            }
        }

        return $out;
    }

    private function child(DOMElement $cell, string $name): ?DOMElement
    {
        foreach ($cell->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child;
            }
        }

        return null;
    }

    private function clear(DOMElement $cell, bool $formulaToo): void
    {
        foreach (iterator_to_array($cell->childNodes) as $child) {
            if ($child instanceof DOMElement && ($child->localName !== 'f' || $formulaToo)) {
                $cell->removeChild($child);
            }
        }
        $cell->removeAttribute('t');
        $cell->removeAttribute('cm');
        $cell->removeAttribute('vm');
        if ($this->child($cell, 'f')) {
            $cell->setAttribute('t', 'str');
        }
    }

    private function create(string $ref): DOMElement
    {
        [$letters, $number] = self::split($ref);
        $row = $this->rows[$number] ?? $this->createRow($number);
        $cell = $this->dom->createElementNS(XlsxPackage::NS, 'c');
        $cell->setAttribute('r', $letters.$number);
        $style = $this->defaultStyle($row, $letters);
        if ($style !== null) {
            $cell->setAttribute('s', $style);
        }
        $index = self::columnIndex($letters);
        $before = null;
        foreach ($row->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'c'
                && self::columnIndex(self::split($child->getAttribute('r'))[0]) > $index) {
                $before = $child;
                break;
            }
        }
        $before ? $row->insertBefore($cell, $before) : $row->appendChild($cell);
        $this->cells[$letters.$number] = $cell;

        return $cell;
    }

    private function createRow(int $number): DOMElement
    {
        if (! $this->sheetData) {
            throw WorkbookException::notXlsx();
        }
        $row = $this->dom->createElementNS(XlsxPackage::NS, 'row');
        $row->setAttribute('r', (string) $number);
        $before = null;
        foreach ($this->rows as $r => $existing) {
            if ($r > $number) {
                $before = $existing;
                break;
            }
        }
        $before ? $this->sheetData->insertBefore($row, $before) : $this->sheetData->appendChild($row);
        $this->rows[$number] = $row;
        ksort($this->rows);

        return $row;
    }

    private function defaultStyle(DOMElement $row, string $letters): ?string
    {
        if ($row->getAttribute('customFormat') === '1' && $row->hasAttribute('s')) {
            return $row->getAttribute('s');
        }
        $index = self::columnIndex($letters);
        foreach ($this->dom->getElementsByTagNameNS(XlsxPackage::NS, 'col') as $col) {
            /** @var DOMElement $col */
            if ((int) $col->getAttribute('min') <= $index && (int) $col->getAttribute('max') >= $index && $col->hasAttribute('style')) {
                return $col->getAttribute('style');
            }
        }

        return null;
    }
}
