<?php

namespace App\Workbooks;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * One .xlsx package opened as a zip of XML parts, without rebuilding the workbook: formulas,
 * tables, validations, styles and drawings stay byte for byte unless a part is changed.
 *
 * Safe to open untrusted files: the zip is checked first (entry count, sizes, compression
 * ratio), files protected with a password to open (an encrypted OLE container, not a zip) are
 * refused, XML is parsed without network access and a part with a DOCTYPE is refused (no
 * external entities).
 */
final class XlsxPackage
{
    public const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public const MAX_ENTRIES = 3000;

    public const MAX_ENTRY_BYTES = 60 * 1024 * 1024;

    public const MAX_TOTAL_BYTES = 200 * 1024 * 1024;

    public const MAX_RATIO = 200;

    private ZipArchive $zip;

    /** @var array<string, DOMDocument> parts loaded */
    private array $doms = [];

    /** @var array<string, true> parts changed */
    private array $dirty = [];

    /** @var array<string, true> parts to remove */
    private array $removed = [];

    /** @var array<string, string>|null sheet name => part path */
    private ?array $sheets = null;

    /** @var list<string>|null */
    private ?array $sharedStrings = null;

    /** @var array<string, Sheet> */
    private array $sheetObjects = [];

    private function __construct(private readonly string $path) {}

    /**
     * Open a package. Throws WorkbookException (with a message for people) when the file is
     * not a usable .xlsx.
     */
    public static function open(string $path): self
    {
        $head = (string) @file_get_contents($path, false, null, 0, 8);
        if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            // An OLE container: an Excel file encrypted with a password to open, or an old .xls.
            throw WorkbookException::encrypted();
        }
        if (! str_starts_with($head, "PK\x03\x04")) {
            throw WorkbookException::notXlsx();
        }

        $package = new self($path);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw WorkbookException::notXlsx();
        }
        $package->zip = $zip;
        $package->checkLimits();
        if ($zip->locateName('xl/workbook.xml') === false || $zip->locateName('[Content_Types].xml') === false) {
            $zip->close();
            throw WorkbookException::notXlsx();
        }

        return $package;
    }

    /** Is this a .xlsx package (cheap check by content, for uploads)? */
    public static function looksLikeXlsx(string $path): bool
    {
        try {
            self::open($path)->close();

            return true;
        } catch (WorkbookException) {
            return false;
        }
    }

    public function close(): void
    {
        @$this->zip->close();
    }

    private function checkLimits(): void
    {
        $count = $this->zip->numFiles;
        if ($count < 1 || $count > self::MAX_ENTRIES) {
            throw WorkbookException::tooLarge();
        }
        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $this->zip->statIndex($i);
            if ($stat === false) {
                throw WorkbookException::notXlsx();
            }
            $name = (string) $stat['name'];
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                throw WorkbookException::notXlsx();
            }
            $size = (int) $stat['size'];
            $packed = max(1, (int) $stat['comp_size']);
            $total += $size;
            if ($size > self::MAX_ENTRY_BYTES || $total > self::MAX_TOTAL_BYTES || ($size > 1024 * 1024 && $size / $packed > self::MAX_RATIO)) {
                throw WorkbookException::tooLarge();
            }
        }
    }

    public function has(string $part): bool
    {
        return ! isset($this->removed[$part]) && (isset($this->doms[$part]) || $this->zip->locateName($part) !== false);
    }

    /** Raw content of a part (not parsed). */
    public function raw(string $part): ?string
    {
        $content = $this->zip->getFromName($part);

        return $content === false ? null : $content;
    }

    /** The parsed XML of a part (loaded once, safely). */
    public function dom(string $part): DOMDocument
    {
        if (isset($this->doms[$part])) {
            return $this->doms[$part];
        }
        $xml = $this->raw($part);
        if ($xml === null) {
            throw new RuntimeException("Missing part {$part}");
        }

        return $this->doms[$part] = self::parse($xml);
    }

    public static function parse(string $xml): DOMDocument
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            throw WorkbookException::notXlsx();
        }
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $ok) {
            throw WorkbookException::notXlsx();
        }

        return $dom;
    }

    /** Create or replace a part with a document. */
    public function putDom(string $part, DOMDocument $dom): void
    {
        $this->doms[$part] = $dom;
        $this->dirty[$part] = true;
        unset($this->removed[$part]);
    }

    public function touch(string $part): void
    {
        $this->dom($part);
        $this->dirty[$part] = true;
    }

    public function remove(string $part): void
    {
        $this->removed[$part] = true;
        unset($this->doms[$part], $this->dirty[$part]);
    }

    /** @return array<string, string> sheet name => part path, in workbook order */
    public function sheets(): array
    {
        if ($this->sheets !== null) {
            return $this->sheets;
        }
        $targets = [];
        $rels = $this->dom('xl/_rels/workbook.xml.rels');
        foreach ($rels->getElementsByTagNameNS(self::NS_PKG_REL, 'Relationship') as $rel) {
            /** @var DOMElement $rel */
            $target = $rel->getAttribute('Target');
            $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            $targets[$rel->getAttribute('Id')] = self::normalise($target);
        }
        $sheets = [];
        $workbook = $this->dom('xl/workbook.xml');
        foreach ($workbook->getElementsByTagNameNS(self::NS, 'sheet') as $sheet) {
            /** @var DOMElement $sheet */
            $id = $sheet->getAttributeNS(self::NS_REL, 'id');
            if (isset($targets[$id])) {
                $sheets[$sheet->getAttribute('name')] = $targets[$id];
            }
        }

        return $this->sheets = $sheets;
    }

    /** @return list<string> */
    public function sheetNames(): array
    {
        return array_keys($this->sheets());
    }

    public function sheet(string $name): ?Sheet
    {
        if (isset($this->sheetObjects[$name])) {
            return $this->sheetObjects[$name];
        }
        $part = $this->sheets()[$name] ?? null;
        if ($part === null || ! $this->has($part)) {
            return null;
        }

        return $this->sheetObjects[$name] = new Sheet($this, $part, $this->dom($part));
    }

    /** @return list<string> */
    public function sharedStrings(): array
    {
        if ($this->sharedStrings !== null) {
            return $this->sharedStrings;
        }
        $strings = [];
        if ($this->has('xl/sharedStrings.xml')) {
            $dom = $this->dom('xl/sharedStrings.xml');
            foreach ($dom->getElementsByTagNameNS(self::NS, 'si') as $si) {
                $strings[] = self::text($si);
            }
        }

        return $this->sharedStrings = $strings;
    }

    /** The text of an <si> or <is> element: its <t> runs, phonetic runs left out. */
    public static function text(DOMElement $element): string
    {
        $text = '';
        foreach ($element->getElementsByTagNameNS(self::NS, 't') as $t) {
            /** @var DOMElement $t */
            $parent = $t->parentNode;
            if ($parent instanceof DOMElement && $parent->localName === 'rPh') {
                continue;
            }
            $text .= $t->textContent;
        }

        return self::unescape($text);
    }

    /** Excel's _xHHHH_ escapes back to characters. */
    public static function unescape(string $text): string
    {
        return preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', fn ($m) => mb_chr((int) hexdec($m[1]), 'UTF-8') ?: '', $text) ?? $text;
    }

    public function xpath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('m', self::NS);

        return $xpath;
    }

    /** Write the package to $target: the original entries, with the changed parts replaced. */
    public function saveAs(string $target): void
    {
        if (! @copy($this->path, $target)) {
            throw new RuntimeException('Cannot write the workbook.');
        }
        foreach ($this->sheetObjects as $sheet) {
            if ($sheet->changed()) {
                $this->dirty[$sheet->part] = true;
            }
        }
        $out = new ZipArchive;
        if ($out->open($target) !== true) {
            throw new RuntimeException('Cannot write the workbook.');
        }
        foreach (array_keys($this->removed) as $part) {
            if ($out->locateName($part) !== false) {
                $out->deleteName($part);
            }
        }
        foreach (array_keys($this->dirty) as $part) {
            $xml = $this->doms[$part]->saveXML();
            if ($xml === false) {
                throw new RuntimeException("Cannot write {$part}.");
            }
            $out->addFromString($part, $xml);
            $out->setCompressionName($part, ZipArchive::CM_DEFLATE);
        }
        if (! $out->close()) {
            throw new RuntimeException('Cannot write the workbook.');
        }
    }

    private static function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '.' && $segment !== '') {
                $parts[] = $segment;
            }
        }

        return implode('/', $parts);
    }
}
