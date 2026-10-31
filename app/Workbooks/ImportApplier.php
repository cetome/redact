<?php

namespace App\Workbooks;

use App\Ai\Provenance;
use App\Ai\Targets;
use App\Assessment\Assessment;
use App\Content\ContentPack;
use App\Content\Text;
use App\Enums\Origin;
use App\Enums\Verdict;
use App\Models\EinfoEntry;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\InventoryLink;
use App\Models\ProductVersion;
use App\Models\RiskEntry;
use App\Models\RiskLevel;
use App\Models\RiskMatrixCell;
use App\Models\User;
use App\Reports\ReportData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shows what an import plan (WorkbookImport) would create or change on a version, with the
 * conflicts against what is there, and applies the parts a person ticked. Values are validated
 * against the content pack (lists, fields, options, relations, requirements, tree nodes);
 * every text is marked "Imported from your workbook" (not AI).
 */
final class ImportApplier
{
    public const PARTS = ['fields', 'scoping', 'inventory', 'links', 'walks', 'einfo', 'risks', 'matrix'];

    private const MAX_TEXT = 10000;

    /** @var array<string, InventoryItem|null> plan item key => existing item */
    private array $matches = [];

    public function __construct(
        private readonly ProductVersion $version,
        private readonly ContentPack $pack,
    ) {}

    // ---- Preview -------------------------------------------------------------------------------

    /**
     * Rows per part: label, current, new, status (new | change | same | conflict | skip).
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, list<array{label: string, current: string, new: string, status: string}>>
     */
    public function preview(array $plan): array
    {
        $this->match($plan);
        $out = array_fill_keys(self::PARTS, []);

        $product = $this->version->product;
        $currentFields = [
            'version.author_name' => (string) $this->version->author_name,
            'version.completion_date' => (string) $this->version->completion_date?->toDateString(),
            'product.description' => (string) $product->description,
        ];
        foreach ($plan['fields'] as $field => $value) {
            if (! array_key_exists($field, $currentFields)) {
                continue;
            }
            $out['fields'][] = $this->row(__('import.fields.'.str_replace('.', '_', $field)), $currentFields[$field], (string) $value);
        }

        $answers = $this->version->scopingAnswers()->pluck('value', 'question')->all();
        $questions = $this->questions();
        foreach ($plan['scoping'] as $question => $value) {
            if (! isset($questions[$question])) {
                continue;
            }
            $current = array_key_exists($question, $answers) ? ($answers[$question] ? __('common.yes') : __('common.no')) : '';
            $out['scoping'][] = $this->row($questions[$question], $current, $value ? __('common.yes') : __('common.no'));
        }

        $lists = $this->pack->lists();
        foreach ($plan['items'] as $key => $item) {
            $definition = $lists[$item['list']] ?? null;
            if (! $definition) {
                continue;
            }
            $existing = $this->matches[$key] ?? null;
            $listLabel = Text::of($definition['label'] ?? $item['list']);
            $attributes = $this->cleanAttributes($item['list'], (array) $item['attributes']);
            $changes = [];
            foreach ($attributes as $field => $value) {
                $fieldDefinition = $this->field($item['list'], $field);
                $old = $existing?->attr($field);
                if ($old !== $value) {
                    $changes[] = Text::of($fieldDefinition['label'] ?? $field).': '.ReportData::answerText($fieldDefinition ?? [], $value);
                }
            }
            if (($item['description'] ?? null) !== null && trim((string) $item['description']) !== trim((string) $existing?->description)) {
                $changes[] = __('import.description_changed');
            }
            if (! $existing) {
                if (! empty($item['creates'])) {
                    $out['inventory'][] = ['label' => $listLabel.' · '.($item['label'] ?: $item['name']), 'current' => '', 'new' => implode(' · ', $changes), 'status' => 'new'];
                }

                continue;
            }
            $out['inventory'][] = [
                'label' => $listLabel.' · '.$existing->label(),
                'current' => '',
                'new' => implode(' · ', $changes),
                'status' => $changes === [] ? 'same' : 'change',
            ];
        }

        $linked = [];
        foreach ($this->version->inventoryLinks()->get(['relation', 'from_item_id', 'to_item_id']) as $link) {
            $linked[$link->relation.'|'.$link->from_item_id.'|'.$link->to_item_id] = true;
        }
        $relations = $this->pack->relations();
        foreach ($plan['links'] as $link) {
            if (! $this->known($link['from'], $plan) || ! $this->known($link['to'], $plan)) {
                continue;
            }
            $from = $this->matches[$link['from']] ?? null;
            $to = $this->matches[$link['to']] ?? null;
            $label = Text::of($relations[$link['relation']]['label'] ?? $link['relation']);
            $fromLabel = $from?->label() ?? ($plan['items'][$link['from']]['label'] ?? '');
            $toLabel = $to?->label() ?? ($plan['items'][$link['to']]['label'] ?? '');
            $exists = $from && $to && isset($linked[$link['relation'].'|'.$from->id.'|'.$to->id]);
            $out['links'][] = [
                'label' => $fromLabel.' → '.$toLabel,
                'current' => $exists ? $label : '',
                'new' => $label,
                'status' => $exists ? 'same' : 'new',
            ];
        }

        $assessment = Assessment::for($this->version);
        foreach ($plan['walks'] as $walk) {
            if (! $this->known($walk['item'], $plan)) {
                continue;
            }
            $item = $this->matches[$walk['item']] ?? null;
            $current = '';
            $walked = $item === null;
            if ($item) {
                foreach ($assessment->standards() as $standard) {
                    foreach ($assessment->targets($standard, $walk['requirement']) as $target) {
                        $walked = $walked || $target?->id === $item->id;
                    }
                }
                foreach ($assessment->standards() as $standard) {
                    $evaluation = $assessment->evaluation($standard, $walk['requirement'], $item);
                    if ($evaluation) {
                        $current = trim(($evaluation->nodeReference() ?? '').' '.($evaluation->verdict?->label() ?? ''));
                        break;
                    }
                }
            }
            $new = trim(($walk['node'] ? 'DT.'.$walk['requirement'].'.'.$walk['node'] : '').' '.($walk['verdict'] ? Verdict::from($walk['verdict'])->label() : ''));
            $label = $walk['requirement'].' · '.($item?->label() ?? ($plan['items'][$walk['item']]['label'] ?? ''));
            $row = $this->row($label, $current, $new === '' ? __('import.justification_only') : $new);
            if ($walk['justification'] ?? null) {
                $row['new'] .= ' · '.__('import.with_justification');
            }
            if (! $walked) {
                $row['status'] = 'skip';
                $row['new'] .= ' · '.__('import.not_walked');
            }
            $out['walks'][] = $row;
        }

        $texts = $this->texts();
        foreach ($plan['einfo'] as $entry) {
            if (! $this->known($entry['item'], $plan)) {
                continue;
            }
            $item = $this->matches[$entry['item']] ?? null;
            $code = $entry['code'] ?? $this->guessCode($entry, $item, $plan['items'][$entry['item']]['list'] ?? null);
            $label = ($code ?? __('import.einfo_unplaced')).' · '.($item?->label() ?? ($plan['items'][$entry['item']]['label'] ?? ''));
            $current = $item && $code ? ($texts[$code.'|'.$item->id] ?? '') : '';
            $row = $this->row($label, self::excerpt($current), self::excerpt((string) $entry['text']));
            if ($code === null) {
                $row['status'] = 'skip';
            } elseif ($current !== '' && trim($current) === trim((string) $entry['text'])) {
                $row['status'] = 'same';
            }
            $out['einfo'][] = $row;
        }

        $risks = $this->version->riskEntries()->get()->keyBy(fn (RiskEntry $r) => Mapping::normalise((string) $r->threat));
        foreach ($plan['risks'] as $key => $risk) {
            $existing = $risks->get($key);
            $summary = fn (array $stride, $impact, $likelihood) => trim(implode(' ', array_values(array_intersect(RiskEntry::STRIDE, $stride))).' · '.($impact ?? '–').'×'.($likelihood ?? '–'));
            $new = $summary((array) $risk['stride'], $risk['impact'] ?? null, $risk['likelihood'] ?? null);
            $current = $existing ? $summary((array) $existing->stride, $existing->impact, $existing->likelihood) : '';
            $sameTexts = $existing
                && Mapping::normalise((string) $existing->description) === Mapping::normalise((string) ($risk['description'] ?? ''))
                && Mapping::normalise((string) $existing->remediation) === Mapping::normalise((string) ($risk['remediation'] ?? ''))
                && Mapping::normalise((string) $existing->implementation) === Mapping::normalise((string) ($risk['implementation'] ?? ''));
            $out['risks'][] = [
                'label' => (string) $risk['threat'],
                'current' => $current,
                'new' => $new,
                'status' => ! $existing ? 'new' : ($current === $new && $sameTexts ? 'same' : 'change'),
            ];
        }

        if (($plan['matrix']['levels'] ?? []) !== []) {
            $current = $this->version->riskLevels()->pluck('name', 'rank')->all();
            foreach ($plan['matrix']['levels'] as $rank => $name) {
                $out['matrix'][] = $this->row(__('import.level', ['rank' => $rank]), (string) ($current[$rank] ?? ''), (string) $name);
            }
            $cells = [];
            foreach ($this->version->riskMatrixCells()->get() as $cell) {
                $cells[$cell->impact][$cell->likelihood] = $cell->level_rank;
            }
            $changed = 0;
            foreach ($plan['matrix']['cells'] as $impact => $row) {
                foreach ($row as $likelihood => $rank) {
                    $changed += ($cells[$impact][$likelihood] ?? null) !== $rank ? 1 : 0;
                }
            }
            $out['matrix'][] = ['label' => __('import.matrix_cells'), 'current' => '', 'new' => trans_choice('import.cells_changed', $changed, ['count' => $changed]), 'status' => $changed ? 'change' : 'same'];
        }

        return $out;
    }

    /**
     * Is a plan item an item of the version, or one the import creates?
     *
     * @param  array<string, mixed>  $plan
     */
    private function known(string $key, array $plan): bool
    {
        return ($this->matches[$key] ?? null) !== null || ! empty($plan['items'][$key]['creates']);
    }

    /** @return array{label: string, current: string, new: string, status: string} */
    private function row(string $label, string $current, string $new): array
    {
        $status = match (true) {
            trim($current) === '' => 'new',
            Mapping::normalise($current) === Mapping::normalise($new) => 'same',
            default => 'conflict',
        };

        return ['label' => $label, 'current' => $current, 'new' => $new, 'status' => $status];
    }

    private static function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157).'…' : $text;
    }

    // ---- Apply ---------------------------------------------------------------------------------

    /**
     * Apply the ticked parts. Returns counts per part.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $parts
     * @return array<string, int>
     */
    public function apply(array $plan, array $parts, User $by): array
    {
        $parts = array_values(array_intersect(self::PARTS, $parts));
        $counts = array_fill_keys($parts, 0);
        $counts['skipped'] = 0;
        $provenance = app(Provenance::class);

        DB::transaction(function () use ($plan, $parts, $by, &$counts, $provenance) {
            $this->match($plan);

            if (in_array('fields', $parts, true)) {
                $counts['fields'] = $this->applyFields($plan['fields'], $by, $provenance);
            }
            if (in_array('scoping', $parts, true)) {
                $questions = $this->questions();
                foreach ($plan['scoping'] as $question => $value) {
                    if (isset($questions[$question])) {
                        $this->version->scopingAnswers()->updateOrCreate(['question' => $question], ['value' => (bool) $value, 'updated_by' => $by->id]);
                        $counts['scoping']++;
                    }
                }
            }
            if (in_array('inventory', $parts, true)) {
                $counts['inventory'] = $this->applyItems($plan['items'], $by, $provenance);
            }
            if (in_array('links', $parts, true)) {
                $counts['links'] = $this->applyLinks($plan['links']);
            }
            if (in_array('walks', $parts, true)) {
                [$done, $skipped] = $this->applyWalks($plan['walks'], $by, $provenance);
                $counts['walks'] = $done;
                $counts['skipped'] += $skipped;
            }
            if (in_array('einfo', $parts, true)) {
                [$done, $skipped] = $this->applyEinfo($plan, $by, $provenance);
                $counts['einfo'] = $done;
                $counts['skipped'] += $skipped;
            }
            if (in_array('risks', $parts, true)) {
                $counts['risks'] = $this->applyRisks($plan['risks'], $by, $provenance);
            }
            if (in_array('matrix', $parts, true)) {
                $counts['matrix'] = $this->applyMatrix((array) $plan['matrix']);
            }
        });

        activity('import')
            ->causedBy($by)
            ->performedOn($this->version)
            ->withProperties(['files' => array_column($plan['files'], 'name'), 'workbooks' => array_column($plan['files'], 'workbook'), 'counts' => $counts])
            ->log('imported');

        return $counts;
    }

    /** @param  array<string, string>  $fields */
    private function applyFields(array $fields, User $by, Provenance $provenance): int
    {
        $n = 0;
        if (isset($fields['version.author_name'])) {
            $this->version->author_name = mb_substr((string) $fields['version.author_name'], 0, 120);
            $n++;
        }
        if (isset($fields['version.completion_date'])) {
            $this->version->completion_date = Carbon::parse((string) $fields['version.completion_date']);
            $n++;
        }
        $this->version->save();
        if (isset($fields['product.description'])) {
            $text = mb_substr((string) $fields['product.description'], 0, self::MAX_TEXT);
            $this->version->product->update(['description' => $text]);
            $provenance->mark('product', $this->version->product->id, 'description', Origin::Imported, $text, null, $by);
            $n++;
        }

        return $n;
    }

    /** @param  array<string, array<string, mixed>>  $items */
    private function applyItems(array $items, User $by, Provenance $provenance): int
    {
        $n = 0;
        $lists = $this->pack->lists();
        foreach ($items as $key => $item) {
            $definition = $lists[$item['list']] ?? null;
            if (! $definition) {
                continue;
            }
            $existing = $this->matches[$key] ?? null;
            $attributes = $this->cleanAttributes($item['list'], (array) $item['attributes']);
            $description = isset($item['description']) ? mb_substr(trim((string) $item['description']), 0, self::MAX_TEXT) : null;
            if (! $existing) {
                if (empty($item['creates'])) {
                    continue;
                }
                $code = $this->freeCode($definition, $item['code'] ?? null);
                $existing = $this->version->inventoryItems()->create([
                    'list' => $item['list'],
                    'code' => $code,
                    'name' => mb_substr((string) ($item['name'] ?: $item['label']), 0, 200),
                    'description' => $description ?: null,
                    'attributes' => $attributes,
                    'position' => (int) $this->version->inventoryItems()->where('list', $item['list'])->max('position') + 1,
                    'updated_by' => $by->id,
                ]);
                $this->matches[$key] = $existing;
            } else {
                $merged = array_merge((array) $existing->getAttribute('attributes'), $attributes);
                $existing->fill(['attributes' => $merged, 'updated_by' => $by->id]);
                if ($description !== null && $description !== '') {
                    $existing->description = $description;
                }
                $existing->save();
            }
            if ($description) {
                $provenance->mark('inventory_item', $existing->id, 'description', Origin::Imported, $description, null, $by);
            }
            $n++;
        }

        return $n;
    }

    /** @param  list<array{relation: string, from: string, to: string}>  $links */
    private function applyLinks(array $links): int
    {
        $n = 0;
        $relations = $this->pack->relations();
        foreach ($links as $link) {
            $definition = $relations[$link['relation']] ?? null;
            $from = $this->matches[$link['from']] ?? null;
            $to = $this->matches[$link['to']] ?? null;
            if (! $definition || ! $from || ! $to || $from->list !== $definition['from'] || $to->list !== $definition['to']) {
                continue;
            }
            $created = InventoryLink::query()->firstOrCreate([
                'product_version_id' => $this->version->id, 'relation' => $link['relation'],
                'from_item_id' => $from->id, 'to_item_id' => $to->id,
            ]);
            $n += $created->wasRecentlyCreated ? 1 : 0;
        }

        return $n;
    }

    /**
     * Walks: recorded in every standard in scope where REDact walks the requirement for the item,
     * as an expert entry (last node + verdict, no path), when the node exists in that tree.
     *
     * @param  array<string, array<string, mixed>>  $walks
     * @return array{0: int, 1: int}
     */
    private function applyWalks(array $walks, User $by, Provenance $provenance): array
    {
        $assessment = Assessment::for($this->version->fresh() ?? $this->version);
        $done = 0;
        $skipped = 0;
        foreach ($walks as $walk) {
            $item = $this->matches[$walk['item']] ?? null;
            if (! $item) {
                $skipped++;

                continue;
            }
            $recorded = false;
            foreach ($assessment->standards() as $standard) {
                $targets = array_filter($assessment->targets($standard, $walk['requirement']), fn ($t) => $t?->id === $item->id);
                $tree = $this->pack->tree($standard, $walk['requirement']);
                if ($targets === [] || ! $tree) {
                    continue;
                }
                $node = $walk['node'] && in_array($walk['node'], $tree->decisionNodes(), true) ? $walk['node'] : null;
                $verdict = $walk['verdict'] ? Verdict::tryFrom((string) $walk['verdict']) : null;
                if ($node && $verdict && ! $tree->allows($node, $verdict)) {
                    $verdict = null;
                }
                $evaluation = Evaluation::query()->firstOrNew([
                    'product_version_id' => $this->version->id, 'standard' => $standard,
                    'requirement' => $walk['requirement'], 'inventory_item_id' => $item->id,
                ]);
                if ($node && $evaluation->decision_node !== $node) {
                    $evaluation->decision_node = $node;
                    $evaluation->path = null;
                }
                if ($verdict) {
                    $evaluation->verdict = $verdict;
                }
                if ($walk['justification'] ?? null) {
                    $evaluation->justification = mb_substr((string) $walk['justification'], 0, self::MAX_TEXT);
                }
                if (! $evaluation->exists && ! $evaluation->decision_node && ! $evaluation->verdict && ! $evaluation->justification) {
                    continue;
                }
                $evaluation->updated_by = $by->id;
                $evaluation->save();
                if ($evaluation->justification && ($walk['justification'] ?? null)) {
                    $provenance->mark('evaluation', $evaluation->id, 'justification', Origin::Imported, (string) $evaluation->justification, null, $by);
                }
                $recorded = true;
            }
            $recorded ? $done++ : $skipped++;
        }

        return [$done, $skipped];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{0: int, 1: int}
     */
    private function applyEinfo(array $plan, User $by, Provenance $provenance): array
    {
        $done = 0;
        $skipped = 0;
        foreach ($plan['einfo'] as $entry) {
            $item = $this->matches[$entry['item']] ?? null;
            $code = $entry['code'] ?? $this->guessCode($entry, $item, $plan['items'][$entry['item']]['list'] ?? null);
            $catalogue = $code ? ($this->pack->einfo()[$code] ?? null) : null;
            if (! $item || ! $catalogue || ($catalogue['list'] ?? null) !== $item->list) {
                $skipped++;

                continue;
            }
            $text = mb_substr(trim((string) $entry['text']), 0, self::MAX_TEXT);
            EinfoEntry::query()->updateOrCreate(
                ['product_version_id' => $this->version->id, 'einfo' => $code, 'inventory_item_id' => $item->id],
                ['text' => $text, 'updated_by' => $by->id],
            );
            $provenance->mark('einfo_entry', Targets::einfoKey($code, $item->id), 'text', Origin::Imported, $text, null, $by);
            $done++;
        }

        return [$done, $skipped];
    }

    /** @param  array<string, array<string, mixed>>  $risks */
    private function applyRisks(array $risks, User $by, Provenance $provenance): int
    {
        $n = 0;
        $existing = $this->version->riskEntries()->get()->keyBy(fn (RiskEntry $r) => Mapping::normalise((string) $r->threat));
        $levels = [];
        foreach ($this->version->riskLevels()->get() as $level) {
            // A level still named as in the pack also reads in the pack's other languages.
            $packNames = array_map(fn (string $n) => Mapping::normalise($n), RiskLevel::packNamesOf($level->rank));
            if (in_array(Mapping::normalise((string) $level->getRawOriginal('name')), $packNames, true)) {
                foreach ($packNames as $name) {
                    $levels[$name] ??= $level->rank;
                }
            }
            $levels[Mapping::normalise((string) $level->name)] = $level->rank;
        }
        $articles = array_keys($this->pack->articles());
        $requirements = array_keys($this->pack->requirements());
        foreach ($risks as $key => $risk) {
            $data = [
                'threat' => mb_substr((string) $risk['threat'], 0, 200),
                'description' => self::nullable($risk['description'] ?? null),
                'risk' => self::nullable($risk['risk'] ?? null),
                'stride' => array_values(array_intersect(RiskEntry::STRIDE, (array) $risk['stride'])),
                'impact' => $risk['impact'] ?? null,
                'likelihood' => $risk['likelihood'] ?? null,
                'articles' => array_values(array_intersect($articles, (array) $risk['articles'])),
                'requirements' => array_values(array_intersect($requirements, (array) $risk['requirements'])),
                'remediation' => self::nullable($risk['remediation'] ?? null),
                'implementation' => self::nullable($risk['implementation'] ?? null),
                'residual_rank' => isset($risk['residual']) ? ($levels[Mapping::normalise((string) $risk['residual'])] ?? null) : null,
                'updated_by' => $by->id,
            ];
            $entry = $existing->get($key);
            if ($entry) {
                $entry->update(array_filter($data, fn ($v) => $v !== null && $v !== []));
            } else {
                $entry = $this->version->riskEntries()->create($data + ['position' => (int) $this->version->riskEntries()->max('position') + 1]);
            }
            foreach (['description', 'risk', 'remediation', 'implementation'] as $field) {
                if (($data[$field] ?? null) !== null) {
                    $provenance->mark('risk_entry', $entry->id, $field, Origin::Imported, (string) $data[$field], null, $by);
                }
            }
            $n++;
        }

        return $n;
    }

    /** @param  array<string, mixed>  $matrix */
    private function applyMatrix(array $matrix): int
    {
        $levels = $this->version->riskLevels()->get()->keyBy('rank');
        $n = 0;
        foreach ((array) ($matrix['levels'] ?? []) as $rank => $name) {
            $level = $levels->get((int) $rank);
            if ($level && $level->name !== $name) {
                $level->update(['name' => mb_substr((string) $name, 0, 60)]);
                $n++;
            }
        }
        foreach ((array) ($matrix['cells'] ?? []) as $impact => $row) {
            foreach ((array) $row as $likelihood => $rank) {
                if ($levels->has((int) $rank) && $impact >= 1 && $impact <= 5 && $likelihood >= 1 && $likelihood <= 5) {
                    RiskMatrixCell::query()->updateOrCreate(
                        ['product_version_id' => $this->version->id, 'impact' => (int) $impact, 'likelihood' => (int) $likelihood],
                        ['level_rank' => (int) $rank],
                    );
                    $n++;
                }
            }
        }

        return $n;
    }

    // ---- Matching ------------------------------------------------------------------------------

    /**
     * Match each plan item to an item of the version: by code, else by name (case and spaces
     * ignored), else by its full label.
     *
     * @param  array<string, mixed>  $plan
     */
    private function match(array $plan): void
    {
        $this->matches = [];
        $byCode = [];
        $byName = [];
        foreach ($this->version->inventoryItems()->get() as $item) {
            $byCode[$item->list.'|'.Mapping::normalise((string) $item->code)] = $item;
            $byName[$item->list.'|'.Mapping::normalise((string) $item->name)] ??= $item;
            $byName[$item->list.'|'.Mapping::normalise($item->label())] ??= $item;
        }
        // A vulnerability named by its identifier (e.g. a CVE) in the workbook
        foreach ($this->version->inventoryItems()->get() as $item) {
            $reference = trim((string) $item->attr('reference'));
            if ($reference !== '') {
                $byName[$item->list.'|'.Mapping::normalise($reference)] ??= $item;
            }
        }
        foreach ($plan['items'] as $key => $item) {
            $list = (string) $item['list'];
            $this->matches[$key] = ($item['code'] ? ($byCode[$list.'|'.Mapping::normalise((string) $item['code'])] ?? null) : null)
                ?? ($item['name'] !== '' ? ($byName[$list.'|'.Mapping::normalise((string) $item['name'])] ?? null) : null)
                ?? ($byName[$list.'|'.Mapping::normalise((string) $item['label'])] ?? null);
        }
    }

    /**
     * A text found without an E.Info identifier: the first item of its column the rules require
     * on the object, else the first of the catalogue for the list.
     *
     * @param  array<string, mixed>  $entry
     */
    private function guessCode(array $entry, ?InventoryItem $item, ?string $list): ?string
    {
        $candidates = [];
        foreach ($this->pack->einfo() as $code => $definition) {
            if (($definition['list'] ?? null) === ($item->list ?? $list) && WorkbookExport::matchesAny((string) $code, (array) $entry['patterns'])) {
                $candidates[] = (string) $code;
            }
        }
        if ($candidates === []) {
            return null;
        }
        if ($item) {
            $assessment = Assessment::for($this->version);
            foreach ($assessment->standards() as $standard) {
                foreach ($this->pack->rules()->requiredEinfo($standard, $item, $assessment->context()) as $code) {
                    if (in_array($code, $candidates, true)) {
                        return $code;
                    }
                }
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Attributes validated against the list's fields: known keys, allowed options, right types.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function cleanAttributes(string $list, array $attributes): array
    {
        $clean = [];
        foreach ($attributes as $key => $value) {
            $field = $this->field($list, (string) $key);
            if (! $field) {
                continue;
            }
            $options = array_map(fn ($o) => (string) $o['value'], (array) ($field['options'] ?? []));
            $value = match ($field['type'] ?? null) {
                'boolean' => is_bool($value) ? $value : null,
                'select' => in_array((string) $value, $options, true) ? (string) $value : null,
                'yesno' => in_array($value, ['yes', 'no', 'na'], true) ? $value : (is_bool($value) ? ($value ? 'yes' : 'no') : null),
                'multiselect' => array_values(array_intersect($options, (array) $value)) ?: null,
                'text', 'textarea' => is_scalar($value) ? mb_substr((string) $value, 0, self::MAX_TEXT) : null,
                default => null,
            };
            if ($value !== null) {
                $clean[(string) $key] = $value;
            }
        }

        return $clean;
    }

    /** @return array<string, mixed>|null */
    private function field(string $list, string $key): ?array
    {
        foreach ((array) ($this->pack->lists()[$list]['fields'] ?? []) as $field) {
            if (($field['key'] ?? null) === $key) {
                return $field;
            }
        }

        return null;
    }

    /** @return array<string, string> question key => label */
    private function questions(): array
    {
        $out = [];
        foreach ($this->pack->scoping()['sections'] ?? [] as $section) {
            foreach ($section['groups'] ?? [] as $group) {
                foreach ($group['questions'] ?? [] as $question) {
                    if (($question['type'] ?? 'boolean') === 'boolean') {
                        $out[(string) $question['key']] = Text::of($question['label'] ?? $question['key']);
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> "code|item" => text */
    private function texts(): array
    {
        $out = [];
        foreach ($this->version->einfoEntries()->get(['einfo', 'inventory_item_id', 'text']) as $entry) {
            $out[$entry->einfo.'|'.$entry->inventory_item_id] = (string) $entry->text;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $definition */
    private function freeCode(array $definition, ?string $wanted): string
    {
        $taken = $this->version->inventoryItems()->where('list', $definition['key'])->pluck('code')->map(fn ($c) => strtoupper((string) $c))->all();
        if ($wanted && ! in_array(strtoupper($wanted), $taken, true) && mb_strlen($wanted) <= 20) {
            return $wanted;
        }
        $prefix = (string) ($definition['code_prefix'] ?? '');
        $n = count($taken) + 1;
        do {
            $code = $prefix.str_pad((string) $n++, 2, '0', STR_PAD_LEFT);
        } while (in_array(strtoupper($code), $taken, true));

        return $code;
    }

    private static function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, self::MAX_TEXT);
    }
}
