<?php

namespace App\Workbooks;

use App\Assessment\Assessment;
use App\Enums\Verdict;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\RiskEntry;
use Illuminate\Support\Carbon;

/**
 * What goes in each input cell of one cetome workbook for one version, read from the mapping:
 * the human fields only (scoping answers, inventory and links, walks, E.Info texts, risks), as
 * the app stores them. Produces the cell writes; TemplateFiller puts them in the template.
 *
 * Rows: a workbook prepares a fixed number of rows per list. Items beyond them are left out
 * (never a table extended) and listed in the notes, so that the formulas and tables of the
 * template stay exactly as the owner built them.
 */
final class WorkbookExport
{
    /** @var array<string, array<string, array{v?: mixed, f?: string, over?: bool}>> sheet => ref => write */
    private array $writes = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var array<string, list<InventoryItem>> block id => items in row order */
    private array $rowsOf = [];

    /** @var array<string, int> sheet => next free row after the last block on it */
    private array $nextRow = [];

    /** @var array<string, array<string, true>>|null "standard|requirement" => item id set */
    private ?array $targets = null;

    /** @var array<string, array<string, true>>|null item id => E.Info codes required or conditional */
    private ?array $einfoCodes = null;

    /** @var array<string, array<string, string>>|null item id => code => text */
    private ?array $texts = null;

    private int $autofillTexts = 0;

    public function __construct(
        private readonly Assessment $assessment,
        private readonly Mapping $mapping,
    ) {}

    /**
     * @param  array<string, mixed>  $workbook
     * @return array{writes: array<string, array<string, array{v?: mixed, f?: string, over?: bool}>>, notes: list<string>}
     */
    public function build(array $workbook): array
    {
        $this->writes = [];
        $this->notes = [];
        $this->rowsOf = [];
        $this->nextRow = [];
        $this->autofillTexts = 0;

        foreach ((array) ($workbook['blocks'] ?? []) as $block) {
            match ($block['type'] ?? null) {
                'fields' => $this->fields($block),
                'scoping' => $this->scoping($block),
                'list' => $this->listBlock($block),
                'matrix' => $this->matrix($block),
                'risks' => $this->risks($block),
                'risk_matrix' => $this->riskMatrix($block),
                default => null,
            };
        }
        if ($this->autofillTexts > 0) {
            $this->notes[] = __('workbooks.notes.autofill', ['count' => $this->autofillTexts]);
        }

        return ['writes' => $this->writes, 'notes' => $this->notes];
    }

    private function version(): ProductVersion
    {
        return $this->assessment->version;
    }

    private function put(string $sheet, string $ref, mixed $value, bool $over = false): void
    {
        $this->writes[$sheet][strtoupper($ref)] = ['v' => $value, 'over' => $over];
    }

    private function putFormula(string $sheet, string $ref, string $formula): void
    {
        $this->writes[$sheet][strtoupper($ref)] = ['f' => $formula];
    }

    // ---- Blocks --------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $block */
    private function fields(array $block): void
    {
        $version = $this->version();
        $product = $version->product;
        foreach ((array) $block['cells'] as $ref => $spec) {
            $value = match ($spec['field'] ?? null) {
                'version.author_name' => (string) $version->author_name,
                'product.title' => trim($product->name.' '.$version->label),
                'version.completion_date' => $version->completion_date ? self::excelDate($version->completion_date) : null,
                'product.description' => (string) $product->description,
                'version.intended_use' => (string) $version->intended_use,
                default => null,
            };
            $this->put($block['sheet'], (string) $ref, $value === '' ? null : $value);
        }
    }

    /** @param  array<string, mixed>  $block */
    private function scoping(array $block): void
    {
        $answers = $this->version()->scopingAnswers()->pluck('value', 'question')->all();
        // The Conformity step's own outcome, so the workbook never disagrees with the page.
        $conformity = $this->assessment->conformity();
        foreach ((array) $block['cells'] as $ref => $spec) {
            if (is_array($spec) && ($spec['computed'] ?? null) === 'en18031_full') {
                $value = $conformity->en18031Fully() === true;
            } elseif (is_array($spec) && ($spec['computed'] ?? null) === 'settled') {
                // A restriction the data settles (no secure update to protect): met over the
                // workbook's formula, which only knows the questions; otherwise the formula stays.
                $group = (string) ($spec['group'] ?? '');
                if (! in_array($group, $conformity->settledGroups(), true)) {
                    continue;
                }
                $this->put($block['sheet'], (string) $ref, 1, true);
                $this->notes[] = __('workbooks.notes.settled', ['sheet' => $block['sheet'], 'ref' => $ref,
                    'reason' => $conformity->suggestions()[$group]['because'][0]['text'] ?? $group]);

                continue;
            } else {
                $value = ($answers[(string) $spec] ?? null) === true;
            }
            $this->put($block['sheet'], (string) $ref, $value);
        }
    }

    /** @param  array<string, mixed>  $block */
    private function listBlock(array $block): void
    {
        $sheet = (string) $block['sheet'];
        $items = $this->items($block);
        $first = ($block['first_row'] ?? 1) === 'next' ? ($this->nextRow[$sheet] ?? 1) : (int) $block['first_row'];
        $last = (int) $block['last_row'];
        $capacity = max(0, $last - $first + 1);
        if (count($items) > $capacity) {
            $this->notes[] = __('workbooks.notes.overflow', [
                'sheet' => $sheet, 'count' => count($items) - $capacity, 'max' => $capacity,
                'names' => implode(', ', array_map(fn (InventoryItem $i) => $i->label(), array_slice($items, $capacity, 5))),
            ]);
            $items = array_slice($items, 0, $capacity);
        }
        $this->rowsOf[(string) $block['id']] = $items;
        $this->nextRow[$sheet] = $first + count($items);

        $columns = (array) ($block['columns'] ?? []);
        $assignment = $this->einfoAssignment($columns);
        for ($row = $first; $row <= $last; $row++) {
            $item = $items[$row - $first] ?? null;
            foreach ($columns as $col => $spec) {
                if (! empty($spec['import_only'])) {
                    continue;
                }
                $value = $item ? $this->columnValue($spec, $item, (string) $col, $assignment, (array) ($block['autofill'] ?? [])) : $this->emptyValue($spec);
                if ($value === null && ! empty($spec['overwrite_formula'])) {
                    continue; // leave the template's cell (formula) alone when there is nothing to write
                }
                $this->put($sheet, $col.$row, $value, ! empty($spec['overwrite_formula']));
            }
            foreach ((array) ($block['walks'] ?? []) as $walk) {
                $this->walk($sheet, $row, $walk, $item);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, string>  $assignment  E.Info code => column
     * @param  list<string>  $autofill
     */
    private function columnValue(array $spec, InventoryItem $item, string $col, array $assignment, array $autofill): mixed
    {
        if (isset($spec['value'])) {
            return match ($spec['value']) {
                'label' => $item->label(),
                'name' => (string) $item->name,
                'code' => (string) $item->code,
                'description' => self::nullIfEmpty((string) $item->description),
                'reference' => self::nullIfEmpty((string) ($item->attr('reference') ?: $item->label())),
                default => null,
            };
        }
        if (isset($spec['links'])) {
            $linked = $this->linkedPath($item, $spec['links']);
            if (isset($spec['attr'])) {
                $values = array_filter(array_map(fn (InventoryItem $i) => (string) $i->attr($spec['attr']), $linked));

                return self::nullIfEmpty(implode(', ', array_unique($values)));
            }

            return self::nullIfEmpty(implode(",\n", array_map(fn (InventoryItem $i) => $i->label(), $linked)));
        }
        if (isset($spec['attr'])) {
            $value = $item->attr($spec['attr']);
            if (! isset($spec['map'])) {
                return self::nullIfEmpty(is_scalar($value) ? (string) $value : '');
            }

            return $this->mapping->label($spec['map'], $value);
        }
        if (isset($spec['option'])) {
            $selected = $item->attr($spec['option']);
            if (! is_array($selected) || $selected === []) {
                return null;
            }

            return $this->mapping->label($spec['map'] ?? 'yes_no', in_array($spec['is'], $selected, true));
        }
        if (isset($spec['einfo'])) {
            return $this->einfoText($item, $col, $spec, $assignment, $autofill);
        }

        return null;
    }

    /** @param  array<string, mixed>  $spec */
    private function emptyValue(array $spec): mixed
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, string>  $assignment
     * @param  list<string>  $autofill
     */
    private function einfoText(InventoryItem $item, string $col, array $spec, array $assignment, array $autofill): ?string
    {
        $objects = [$item];
        if (isset($spec['via'])) {
            $objects = array_merge($objects, $this->linkedPath($item, $spec['via']));
        }
        $parts = [];
        foreach ($objects as $object) {
            $codes = $this->einfoCodes()[$object->id] ?? [];
            foreach ($this->textsOf($object) as $code => $text) {
                if (($assignment[$code] ?? null) !== $col || ! isset($codes[$code])) {
                    continue;
                }
                $label = $object->id === $item->id ? '' : $object->label().' · ';
                $parts[] = ! empty($spec['plain']) ? $text : $label.$this->mapping->einfoPrefix($code).$text;
            }
        }
        if ($objects === [$item]) {
            // Texts of items the workbook fills itself (Autofill columns) are not copied.
            foreach ($this->textsOf($item) as $code => $text) {
                if (! isset($assignment[$code]) && self::matchesAny($code, $autofill)) {
                    $this->autofillTexts++;
                }
            }
        }
        if ($parts === [] && isset($spec['fallback_links'])) {
            $linked = $this->linkedPath($item, $spec['fallback_links']);

            return self::nullIfEmpty(implode(",\n", array_map(fn (InventoryItem $i) => $i->label(), $linked)));
        }

        return self::nullIfEmpty(implode($this->mapping->einfoSeparator(), $parts));
    }

    /** @param  array<string, mixed>  $walk */
    private function walk(string $sheet, int $row, array $walk, ?InventoryItem $item): void
    {
        $requirement = (string) $walk['requirement'];
        [$found, $evaluation] = $item ? $this->evaluationFor($requirement, $item) : [false, null];
        if (isset($walk['node'])) {
            $node = match (true) {
                ! $item => null,
                ! $found => $this->mapping->notWalked(),
                $evaluation?->decision_node !== null => $this->mapping->node($requirement, (string) $evaluation->decision_node),
                default => null,
            };
            $this->put($sheet, $walk['node'].$row, $node);
        }
        if (isset($walk['verdict'])) {
            $verdict = null;
            if ($evaluation?->verdict === Verdict::Inconclusive) {
                $this->notes[] = __('workbooks.notes.inconclusive', ['requirement' => $requirement, 'item' => $item?->label()]);
            } elseif ($evaluation?->verdict) {
                $verdict = $this->mapping->label('verdict', $evaluation->verdict->value);
            }
            $this->put($sheet, $walk['verdict'].$row, $verdict);
        }
        if (isset($walk['justification'])) {
            $this->put($sheet, $walk['justification'].$row, self::nullIfEmpty(trim((string) $evaluation?->justification)));
        }
    }

    /** @param  array<string, mixed>  $block */
    private function matrix(array $block): void
    {
        $rows = $this->rowsOf[(string) $block['rows_of']] ?? [];
        $cols = $this->rowsOf[(string) $block['cols_of']] ?? [];
        $maxRows = (int) ($block['max_rows'] ?? 49);
        $maxCols = (int) ($block['max_cols'] ?? 49);
        [$relation] = Mapping::relation((string) $block['relation']);
        $rowsAreTo = ($block['rows_are'] ?? 'from') === 'to';
        $linked = [];
        foreach ($this->version()->inventoryLinks()->where('relation', $relation)->get(['from_item_id', 'to_item_id']) as $link) {
            $linked[$link->from_item_id.'|'.$link->to_item_id] = true;
        }
        $firstCol = Sheet::columnIndex((string) $block['first_col']);
        for ($r = 0; $r < $maxRows; $r++) {
            for ($c = 0; $c < $maxCols; $c++) {
                $rowItem = $rows[$r] ?? null;
                $colItem = $cols[$c] ?? null;
                $mark = null;
                if ($rowItem && $colItem) {
                    $key = $rowsAreTo ? $colItem->id.'|'.$rowItem->id : $rowItem->id.'|'.$colItem->id;
                    $mark = isset($linked[$key]) ? (string) ($block['mark'] ?? 'x') : null;
                }
                $this->put($block['sheet'], Sheet::columnLetters($firstCol + $c).((int) $block['first_row'] + $r), $mark);
            }
        }
    }

    /** @param  array<string, mixed>  $block */
    private function risks(array $block): void
    {
        $entries = $this->version()->riskEntries()->get()->all();
        $first = (int) $block['first_row'];
        $last = (int) $block['last_row'];
        $capacity = $last - $first + 1;
        if (count($entries) > $capacity) {
            $this->notes[] = __('workbooks.notes.overflow_risks', ['count' => count($entries) - $capacity, 'max' => $capacity]);
            $entries = array_slice($entries, 0, $capacity);
        }
        $levels = $this->version()->riskLevels()->pluck('name', 'rank')->all();
        for ($row = $first; $row <= $last; $row++) {
            /** @var RiskEntry|null $entry */
            $entry = $entries[$row - $first] ?? null;
            foreach ((array) $block['columns'] as $col => $spec) {
                $this->put($block['sheet'], $col.$row, $this->riskValue($spec, $entry, $levels));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int, string>  $levels
     */
    private function riskValue(array $spec, ?RiskEntry $entry, array $levels): mixed
    {
        $field = $spec['risk'] ?? null;
        if ($field === 'article') {
            return $entry !== null && in_array($spec['is'], (array) $entry->articles, true);
        }
        if (! $entry) {
            return null;
        }

        return match ($field) {
            'threat' => self::nullIfEmpty((string) $entry->threat),
            'description' => self::nullIfEmpty((string) $entry->description),
            'risk' => self::nullIfEmpty((string) $entry->risk),
            'stride' => in_array($spec['is'], (array) $entry->stride, true) ? (string) ($spec['mark'] ?? 'x') : null,
            'impact' => $entry->impact ?: null,
            'likelihood' => $entry->likelihood ?: null,
            'remediation' => self::nullIfEmpty(trim((string) $entry->remediation
                .((array) $entry->requirements !== [] ? "\n[".implode(', ', (array) $entry->requirements).']' : ''))),
            'implementation' => self::nullIfEmpty((string) $entry->implementation),
            'residual' => $entry->residual_rank ? ($levels[$entry->residual_rank] ?? null) : null,
            default => null,
        };
    }

    /** @param  array<string, mixed>  $block */
    private function riskMatrix(array $block): void
    {
        $levels = $this->version()->riskLevels()->get();
        $cells = $this->version()->riskMatrixCells()->get();
        $levelRefs = array_values((array) $block['levels']);
        $impacts = array_values((array) $block['impacts']);
        $likelihoods = array_values((array) $block['likelihoods']);
        $ranks = $levels->pluck('rank')->sort()->values()->all();
        if ($levels->isEmpty() || $ranks !== range(1, count($levelRefs))) {
            if ($levels->isNotEmpty()) {
                $this->notes[] = __('workbooks.notes.matrix_kept');
            }

            return;
        }
        foreach ($levels as $level) {
            $this->put($block['sheet'], $levelRefs[$level->rank - 1], (string) $level->name);
        }
        [$originCol, $originRow] = Sheet::split((string) $block['origin']);
        $originIndex = Sheet::columnIndex($originCol);
        foreach ($cells as $cell) {
            $r = array_search($cell->impact, $impacts, true);
            $c = array_search($cell->likelihood, $likelihoods, true);
            if ($r === false || $c === false || ! isset($levelRefs[$cell->level_rank - 1])) {
                continue;
            }
            [, $levelRow] = Sheet::split($levelRefs[$cell->level_rank - 1]);
            $formula = strtr((string) ($block['level_formula'] ?? 'J{row}'), ['{row}' => (string) $levelRow]);
            $this->putFormula($block['sheet'], Sheet::columnLetters($originIndex + (int) $c).($originRow + (int) $r), $formula);
        }
    }

    // ---- Data ----------------------------------------------------------------------------------

    /**
     * The items of a list block, in the app's order, filtered by the block's "where".
     *
     * @param  array<string, mixed>  $block
     * @return list<InventoryItem>
     */
    private function items(array $block): array
    {
        $items = isset($block['rows_of'])
            ? ($this->rowsOf[(string) $block['rows_of']] ?? [])
            : $this->assessment->context()->items((string) $block['list'])->values()->all();

        return array_values(array_filter($items, fn (InventoryItem $item) => $this->matches($item, (array) ($block['where'] ?? []))));
    }

    /** @param  array<string, mixed>  $where */
    public function matches(InventoryItem $item, array $where): bool
    {
        foreach ($where as $key => $allowed) {
            if ($key === 'unlinked') {
                if ($this->linkedPath($item, (string) $allowed) !== []) {
                    return false;
                }

                continue;
            }
            $value = $item->attr((string) $key);
            $value = $value === '' ? null : $value;
            if (! in_array($value, (array) $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  string|list<string>  $path  one relation spec or a chain of them
     * @return list<InventoryItem>
     */
    private function linkedPath(InventoryItem $item, string|array $path): array
    {
        $current = [$item];
        foreach ((array) $path as $spec) {
            [$relation, $reverse] = Mapping::relation((string) $spec);
            $next = [];
            foreach ($current as $from) {
                foreach ($this->assessment->context()->linked($from, $relation, $reverse) as $to) {
                    $next[$to->id] = $to;
                }
            }
            $current = array_values($next);
        }

        return $current;
    }

    /**
     * The walk of a requirement for a row's item: its own in the first standard in scope where it
     * is walked, else that of an item linked to it which is walked (e.g. the cryptographic scheme
     * of an asset for SCM-2…4). [walked anywhere, evaluation].
     *
     * @return array{0: bool, 1: Evaluation|null}
     */
    private function evaluationFor(string $requirement, InventoryItem $item): array
    {
        $standards = $this->assessment->standards();
        $walked = false;
        foreach ($standards as $standard) {
            if (isset($this->targets()[$standard.'|'.$requirement][$item->id])) {
                $walked = true;
                $evaluation = $this->assessment->evaluation($standard, $requirement, $item);
                if ($evaluation) {
                    return [true, $evaluation];
                }
            }
        }
        if ($walked) {
            return [true, null];
        }
        foreach ($this->assessment->pack->relations() as $key => $relation) {
            foreach ([false, true] as $reverse) {
                foreach ($this->assessment->context()->linked($item, (string) $key, $reverse) as $other) {
                    foreach ($standards as $standard) {
                        if (isset($this->targets()[$standard.'|'.$requirement][$other->id])) {
                            $evaluation = $this->assessment->evaluation($standard, $requirement, $other);
                            if ($evaluation) {
                                return [true, $evaluation];
                            }
                            $walked = true;
                        }
                    }
                }
            }
        }

        return [$walked, null];
    }

    /** @return array<string, array<string, true>> */
    private function targets(): array
    {
        if ($this->targets !== null) {
            return $this->targets;
        }
        $this->targets = [];
        foreach ($this->assessment->standards() as $standard) {
            foreach ($this->assessment->pack->requirementsFor($standard) as $code => $definition) {
                foreach ($this->assessment->targets($standard, (string) $code) as $target) {
                    if ($target) {
                        $this->targets[$standard.'|'.$code][$target->id] = true;
                    }
                }
            }
        }

        return $this->targets;
    }

    /** @return array<string, array<string, true>> */
    private function einfoCodes(): array
    {
        if ($this->einfoCodes !== null) {
            return $this->einfoCodes;
        }
        $this->einfoCodes = [];
        foreach ($this->assessment->standards() as $standard) {
            foreach ($this->assessment->requiredEinfo($standard) as $row) {
                if ($row['item']) {
                    foreach ([...$row['codes'], ...$row['conditional']] as $code) {
                        $this->einfoCodes[$row['item']->id][$code] = true;
                    }
                }
            }
        }

        return $this->einfoCodes;
    }

    /** @return array<string, string> code => text, in code order */
    private function textsOf(InventoryItem $item): array
    {
        if ($this->texts === null) {
            $this->texts = [];
            foreach ($this->version()->einfoEntries()->orderBy('einfo')->get(['einfo', 'inventory_item_id', 'text']) as $entry) {
                $text = trim((string) $entry->text);
                if ($text !== '' && $entry->inventory_item_id) {
                    $this->texts[$entry->inventory_item_id][$entry->einfo] = $text;
                }
            }
        }

        return $this->texts[$item->id] ?? [];
    }

    /**
     * Which E.Info text column of a block holds each code: the first whose patterns match.
     *
     * @param  array<string, array<string, mixed>>  $columns
     * @return array<string, string>
     */
    private function einfoAssignment(array $columns): array
    {
        $assignment = [];
        foreach (array_keys($this->assessment->pack->einfo()) as $code) {
            foreach ($columns as $col => $spec) {
                if (isset($spec['einfo']) && self::matchesAny((string) $code, (array) $spec['einfo'])) {
                    $assignment[(string) $code] = (string) $col;
                    break;
                }
            }
        }

        return $assignment;
    }

    /** @param  list<string>  $patterns */
    public static function matchesAny(string $code, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match(Mapping::einfoRegex((string) $pattern), $code) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function excelDate(\DateTimeInterface $date): int
    {
        return intdiv(Carbon::parse($date->format('Y-m-d'), 'UTC')->timestamp, 86400) + 25569;
    }

    private static function nullIfEmpty(string $text): ?string
    {
        return trim($text) === '' ? null : $text;
    }
}
