<?php

namespace App\Workbooks;

use App\Content\ContentPack;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads people's own cetome REDact workbooks back into REDact data, exactly (no AI), with the
 * same mapping that fills the templates (templates/mapping.json). A workbook is recognised by
 * its sheet names (any version close to the templates); protection does not prevent reading
 * (the XML is read directly), a workbook encrypted with a password to open is refused.
 *
 * The result is a plan (plain arrays, kept between the preview and the apply):
 *   files    name, workbook key and label of each file read
 *   fields   version.author_name, version.completion_date (Y-m-d), product.description
 *   scoping  question => bool
 *   items    "list|key" => list, label, code, name, description, attributes, sources
 *   links    relation, from item key, to item key
 *   walks    "requirement|item key" => requirement, item, node, verdict, justification
 *   einfo    item, code (null when the text names none), text, patterns of its column
 *   risks    one entry per threat
 *   matrix   level names per rank, level rank per impact and likelihood
 *   warnings sentences for people
 */
final class WorkbookImport
{
    /** @var array<string, mixed> */
    private array $plan;

    /** @var array<string, string> alias "list|code:XX" / "list|name:..." => item key */
    private array $aliases = [];

    /** @var array<string, list<string>> block id => item keys in row order */
    private array $rowsOf = [];

    /** @var array<string, array<string, array{yes: list<string>, seen: int}>> item key => option attribute => answers */
    private array $options = [];

    public function __construct(
        private readonly ContentPack $pack,
        private readonly Mapping $mapping,
    ) {
        $this->plan = self::emptyPlan();
    }

    /** @return array<string, mixed> */
    public static function emptyPlan(): array
    {
        return ['files' => [], 'fields' => [], 'scoping' => [], 'items' => [], 'links' => [], 'walks' => [],
            'einfo' => [], 'risks' => [], 'matrix' => [], 'warnings' => []];
    }

    /**
     * Which workbook of the mapping a package is (by its sheet names), or null.
     *
     * @return array<string, mixed>|null
     */
    public function detect(XlsxPackage $package): ?array
    {
        $sheets = array_map([Mapping::class, 'normalise'], $package->sheetNames());
        $best = null;
        $bestCount = 0;
        foreach ($this->mapping->workbooks() as $workbook) {
            $wanted = array_map(fn ($s) => Mapping::normalise((string) $s), (array) ($workbook['detect'] ?? []));
            if ($wanted !== [] && array_diff($wanted, $sheets) === [] && count($wanted) > $bestCount) {
                $best = $workbook;
                $bestCount = count($wanted);
            }
        }

        return $best;
    }

    /**
     * Read one file into the plan. Returns the workbook definition, or throws WorkbookException.
     *
     * @return array<string, mixed>
     */
    public function read(string $path, string $name): array
    {
        $package = XlsxPackage::open($path);
        try {
            $workbook = $this->detect($package);
            if (! $workbook) {
                throw WorkbookException::unknown();
            }
            $this->rowsOf = [];
            foreach ((array) ($workbook['blocks'] ?? []) as $block) {
                $sheet = $package->sheet($this->sheetName($package, (string) $block['sheet']));
                if (! $sheet) {
                    continue;
                }
                match ($block['type'] ?? null) {
                    'fields' => $this->fields($sheet, $block),
                    'scoping' => $this->scoping($sheet, $block),
                    'list' => $this->listBlock($sheet, $block),
                    'matrix' => $this->matrix($sheet, $block),
                    'risks' => $this->risks($sheet, $block),
                    'risk_matrix' => $this->riskMatrix($sheet, $block),
                    default => null,
                };
            }
            $this->plan['files'][] = ['name' => $name, 'workbook' => (string) $workbook['key']];
        } finally {
            $package->close();
        }
        $this->finishOptions();

        return $workbook;
    }

    /** @return array<string, mixed> */
    public function plan(): array
    {
        $this->plan['links'] = array_values(array_unique($this->plan['links'], SORT_REGULAR));

        return $this->plan;
    }

    private function sheetName(XlsxPackage $package, string $wanted): string
    {
        foreach ($package->sheetNames() as $name) {
            if (Mapping::normalise($name) === Mapping::normalise($wanted)) {
                return $name;
            }
        }

        return $wanted;
    }

    private function warn(string $text): void
    {
        if (count($this->plan['warnings']) < 200 && ! in_array($text, $this->plan['warnings'], true)) {
            $this->plan['warnings'][] = $text;
        }
    }

    // ---- Blocks --------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $block */
    private function fields(Sheet $sheet, array $block): void
    {
        foreach ((array) $block['cells'] as $ref => $spec) {
            $field = (string) ($spec['field'] ?? '');
            if (! empty($spec['export_only']) || $field === '') {
                continue;
            }
            if (isset($spec['rows'])) {
                $lines = array_filter(array_map(fn ($r) => $sheet->text($r), Sheet::range((string) $spec['rows'])), fn ($t) => $t !== '');
                $text = implode("\n", $lines);
            } else {
                $text = $sheet->text((string) $ref);
            }
            if ($text === '' || preg_match('/^\[.*\]$/s', $text) === 1) {
                continue; // empty, or the template's placeholder "[name of the author]"
            }
            if (($spec['format'] ?? null) === 'date') {
                $text = self::date($sheet->get((string) $ref));
                if ($text === null) {
                    continue;
                }
            }
            $this->plan['fields'][$field] ??= $text;
        }
    }

    /** @param  array<string, mixed>  $block */
    private function scoping(Sheet $sheet, array $block): void
    {
        foreach ((array) $block['cells'] as $ref => $question) {
            if (! is_string($question)) {
                continue; // computed by REDact (e.g. "EN 18031 fully implemented")
            }
            $value = $sheet->get((string) $ref);
            if (is_bool($value)) {
                $this->plan['scoping'][$question] = $value;
            } elseif (is_string($value) && in_array(Mapping::normalise($value), ['true', 'false', 'x', 'yes', 'no'], true)) {
                $this->plan['scoping'][$question] = in_array(Mapping::normalise($value), ['true', 'x', 'yes'], true);
            } elseif (is_float($value)) {
                $this->plan['scoping'][$question] = $value !== 0.0;
            }
        }
    }

    /** @param  array<string, mixed>  $block */
    private function listBlock(Sheet $sheet, array $block): void
    {
        $list = (string) $block['list'];
        if (! isset($this->pack->lists()[$list])) {
            return;
        }
        $first = is_numeric($block['first_row'] ?? null) ? (int) $block['first_row'] : $this->firstRowOf((string) $block['sheet']);
        $last = (int) $block['last_row'];
        $positional = isset($block['rows_of']);
        $sequence = $positional ? $this->positional($block) : [];
        $keys = [];

        for ($row = $first; $row <= $last; $row++) {
            if ($positional) {
                $key = $sequence[$row - $first] ?? null;
            } else {
                $label = $sheet->text($block['key'].$row);
                if ($label === '' || (isset($block['key_when_empty']) && $sheet->text($block['key_when_empty'].$row) !== '')) {
                    continue;
                }
                if ($this->ignored($label, (array) ($block['ignore_keys'] ?? []))) {
                    continue;
                }
                $key = $this->itemKey($list, $label, ! empty($block['creates']), $block['sheet'].'!'.$block['key'].$row);
            }
            if ($key === null) {
                continue;
            }
            $keys[] = $key;
            foreach ((array) ($block['columns'] ?? []) as $col => $spec) {
                if (! empty($spec['export_only'])) {
                    continue;
                }
                $this->column($sheet, (string) $col, $row, $spec, $key, $block);
            }
            foreach ((array) ($block['walks'] ?? []) as $walk) {
                $this->walk($sheet, $row, $walk, $key);
            }
        }
        $this->rowsOf[(string) $block['id']] = $keys;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $block
     */
    private function column(Sheet $sheet, string $col, int $row, array $spec, string $key, array $block): void
    {
        $text = $sheet->text($col.$row);
        $item = &$this->plan['items'][$key];
        if (isset($spec['value'])) {
            if ($text === '') {
                return;
            }
            match ($spec['value']) {
                'description' => $item['description'] = $text,
                'reference' => $item['attributes']['reference'] = $text,
                default => null,
            };

            return;
        }
        if (isset($spec['links'])) {
            if ($text === '' || is_array($spec['links'])) {
                return;
            }
            [$relation, $reverse] = Mapping::relation((string) $spec['links']);
            $definition = $this->pack->relations()[$relation] ?? null;
            if (! $definition) {
                return;
            }
            if (isset($spec['attr'])) {
                return;
            }
            $otherList = (string) ($reverse ? $definition['from'] : $definition['to']);
            foreach (self::labels($text) as $label) {
                $other = $this->itemKey($otherList, $label, false, $sheet->part.'!'.$col.$row);
                if ($other !== null) {
                    $this->plan['links'][] = ['relation' => $relation, 'from' => $reverse ? $other : $key, 'to' => $reverse ? $key : $other];
                }
            }

            return;
        }
        if (isset($spec['attr'])) {
            if ($text === '') {
                return;
            }
            if (! isset($spec['map'])) {
                $item['attributes'][$spec['attr']] = $text;

                return;
            }
            $value = $this->mapping->value($spec['map'], $text);
            if ($value === null) {
                $this->warn(__('import.warnings_list.unknown_value', ['value' => mb_substr($text, 0, 60), 'cell' => $block['sheet'].'!'.$col.$row]));

                return;
            }
            $item['attributes'][$spec['attr']] = $value;

            return;
        }
        if (isset($spec['option'])) {
            $value = $text === '' ? null : $this->mapping->value($spec['map'] ?? 'yes_no', $text);
            if (! is_bool($value)) {
                return; // N/A or empty: not answered
            }
            $option = (string) $spec['option'];
            $this->options[$key][$option] ??= ['yes' => [], 'seen' => 0];
            $this->options[$key][$option]['seen']++;
            if ($value) {
                $this->options[$key][$option]['yes'][] = (string) $spec['is'];
            }

            return;
        }
        if (isset($spec['einfo']) && $text !== '') {
            $this->einfo($text, $key, $spec);
        }
    }

    /** @param  array<string, mixed>  $spec */
    private function einfo(string $text, string $key, array $spec): void
    {
        $patterns = array_values((array) $spec['einfo']);
        if (isset($spec['fallback_links'])) {
            // The list REDact writes when no text was documented: links, not a text.
            [$relation, $reverse] = Mapping::relation((string) $spec['fallback_links']);
            $definition = $this->pack->relations()[$relation] ?? null;
            $otherList = $definition ? (string) ($reverse ? $definition['from'] : $definition['to']) : null;
            $others = $otherList ? $this->knownItems($otherList, $text) : null;
            if ($others !== null) {
                foreach ($others as $other) {
                    $this->plan['links'][] = ['relation' => $relation, 'from' => $reverse ? $other : $key, 'to' => $reverse ? $key : $other];
                }

                return;
            }
        }
        if (! empty($spec['plain'])) {
            $code = 'E.Info.'.str_replace('\\', '', (string) $patterns[0]);
            $this->addEinfo($key, $code, $text, $patterns);

            return;
        }
        $parts = preg_split('/^((?:[^\n]*? · )?E\.Info\.[A-Za-z0-9.\-]+):[ \t]*/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $current = null;
        $head = '';
        foreach ($parts as $part) {
            if (preg_match('/^(?:(.*?) · )?(E\.Info\.[A-Za-z0-9.\-]+)$/', $part, $m) === 1) {
                $current = [$m[1], $m[2]];

                continue;
            }
            if ($current === null) {
                $head .= $part;

                continue;
            }
            [$prefix, $code] = $current;
            $target = $key;
            if ($prefix !== '' && isset($spec['via'])) {
                [$relation, $reverse] = Mapping::relation((string) (is_array($spec['via']) ? end($spec['via']) : $spec['via']));
                $definition = $this->pack->relations()[$relation] ?? null;
                $otherList = $definition ? (string) ($reverse ? $definition['from'] : $definition['to']) : null;
                $target = $otherList ? ($this->itemKey($otherList, $prefix, false, '') ?? $key) : $key;
            }
            $this->addEinfo($target, $code, trim($part), $patterns);
            $current = null;
        }
        if (trim($head) !== '') {
            $this->addEinfo($key, null, trim($head), $patterns);
        }
    }

    /** @param  list<string>  $patterns */
    private function addEinfo(string $key, ?string $code, string $text, array $patterns): void
    {
        if ($text === '' || ($code !== null && ! isset($this->pack->einfo()[$code]))) {
            if ($code !== null) {
                $this->warn(__('import.warnings_list.unknown_einfo', ['code' => $code]));
            }

            return;
        }
        $this->plan['einfo'][$key.'|'.($code ?? 'column:'.md5(implode('|', $patterns)))] = [
            'item' => $key, 'code' => $code, 'text' => mb_substr($text, 0, 20000), 'patterns' => $patterns,
        ];
    }

    /** @param  array<string, mixed>  $walk */
    private function walk(Sheet $sheet, int $row, array $walk, string $key): void
    {
        $requirement = (string) $walk['requirement'];
        $entry = $this->plan['walks'][$requirement.'|'.$key] ?? ['requirement' => $requirement, 'item' => $key, 'node' => null, 'verdict' => null, 'justification' => null];
        if (isset($walk['node'])) {
            $text = $sheet->text($walk['node'].$row);
            if ($text !== '' && Mapping::normalise($text) !== Mapping::normalise($this->mapping->notWalked())) {
                $node = $this->mapping->parseNode($requirement, $text);
                $node === null
                    ? $this->warn(__('import.warnings_list.unknown_node', ['value' => mb_substr($text, 0, 40), 'cell' => $walk['node'].$row]))
                    : $entry['node'] = $node;
            }
        }
        if (isset($walk['verdict'])) {
            $text = $sheet->text($walk['verdict'].$row);
            $verdict = $text === '' ? null : $this->mapping->value('verdict', $text);
            if (is_string($verdict)) {
                $entry['verdict'] = $verdict;
            }
        }
        if (isset($walk['justification'])) {
            $text = $sheet->text($walk['justification'].$row);
            if ($text !== '') {
                $entry['justification'] = mb_substr($text, 0, 20000);
            }
        }
        if ($entry['node'] !== null || $entry['verdict'] !== null || $entry['justification'] !== null) {
            $this->plan['walks'][$requirement.'|'.$key] = $entry;
        }
    }

    /** @param  array<string, mixed>  $block */
    private function matrix(Sheet $sheet, array $block): void
    {
        $rows = $this->rowsOf[(string) $block['rows_of']] ?? [];
        $cols = $this->rowsOf[(string) $block['cols_of']] ?? [];
        [$relation] = Mapping::relation((string) $block['relation']);
        $rowsAreTo = ($block['rows_are'] ?? 'from') === 'to';
        $firstCol = Sheet::columnIndex((string) $block['first_col']);
        foreach ($rows as $r => $rowKey) {
            foreach ($cols as $c => $colKey) {
                $mark = $sheet->text(Sheet::columnLetters($firstCol + $c).((int) $block['first_row'] + $r));
                if ($mark !== '') {
                    $this->plan['links'][] = ['relation' => $relation, 'from' => $rowsAreTo ? $colKey : $rowKey, 'to' => $rowsAreTo ? $rowKey : $colKey];
                }
            }
        }
    }

    /** @param  array<string, mixed>  $block */
    private function risks(Sheet $sheet, array $block): void
    {
        $requirements = array_keys($this->pack->requirements());
        for ($row = (int) $block['first_row']; $row <= (int) $block['last_row']; $row++) {
            $risk = ['stride' => [], 'articles' => [], 'requirements' => []];
            foreach ((array) $block['columns'] as $col => $spec) {
                $value = $sheet->get($col.$row);
                $text = $sheet->text($col.$row);
                switch ($spec['risk'] ?? null) {
                    case 'stride':
                        if ($text !== '') {
                            $risk['stride'][] = (string) $spec['is'];
                        }
                        break;
                    case 'article':
                        if ($value === true || in_array(Mapping::normalise($text), ['true', 'x', 'yes'], true)) {
                            $risk['articles'][] = (string) $spec['is'];
                        }
                        break;
                    case 'impact':
                    case 'likelihood':
                        $risk[$spec['risk']] = is_numeric($text) && (int) $text >= 1 && (int) $text <= 5 ? (int) $text : null;
                        break;
                    case 'remediation':
                        foreach ($requirements as $code) {
                            if (preg_match('/(?<![\w-])'.preg_quote((string) $code, '/').'(?![\w-])/', $text) === 1) {
                                $risk['requirements'][] = (string) $code;
                            }
                        }
                        // REDact writes the requirements as a last "[ACM-1, SUM-1]" line
                        $risk['remediation'] = trim(preg_replace('/(^|\n)\[[A-Z0-9, \-]+\]$/', '', $text) ?? $text);
                        break;
                    default:
                        $risk[$spec['risk']] = $text;
                }
            }
            $threat = trim((string) ($risk['threat'] ?? ''));
            if ($threat === '' || $this->ignored($threat, (array) ($block['ignore_keys'] ?? []))) {
                continue;
            }
            $this->plan['risks'][Mapping::normalise($threat)] = $risk;
        }
    }

    /** @param  array<string, mixed>  $block */
    private function riskMatrix(Sheet $sheet, array $block): void
    {
        $levels = [];
        $rowOfRank = [];
        foreach (array_values((array) $block['levels']) as $i => $ref) {
            $name = $sheet->text((string) $ref);
            if ($name !== '') {
                $levels[$i + 1] = mb_substr($name, 0, 60);
                $rowOfRank[Sheet::split((string) $ref)[1]] = $i + 1;
            }
        }
        if (count($levels) !== count((array) $block['levels'])) {
            return;
        }
        [$originCol, $originRow] = Sheet::split((string) $block['origin']);
        $originIndex = Sheet::columnIndex($originCol);
        $cells = [];
        foreach (array_values((array) $block['impacts']) as $r => $impact) {
            foreach (array_values((array) $block['likelihoods']) as $c => $likelihood) {
                $ref = Sheet::columnLetters($originIndex + $c).($originRow + $r);
                $formula = $sheet->formula($ref);
                $rank = null;
                if ($formula !== null && preg_match('/^\$?[A-Z]+\$?(\d+)$/', trim(ltrim($formula, '=')), $m) === 1) {
                    $rank = $rowOfRank[(int) $m[1]] ?? null;
                } else {
                    $found = array_search(Mapping::normalise($sheet->text($ref)), array_map([Mapping::class, 'normalise'], $levels), true);
                    $rank = $found === false ? null : (int) $found;
                }
                if ($rank !== null) {
                    $cells[(int) $impact][(int) $likelihood] = $rank;
                }
            }
        }
        $this->plan['matrix'] = ['levels' => $levels, 'cells' => $cells];
    }

    // ---- Items ---------------------------------------------------------------------------------

    /**
     * The plan key of an item named in a workbook ("AS-01 · Wi-Fi password", "AS-01" or
     * "Wi-Fi password"), creating the plan entry when $creates. Null when unknown.
     */
    private function itemKey(string $list, string $label, bool $creates, string $source): ?string
    {
        [$code, $name] = $this->splitLabel($list, $label);
        if ($code !== null && $this->otherListPrefix($list, $code)) {
            return null; // "SW-02 · Bootloader" read on a row of interfaces: another block's row
        }
        $codeAlias = $code !== null ? $list.'|code:'.Mapping::normalise($code) : null;
        $nameAlias = $name !== '' ? $list.'|name:'.Mapping::normalise($name) : null;
        $key = ($codeAlias ? ($this->aliases[$codeAlias] ?? null) : null) ?? ($nameAlias ? ($this->aliases[$nameAlias] ?? null) : null);
        if ($key === null) {
            if (! $creates && ! $this->existsInVersion($list)) {
                return null;
            }
            $key = $codeAlias ?? $nameAlias ?? null;
            if ($key === null) {
                return null;
            }
            $this->plan['items'][$key] = [
                'list' => $list, 'label' => mb_substr($label, 0, 250), 'code' => $code, 'name' => mb_substr($name, 0, 200),
                'description' => null, 'attributes' => [], 'sources' => [], 'creates' => $creates,
            ];
        }
        if ($creates) {
            $this->plan['items'][$key]['creates'] = true;
        }
        if ($codeAlias) {
            $this->aliases[$codeAlias] = $key;
            $this->plan['items'][$key]['code'] ??= $code;
        }
        if ($nameAlias) {
            $this->aliases[$nameAlias] = $key;
            if (($this->plan['items'][$key]['name'] ?? '') === '') {
                $this->plan['items'][$key]['name'] = $name;
            }
        }
        if ($source !== '' && count($this->plan['items'][$key]['sources']) < 6) {
            $this->plan['items'][$key]['sources'][] = $source;
        }

        return $key;
    }

    /** Items not created by this block may still be matched to the version at preview time. */
    private function existsInVersion(string $list): bool
    {
        return true;
    }

    /** Does a code carry the prefix of another list ("SW-" read where interfaces are expected)? */
    private function otherListPrefix(string $list, string $code): bool
    {
        $own = (string) ($this->pack->lists()[$list]['code_prefix'] ?? '');
        if ($own !== '' && str_starts_with(strtoupper($code), strtoupper($own))) {
            return false;
        }
        foreach ($this->pack->lists() as $key => $definition) {
            $prefix = (string) ($definition['code_prefix'] ?? '');
            if ($key !== $list && $prefix !== '' && str_starts_with(strtoupper($code), strtoupper($prefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Labels of a list cell that all name items already read (e.g. the assets of a
     * cryptographic scheme), as plan keys; null when one of them is not an item.
     *
     * @return list<string>|null
     */
    private function knownItems(string $list, string $text): ?array
    {
        $keys = [];
        foreach (self::labels($text) as $label) {
            [$code, $name] = $this->splitLabel($list, $label);
            $key = ($code !== null ? ($this->aliases[$list.'|code:'.Mapping::normalise($code)] ?? null) : null)
                ?? ($name !== '' ? ($this->aliases[$list.'|name:'.Mapping::normalise($name)] ?? null) : null);
            if ($key === null) {
                return null;
            }
            $keys[] = $key;
        }

        return $keys === [] ? null : $keys;
    }

    /** @return array{0: string|null, 1: string} code, name */
    private function splitLabel(string $list, string $label): array
    {
        $prefix = (string) ($this->pack->lists()[$list]['code_prefix'] ?? '');
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
        // "AS-01 · Name" (REDact's labels), or "<list prefix>NN - Name"
        if (preg_match('/^([A-Z]{1,6}-[A-Z0-9._-]{1,20})\s*·\s*(.+)$/u', $label, $m) === 1
            || ($prefix !== '' && preg_match('/^('.preg_quote($prefix, '/').'[A-Z0-9._-]{1,20})\s*[:\-–]\s*(.+)$/iu', $label, $m) === 1)) {
            return [strtoupper($m[1]), trim($m[2])];
        }
        if ($prefix !== '' && preg_match('/^'.preg_quote($prefix, '/').'[A-Z0-9._-]{1,20}$/i', $label) === 1) {
            return [strtoupper($label), ''];
        }

        return [null, $label];
    }

    /**
     * Item keys of a positional block (rows tied to another block's rows), filtered by "where"
     * on the attributes read.
     *
     * @param  array<string, mixed>  $block
     * @return list<string>
     */
    private function positional(array $block): array
    {
        $keys = $this->rowsOf[(string) $block['rows_of']] ?? [];
        $where = (array) ($block['where'] ?? []);

        return array_values(array_filter($keys, function (string $key) use ($where) {
            foreach ($where as $attribute => $allowed) {
                if ($attribute === 'unlinked') {
                    continue;
                }
                $value = $this->plan['items'][$key]['attributes'][$attribute] ?? null;
                if (! in_array($value, (array) $allowed, true)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function firstRowOf(string $sheet): int
    {
        foreach ($this->mapping->workbooks() as $workbook) {
            foreach ((array) $workbook['blocks'] as $block) {
                if (($block['sheet'] ?? null) === $sheet && is_numeric($block['first_row'] ?? null)) {
                    return (int) $block['first_row'];
                }
            }
        }

        return 1;
    }

    private function finishOptions(): void
    {
        foreach ($this->options as $key => $attributes) {
            foreach ($attributes as $attribute => $answers) {
                if ($answers['yes'] !== []) {
                    $this->plan['items'][$key]['attributes'][$attribute] = array_values(array_unique($answers['yes']));
                } elseif ($answers['seen'] > 0) {
                    // Every listed category answered "No": none of them, "other" in REDact.
                    $this->plan['items'][$key]['attributes'][$attribute] = ['other'];
                }
            }
        }
        $this->options = [];
    }

    /** @param  list<string>  $patterns */
    private function ignored(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match('/'.str_replace('/', '\/', (string) $pattern).'/i', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> labels of a list cell: one per line, or comma-separated on one line */
    public static function labels(string $text): array
    {
        $lines = preg_split('/\s*\n\s*/u', trim($text)) ?: [];
        if (count($lines) === 1) {
            $lines = preg_split('/\s*[,;]\s*/u', $lines[0]) ?: [];
        }

        return array_values(array_filter(array_map(fn ($l) => trim(rtrim(trim($l), ',')), $lines), fn ($l) => $l !== '' && mb_strlen($l) <= 250));
    }

    private static function date(string|float|bool|null $value): ?string
    {
        if (is_float($value) && $value > 20000 && $value < 80000) {
            return Carbon::create(1899, 12, 30, 0, 0, 0, 'UTC')?->addDays((int) $value)->toDateString();
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse(trim($value))->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
