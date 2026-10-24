<?php

namespace App\Reports;

use App\Content\Text;
use App\Workbooks\Sheet;
use App\Workbooks\XlsxPackage;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Fills one of cetome's workbook templates without rebuilding it: the template is copied and
 * only the XML of the input cells is changed (values written as inline strings, numbers or
 * booleans, styles kept). Formula cells are never written, except where a fix of the mapping
 * says so; tables, data validations, styles, drawings and every other part stay as they are.
 *
 * Also, on the copy:
 *  - the fixes of the mapping ("fixed if possible"): formula texts and dropdown lists, each
 *    applied only when the template holds exactly the expected text, else reported as left;
 *  - no protection (owner's rule): sheet and workbook protection elements are removed, so no
 *    password is ever needed, stored or written;
 *  - Excel recomputes every formula on opening (calcPr fullCalcOnLoad, calcChain dropped);
 *  - document properties stamp REDact's release, the content pack, the time and the product.
 */
final class TemplateFiller
{
    public const FMTID = '{D5CDD505-2E9C-101B-9397-08002B2CF9AE}';

    private const NS_CUSTOM = 'http://schemas.openxmlformats.org/officeDocument/2006/custom-properties';

    private const NS_VT = 'http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes';

    private const NS_CT = 'http://schemas.openxmlformats.org/package/2006/content-types';

    /** @var list<array{id: string, why: string}> */
    private array $applied = [];

    /** @var list<array{id: string, why: string}> */
    private array $skipped = [];

    /** @var list<string> */
    private array $refused = [];

    /**
     * @param  array<string, mixed>  $workbook  the mapping's workbook definition
     * @param  array<string, array<string, array{v?: mixed, f?: string, over?: bool}>>  $writes
     * @param  array<string, string>  $properties  document properties to stamp
     * @return array{applied: list<array{id: string, why: string}>, skipped: list<array{id: string, why: string}>, refused: list<string>}
     */
    public function fill(string $template, array $workbook, array $writes, array $properties, string $target): array
    {
        $this->applied = [];
        $this->skipped = [];
        $this->refused = [];

        $package = XlsxPackage::open($template);
        try {
            foreach ($writes as $sheetName => $cells) {
                $sheet = $package->sheet($sheetName);
                if (! $sheet) {
                    throw new RuntimeException("The template has no sheet {$sheetName}.");
                }
                $sheet->unshareConstantFormulas();
                foreach ($cells as $ref => $write) {
                    if (isset($write['f'])) {
                        $sheet->setFormula($ref, $write['f']);
                    } elseif (! $sheet->set($ref, $write['v'] ?? null, (bool) ($write['over'] ?? false))) {
                        $this->refused[] = $sheetName.'!'.$ref;
                    }
                }
            }

            foreach ((array) ($workbook['fixes'] ?? []) as $fix) {
                $ok = match ($fix['type'] ?? null) {
                    'formula' => $this->formulaFix($package, $fix),
                    'fill' => $this->fillFix($package, $fix),
                    'validation', 'validation_remove' => $this->validationFix($package, $fix),
                    default => false,
                };
                $entry = ['id' => (string) $fix['id'], 'why' => Text::of($fix['why'] ?? $fix['id'])];
                $ok ? $this->applied[] = $entry : $this->skipped[] = $entry;
            }

            $this->unprotect($package);
            $this->recalculateOnOpen($package);
            $this->stamp($package, $properties + ['REDact fixes' => implode(', ', array_column($this->applied, 'id'))]);
            $package->saveAs($target);
        } finally {
            $package->close();
        }

        return ['applied' => $this->applied, 'skipped' => $this->skipped, 'refused' => $this->refused];
    }

    // ---- Fixes ---------------------------------------------------------------------------------

    /**
     * Cells of the fix: "A1:A9" on the fix's sheet, or "Sheet name!A1:A9".
     *
     * @param  array<string, mixed>  $fix
     * @return list<array{0: string, 1: list<string>}> [sheet, cells] per range
     */
    private function ranges(array $fix): array
    {
        $out = [];
        foreach ((array) $fix['range'] as $range) {
            $sheet = (string) ($fix['sheet'] ?? '');
            if (str_contains((string) $range, '!')) {
                [$sheet, $range] = explode('!', (string) $range, 2);
            }
            $out[] = [$sheet, Sheet::range((string) $range)];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $fix */
    private function formulaFix(XlsxPackage $package, array $fix): bool
    {
        $pairs = isset($fix['replace_all']) ? (array) $fix['replace_all'] : [[(string) $fix['find'], (string) $fix['replace']]];
        $perColumn = ! empty($fix['per_column']);
        $planned = [];
        $found = [];
        foreach ($this->ranges($fix) as [$sheetName, $cells]) {
            $sheet = $package->sheet($sheetName);
            if (! $sheet) {
                return false;
            }
            foreach ($pairs as $n => [$find, $replace]) {
                $hits = 0;
                foreach ($cells as $ref) {
                    $formula = $planned[$sheetName][$ref] ?? $sheet->formula($ref);
                    if ($formula === null || $formula === '') {
                        return false; // no formula, or a shared one: the template is not the expected one
                    }
                    if (str_contains($formula, $find)) {
                        $hits++;
                        $planned[$sheetName][$ref] = str_replace($find, $replace, $formula);
                    }
                }
                if ($hits !== 0 && $hits !== count($cells)) {
                    return false; // some rows differ: leave the whole fix
                }
                if ($hits === 0 && ! $perColumn) {
                    return false;
                }
                $found[$n] = ($found[$n] ?? false) || $hits > 0;
            }
        }
        // Every replacement must have been found somewhere: else the template is another one.
        if ($planned === [] || in_array(false, $found, true)) {
            return false;
        }
        foreach ($planned as $sheetName => $cells) {
            $sheet = $package->sheet($sheetName);
            foreach ($cells as $ref => $formula) {
                $sheet?->replaceFormula($ref, $formula);
            }
        }

        return true;
    }

    /** Write a formula of the other rows where the template forgot it (only into empty cells). */
    private function fillFix(XlsxPackage $package, array $fix): bool
    {
        $sheet = $package->sheet((string) $fix['sheet']);
        if (! $sheet) {
            return false;
        }
        $template = (string) $fix['formula'];
        [, $checkRow] = Sheet::split((string) $fix['check']);
        if (self::same($sheet->formula((string) $fix['check']), strtr($template, ['{row}' => (string) $checkRow])) === false) {
            return false;
        }
        $cells = Sheet::range((string) $fix['range']);
        foreach ($cells as $ref) {
            $existing = $sheet->formula($ref);
            [, $row] = Sheet::split($ref);
            if ($existing !== null && ! self::same($existing, strtr($template, ['{row}' => (string) $row]))) {
                return false;
            }
        }
        foreach ($cells as $ref) {
            if ($sheet->formula($ref) === null) {
                [, $row] = Sheet::split($ref);
                $sheet->setFormula($ref, strtr($template, ['{row}' => (string) $row]));
            }
        }

        return true;
    }

    private static function same(?string $a, string $b): bool
    {
        $norm = fn (string $s) => preg_replace('/\s+/', '', $s) ?? $s;

        return $a !== null && $norm($a) === $norm($b);
    }

    /** @param  array<string, mixed>  $fix */
    private function validationFix(XlsxPackage $package, array $fix): bool
    {
        $sheet = $package->sheet((string) $fix['sheet']);
        if (! $sheet) {
            return false;
        }
        $wanted = self::sqref((string) $fix['sqref']);
        foreach ($sheet->validations() as $dv) {
            if (self::sqref($dv->getAttribute('sqref')) !== $wanted) {
                continue;
            }
            $formula = null;
            foreach ($dv->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'formula1') {
                    $formula = $child;
                }
            }
            if (! $formula || $formula->textContent !== (string) $fix['find']) {
                continue;
            }
            if ($fix['type'] === 'validation_remove') {
                $sheet->removeValidation($dv);
            } else {
                if (isset($fix['new_sqref'])) {
                    // The dropdown also covered another column: kept on its own cells only
                    $dv->setAttribute('sqref', (string) $fix['new_sqref']);
                }
                if (isset($fix['replace'])) {
                    while ($formula->firstChild) {
                        $formula->removeChild($formula->firstChild);
                    }
                    $formula->appendChild($formula->ownerDocument->createTextNode((string) $fix['replace']));
                }
                $sheet->markChanged();
            }

            return true;
        }

        return false;
    }

    private static function sqref(string $sqref): string
    {
        $parts = preg_split('/\s+/', strtoupper(trim($sqref))) ?: [];
        sort($parts);

        return implode(' ', $parts);
    }

    // ---- Workbook ------------------------------------------------------------------------------

    /** No sheet or workbook protection in the delivered copy (owner's rule). */
    private function unprotect(XlsxPackage $package): void
    {
        foreach ($package->sheetNames() as $name) {
            $package->sheet($name)?->removeProtection();
        }
        $workbook = $package->dom('xl/workbook.xml');
        $changed = false;
        foreach (['workbookProtection', 'fileSharing'] as $tag) {
            foreach (iterator_to_array($workbook->getElementsByTagNameNS(XlsxPackage::NS, $tag)) as $element) {
                $element->parentNode?->removeChild($element);
                $changed = true;
            }
        }
        if ($changed) {
            $package->touch('xl/workbook.xml');
        }
    }

    private function recalculateOnOpen(XlsxPackage $package): void
    {
        $workbook = $package->dom('xl/workbook.xml');
        $root = $workbook->documentElement;
        $calcPr = $workbook->getElementsByTagNameNS(XlsxPackage::NS, 'calcPr')->item(0);
        if (! $calcPr instanceof DOMElement && $root) {
            $calcPr = $workbook->createElementNS(XlsxPackage::NS, 'calcPr');
            $before = null;
            foreach ($root->childNodes as $child) {
                if ($child instanceof DOMElement && in_array($child->localName, ['oleSize', 'customWorkbookViews', 'pivotCaches', 'smartTagPr', 'smartTagTypes', 'webPublishing', 'fileRecoveryPr', 'webPublishObjects', 'extLst'], true)) {
                    $before = $child;
                    break;
                }
            }
            $before ? $root->insertBefore($calcPr, $before) : $root->appendChild($calcPr);
        }
        if ($calcPr instanceof DOMElement) {
            $calcPr->setAttribute('fullCalcOnLoad', '1');
        }
        $package->touch('xl/workbook.xml');

        // The calculation chain lists the formula cells of the template: Excel rebuilds it.
        if ($package->has('xl/calcChain.xml')) {
            $package->remove('xl/calcChain.xml');
            $rels = $package->dom('xl/_rels/workbook.xml.rels');
            foreach (iterator_to_array($rels->getElementsByTagNameNS(XlsxPackage::NS_PKG_REL, 'Relationship')) as $rel) {
                /** @var DOMElement $rel */
                if (str_ends_with($rel->getAttribute('Target'), 'calcChain.xml')) {
                    $rel->parentNode?->removeChild($rel);
                }
            }
            $package->touch('xl/_rels/workbook.xml.rels');
            $types = $package->dom('[Content_Types].xml');
            foreach (iterator_to_array($types->getElementsByTagNameNS(self::NS_CT, 'Override')) as $override) {
                /** @var DOMElement $override */
                if ($override->getAttribute('PartName') === '/xl/calcChain.xml') {
                    $override->parentNode?->removeChild($override);
                }
            }
            $package->touch('[Content_Types].xml');
        }
    }

    /** @param  array<string, string>  $properties */
    private function stamp(XlsxPackage $package, array $properties): void
    {
        $part = 'docProps/custom.xml';
        if ($package->has($part)) {
            $dom = $package->dom($part);
        } else {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->xmlStandalone = true;
            $root = $dom->createElementNS(self::NS_CUSTOM, 'Properties');
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:vt', self::NS_VT);
            $dom->appendChild($root);
            $this->registerCustomPart($package);
        }
        $root = $dom->documentElement;
        if (! $root) {
            return;
        }
        $pid = 1;
        $existing = [];
        foreach ($root->getElementsByTagNameNS(self::NS_CUSTOM, 'property') as $property) {
            /** @var DOMElement $property */
            $pid = max($pid, (int) $property->getAttribute('pid'));
            $existing[$property->getAttribute('name')] = $property;
        }
        foreach ($properties as $name => $value) {
            $value = mb_substr(Sheet::cleanText($value), 0, 255);
            if (isset($existing[$name])) {
                $root->removeChild($existing[$name]);
            }
            $property = $dom->createElementNS(self::NS_CUSTOM, 'property');
            $property->setAttribute('fmtid', self::FMTID);
            $property->setAttribute('pid', (string) ++$pid);
            $property->setAttribute('name', $name);
            $text = $dom->createElementNS(self::NS_VT, 'vt:lpwstr');
            $text->appendChild($dom->createTextNode($value));
            $property->appendChild($text);
            $root->appendChild($property);
        }
        $package->putDom($part, $dom);
    }

    private function registerCustomPart(XlsxPackage $package): void
    {
        $types = $package->dom('[Content_Types].xml');
        $override = $types->createElementNS(self::NS_CT, 'Override');
        $override->setAttribute('PartName', '/docProps/custom.xml');
        $override->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.custom-properties+xml');
        $types->documentElement?->appendChild($override);
        $package->touch('[Content_Types].xml');

        $rels = $package->dom('_rels/.rels');
        $ids = [];
        foreach ($rels->getElementsByTagNameNS(XlsxPackage::NS_PKG_REL, 'Relationship') as $rel) {
            /** @var DOMElement $rel */
            $ids[] = $rel->getAttribute('Id');
        }
        $n = 1;
        while (in_array('rId'.$n, $ids, true)) {
            $n++;
        }
        $rel = $rels->createElementNS(XlsxPackage::NS_PKG_REL, 'Relationship');
        $rel->setAttribute('Id', 'rId'.$n);
        $rel->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/custom-properties');
        $rel->setAttribute('Target', 'docProps/custom.xml');
        $rels->documentElement?->appendChild($rel);
        $package->touch('_rels/.rels');
    }
}
