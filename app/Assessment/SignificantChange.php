<?php

namespace App\Assessment;

use App\Content\Text;
use App\Models\EinfoEntry;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\RiskEntry;
use App\Models\User;
use App\Support\Links;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Significant change between a version and the one it was made from (owner, 2026-10-30): a new
 * version must be reassessed when its risks are higher, when it has new risks or when its assets
 * change. REDact lists the signs, a person decides ("significant" or "not_significant") with a
 * justification, and a significant change puts the evaluations and E.Info entries the signs touch
 * back to "to re-check" (recheck): approval waits until each is confirmed or changed.
 *
 * Signs (signs()), compared with the earlier version:
 *  - risk_new: a risk the earlier version did not have;
 *  - risk_up: a risk rated higher, before or after its remediation (rank 1 is the highest level);
 *  - item_added / item_removed / item_changed: an object of a list the pack watches
 *    (lists.json "significant_change": true, assets and interfaces) added, removed, or with other
 *    attributes or members (a new name or description alone is not a change of the object);
 *  - scope_added: a part of EN 18031 now in scope.
 *
 * Copied risks and items keep the id they come from (source_id, VersionFactory); versions copied
 * before that are matched by code (items) and threat (risks). The decision keeps the fingerprint
 * of the signs it was taken on: other signs since, and it is out of date (state()).
 */
final class SignificantChange
{
    public const SIGNIFICANT = 'significant';

    public const NOT_SIGNIFICANT = 'not_significant';

    public const DECISIONS = [self::SIGNIFICANT, self::NOT_SIGNIFICANT];

    /** @var list<array<string, mixed>>|null */
    private ?array $signs = null;

    public function __construct(public readonly Assessment $assessment, public readonly ProductVersion $previous) {}

    /** Null for a version not made from an earlier one (or when that one is deleted). */
    public static function for(ProductVersion $version): ?self
    {
        return Assessment::for($version)->changes();
    }

    public function version(): ProductVersion
    {
        return $this->assessment->version;
    }

    /**
     * Every sign, risks first, then objects (in pack list order), then the parts in scope.
     *
     * @return list<array{kind: string, key: string, label: string, detail: string, href: string|null, risk: RiskEntry|null, item: InventoryItem|null, standard: string|null, raw: mixed}>
     */
    public function signs(): array
    {
        return $this->signs ??= [...$this->riskSigns(), ...$this->itemSigns(), ...$this->scopeSigns()];
    }

    /** Of the signs' values, never of their texts: another language is not another sign. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode(array_map(fn (array $s) => [$s['kind'], $s['key'], $s['raw']], $this->signs())) ?: '');
    }

    /**
     * "no_signs" (nothing to decide), "to_decide", "decided", or "outdated" (decided on other signs).
     */
    public function state(): string
    {
        $version = $this->version();
        if ($version->change_decision === null) {
            return $this->signs() === [] ? 'no_signs' : 'to_decide';
        }

        return $version->change_fingerprint === $this->fingerprint() ? 'decided' : 'outdated';
    }

    /** Nothing waits on this step: no sign, or a decision on the current signs. */
    public function settled(): bool
    {
        return in_array($this->state(), ['no_signs', 'decided'], true);
    }

    public function significant(): bool
    {
        return $this->version()->change_decision === self::SIGNIFICANT;
    }

    /**
     * Records the decision. Significant: the evaluations and E.Info entries the signs touch are
     * marked to re-check (those already confirmed since an earlier decision are marked again only
     * when a sign touches them; the marks the signs no longer touch go). Not significant: the marks
     * still open go.
     */
    public function decide(User $user, string $decision, string $justification): void
    {
        $version = $this->version();
        DB::transaction(function () use ($version, $user, $decision, $justification) {
            $version->forceFill([
                'change_decision' => $decision,
                'change_justification' => $justification,
                'change_decided_by' => $user->id,
                'change_decided_at' => now(),
                'change_fingerprint' => $this->fingerprint(),
            ])->save();

            if ($decision === self::SIGNIFICANT) {
                $affected = $this->affected();
                // Decided again on other signs: what they no longer touch is not to re-check any more.
                $version->evaluations()->where('recheck', true)->whereNotIn('id', $affected['evaluations'])->update(['recheck' => false]);
                $version->einfoEntries()->where('recheck', true)->whereNotIn('id', $affected['einfo'])->update(['recheck' => false]);
                Evaluation::query()->whereIn('id', $affected['evaluations'])->update(['recheck' => true]);
                EinfoEntry::query()->whereIn('id', $affected['einfo'])->update(['recheck' => true]);
            } else {
                $version->evaluations()->where('recheck', true)->update(['recheck' => false]);
                $version->einfoEntries()->where('recheck', true)->update(['recheck' => false]);
            }

            activity('version')->performedOn($version)->causedBy($user)
                ->withProperties(['decision' => $decision, 'previous' => $this->previous->label, 'signs' => count($this->signs()), 'justification' => $justification])
                ->log('change_decided');
        });
    }

    /**
     * The evaluations and E.Info entries the signs touch: those of an added or changed object, and
     * those of a requirement (or family) a new or higher risk names. Only work already done is
     * marked; an evaluation or entry never started is to do anyway.
     *
     * @return array{evaluations: list<string>, einfo: list<string>}
     */
    public function affected(): array
    {
        $items = [];
        $named = [];
        foreach ($this->signs() as $sign) {
            if (in_array($sign['kind'], ['item_added', 'item_changed'], true) && $sign['item']) {
                $items[] = $sign['item']->id;
            }
            if (in_array($sign['kind'], ['risk_new', 'risk_up'], true) && $sign['risk']) {
                array_push($named, ...array_map('strval', (array) $sign['risk']->requirements));
            }
        }
        $named = array_values(array_unique($named));

        $families = [];
        foreach ($this->assessment->pack->requirements() as $code => $requirement) {
            $families[(string) $code] = (string) ($requirement['family'] ?? '');
        }

        $evaluations = $this->version()->evaluations()
            ->where(fn ($q) => $q->whereNotNull('decision_node')->orWhereNotNull('verdict'))
            ->get(['id', 'requirement', 'inventory_item_id'])
            ->filter(fn (Evaluation $e) => in_array($e->inventory_item_id, $items, true)
                || in_array($e->requirement, $named, true)
                || (($families[$e->requirement] ?? '') !== '' && in_array($families[$e->requirement], $named, true)))
            ->pluck('id')->values()->all();

        $einfo = $items === [] ? [] : $this->version()->einfoEntries()
            ->whereIn('inventory_item_id', $items)->whereNotNull('text')->where('text', '!=', '')
            ->pluck('id')->values()->all();

        return ['evaluations' => $evaluations, 'einfo' => $einfo];
    }

    /**
     * What is still to re-check, in pack order: evaluations, then E.Info entries, each with its link.
     *
     * @return list<array{type: string, id: string, label: string, href: string}>
     */
    public function rechecks(): array
    {
        $version = $this->version();
        $rows = [];
        $evaluations = $version->evaluations()->where('recheck', true)->with('item')->get();
        foreach ($evaluations->sortBy(fn (Evaluation $e) => [$e->standard, $e->requirement, $e->item?->code]) as $evaluation) {
            $rows[] = [
                'type' => 'evaluation',
                'id' => $evaluation->id,
                'label' => $this->assessment->pack->standardName($evaluation->standard).' · '.$evaluation->requirement.' · '.($evaluation->item?->label() ?? __('evaluations.product')),
                'href' => Links::walk($version, $evaluation->standard, $evaluation->requirement, $evaluation->item),
            ];
        }
        $entries = $version->einfoEntries()->where('recheck', true)->with('item')->get();
        foreach ($entries->sortBy(fn (EinfoEntry $e) => [$e->einfo, $e->item?->code]) as $entry) {
            $standard = $this->einfoStandard($entry);
            $rows[] = [
                'type' => 'einfo',
                'id' => $entry->id,
                'label' => (str_starts_with($entry->einfo, 'E.Info') ? $entry->einfo : 'E.Info · '.$entry->einfo).' · '.($entry->item?->label() ?? __('evaluations.product')),
                'href' => $standard ? Links::einfo($version, $standard, $entry->item) : route('versions.show', $version),
            ];
        }

        return $rows;
    }

    /**
     * "Still valid": the evaluation or entry stays as it is, the mark goes. A walk shared across
     * parts is answered once (SharedWalks): its siblings still tied to it are confirmed with it.
     */
    public static function confirm(Evaluation|EinfoEntry $subject, User $user): void
    {
        $subjects = $subject instanceof Evaluation && $subject->shared_group && ! $subject->shared_untied
            ? Evaluation::query()->where('product_version_id', $subject->product_version_id)
                ->where('shared_group', $subject->shared_group)->where('shared_untied', false)->get()
            : collect([$subject]);
        foreach ($subjects as $one) {
            if (! $one->recheck) {
                continue;
            }
            $one->forceFill(['recheck' => false])->saveQuietly();
            activity($one instanceof Evaluation ? 'evaluation' : 'einfo')->performedOn($one)->causedBy($user)
                ->withProperties(['ref' => $one instanceof Evaluation
                    ? ['requirement' => $one->requirement, 'item' => $one->item?->label()]
                    : ['einfo' => $one->einfo, 'item' => $one->item?->label()]])
                ->log('recheck_confirmed');
        }
    }

    /** The first working standard that asks for this E.Info on its object, for the link. */
    private function einfoStandard(EinfoEntry $entry): ?string
    {
        foreach ($this->assessment->workingStandards() as $standard) {
            foreach ($this->assessment->requiredEinfo($standard) as $row) {
                if (($row['item']?->id) === $entry->inventory_item_id
                    && in_array($entry->einfo, [...$row['codes'], ...$row['conditional']], true)) {
                    return $standard;
                }
            }
        }

        return null;
    }

    /** @return list<array{kind: string, key: string, label: string, detail: string, href: string|null, risk: RiskEntry|null, item: InventoryItem|null, standard: string|null, raw: mixed}> */
    private function riskSigns(): array
    {
        $version = $this->version();
        $now = $version->riskEntries()->orderBy('position')->get();
        $before = $this->previous->riskEntries()->get()->keyBy('id');
        $rankNow = $this->ranks($version);
        $rankBefore = $this->ranks($this->previous);
        $levels = $version->riskLevels()->pluck('name', 'rank');

        $unmatched = $before->keys()->flip();
        $pairs = [];
        foreach ($now as $risk) {
            $old = $risk->source_id ? $before->get($risk->source_id) : null;
            $old ??= $before->first(fn (RiskEntry $r) => isset($unmatched[$r->id]) && trim((string) $r->threat) !== '' && trim((string) $r->threat) === trim((string) $risk->threat));
            if ($old) {
                unset($unmatched[$old->id]);
            }
            $pairs[] = [$risk, $old];
        }

        $level = fn (?int $rank) => $rank === null ? '—' : (string) ($levels[$rank] ?? $rank);
        $signs = [];
        foreach ($pairs as $n => [$risk, $old]) {
            $label = '#'.($n + 1).' · '.($risk->threat ?: __('changes.untitled_risk'));
            $href = route('versions.risks', $version).'#risk-'.$risk->id;
            // The requirements a risk names are part of its value: they decide what goes back to re-check (affected()).
            $named = self::normal(array_map('strval', (array) $risk->requirements));
            if (! $old) {
                $signs[] = $this->sign('risk_new', 'risk:'.$risk->id, $label, '', $href, risk: $risk, raw: $named);

                continue;
            }
            $inherent = [$rankBefore[$old->impact.'|'.$old->likelihood] ?? null, $rankNow[$risk->impact.'|'.$risk->likelihood] ?? null];
            $residual = [$old->residual_rank, $risk->residual_rank];
            $up = [];
            if ($inherent[0] !== null && $inherent[1] !== null && $inherent[1] < $inherent[0]) {
                $up[] = __('changes.detail.inherent', ['from' => $level($inherent[0]), 'to' => $level($inherent[1])]);
            }
            if ($residual[0] !== null && $residual[1] !== null && $residual[1] < $residual[0]) {
                $up[] = __('changes.detail.residual', ['from' => $level($residual[0]), 'to' => $level($residual[1])]);
            }
            if ($up !== []) {
                $signs[] = $this->sign('risk_up', 'risk:'.$risk->id, $label, implode(' · ', $up), $href, risk: $risk, raw: [$inherent, $residual, $named]);
            }
        }

        return $signs;
    }

    /** @return list<array{kind: string, key: string, label: string, detail: string, href: string|null, risk: RiskEntry|null, item: InventoryItem|null, standard: string|null, raw: mixed}> */
    private function itemSigns(): array
    {
        $version = $this->version();
        $signs = [];
        foreach ($this->watchedLists() as $key => $list) {
            $now = $version->inventoryItems()->where('list', $key)->orderBy('position')->get();
            $before = $this->previous->inventoryItems()->where('list', $key)->orderBy('position')->get()->keyBy('id');
            $unmatched = $before->keys()->flip();

            foreach ($now as $item) {
                $old = $item->source_id ? $before->get($item->source_id) : null;
                $old ??= $before->first(fn (InventoryItem $i) => isset($unmatched[$i->id]) && trim((string) $i->code) !== '' && trim((string) $i->code) === trim((string) $item->code));
                if ($old) {
                    unset($unmatched[$old->id]);
                }
                $label = Text::of($list['singular'] ?? $list['label']).' · '.$item->label();
                $href = Links::item($version, $item);
                if (! $old) {
                    $signs[] = $this->sign('item_added', 'item:'.$item->id, $label, '', $href, item: $item);

                    continue;
                }
                $changes = $this->itemChanges($list, $old, $item);
                if ($changes !== []) {
                    $raw = [array_map(fn (array $field) => self::normal(((array) ($item->getAttribute('attributes') ?? []))[$field['key'] ?? ''] ?? null), $list['fields'] ?? []), $item->memberNames()];
                    $signs[] = $this->sign('item_changed', 'item:'.$item->id, $label, implode(' · ', $changes), $href, item: $item, raw: $raw);
                }
            }
            foreach ($before as $id => $old) {
                if (isset($unmatched[$id])) {
                    $signs[] = $this->sign('item_removed', 'removed:'.$id, Text::of($list['singular'] ?? $list['label']).' · '.$old->label(), '', null);
                }
            }
        }

        return $signs;
    }

    /** @return list<array{kind: string, key: string, label: string, detail: string, href: string|null, risk: RiskEntry|null, item: InventoryItem|null, standard: string|null, raw: mixed}> */
    private function scopeSigns(): array
    {
        $before = Assessment::for($this->previous)->standards();
        $signs = [];
        foreach ($this->assessment->standards() as $standard) {
            if (! in_array($standard, $before, true)) {
                $signs[] = $this->sign('scope_added', 'scope:'.$standard, $this->assessment->pack->standardName($standard), '',
                    route('versions.scoping', $this->version()), standard: $standard);
            }
        }

        return $signs;
    }

    /**
     * The lists whose objects count (lists.json "significant_change": true), by key.
     *
     * @return array<string, array<string, mixed>>
     */
    private function watchedLists(): array
    {
        return array_filter($this->assessment->pack->lists(), fn (array $list) => (bool) ($list['significant_change'] ?? false));
    }

    /**
     * "Criticity: Sensitive → Confidential", one per attribute that changed, then the members.
     *
     * @param  array<string, mixed>  $list
     * @return list<string>
     */
    private function itemChanges(array $list, InventoryItem $old, InventoryItem $new): array
    {
        $changes = [];
        $before = (array) ($old->getAttribute('attributes') ?? []);
        $after = (array) ($new->getAttribute('attributes') ?? []);
        foreach ($list['fields'] ?? [] as $field) {
            $key = (string) ($field['key'] ?? '');
            $a = self::normal($before[$key] ?? null);
            $b = self::normal($after[$key] ?? null);
            if ($key === '' || $a === $b) {
                continue;
            }
            $changes[] = __('changes.detail.field', [
                'field' => Text::of($field['label'] ?? $key),
                'from' => $this->display($field, $a),
                'to' => $this->display($field, $b),
            ]);
        }

        $membersBefore = $old->memberNames();
        $membersAfter = $new->memberNames();
        if ($added = array_values(array_diff($membersAfter, $membersBefore))) {
            $changes[] = __('changes.detail.members_added', ['names' => implode(', ', $added)]);
        }
        if ($removed = array_values(array_diff($membersBefore, $membersAfter))) {
            $changes[] = __('changes.detail.members_removed', ['names' => implode(', ', $removed)]);
        }

        return $changes;
    }

    /** Empty values are one value; lists compare as sets. */
    private static function normal(mixed $value): mixed
    {
        if (is_array($value)) {
            $value = array_values(array_filter($value, fn ($v) => $v !== null && $v !== ''));
            sort($value);

            return $value === [] ? null : $value;
        }
        if (is_string($value)) {
            $value = trim($value);
        }

        return $value === '' || $value === null || $value === false ? null : $value;
    }

    /** @param  array<string, mixed>  $field */
    private function display(array $field, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if ($value === true || in_array($field['type'] ?? '', ['boolean'], true)) {
            return __('common.yes');
        }
        $options = [];
        foreach ($field['options'] ?? [] as $option) {
            $options[(string) ($option['value'] ?? '')] = Text::of($option['label'] ?? $option['value'] ?? '');
        }

        return implode(', ', array_map(fn ($v) => $options[(string) $v] ?? (string) $v, (array) $value));
    }

    /**
     * impact|likelihood => level rank, from the version's own matrix.
     *
     * @return array<string, int>
     */
    private function ranks(ProductVersion $version): array
    {
        return $version->riskMatrixCells()->get(['impact', 'likelihood', 'level_rank'])
            ->mapWithKeys(fn ($cell) => [$cell->impact.'|'.$cell->likelihood => (int) $cell->level_rank])->all();
    }

    /** @return array{kind: string, key: string, label: string, detail: string, href: string|null, risk: RiskEntry|null, item: InventoryItem|null, standard: string|null, raw: mixed} */
    private function sign(string $kind, string $key, string $label, string $detail, ?string $href,
        ?RiskEntry $risk = null, ?InventoryItem $item = null, ?string $standard = null, mixed $raw = null): array
    {
        return compact('kind', 'key', 'label', 'detail', 'href', 'risk', 'item', 'standard', 'raw');
    }

    /**
     * Signs by kind, for the summary ("2 new risks · 1 risk higher · 3 assets changed").
     *
     * @return Collection<string, int>
     */
    public function counts(): Collection
    {
        return collect($this->signs())->countBy('kind');
    }
}
