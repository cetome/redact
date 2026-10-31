<?php

namespace App\Assessment;

use App\Content\ContentPack;
use App\Content\Text;
use App\Enums\VersionStatus;
use App\Models\FieldOrigin;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates product versions: blank (with the pack's default risk matrix) or as
 * a copy of an earlier version. Like CRAted, a copy takes everything except
 * reviews, which belong to the version that was reviewed. Copied inventory items and risks keep
 * the id they come from (source_id) for the significant-change comparison (SignificantChange).
 */
class VersionFactory
{
    public function __construct(private readonly ContentPack $pack) {}

    public function create(Product $product, string $label, User $by, ?ProductVersion $from = null): ProductVersion
    {
        return DB::transaction(function () use ($product, $label, $by, $from) {
            $version = ProductVersion::create([
                'product_id' => $product->id,
                'label' => $label,
                'previous_version_id' => $from?->id,
                'status' => VersionStatus::Draft,
                'content_pack' => $this->pack->reference(),
                'intended_use' => $from?->intended_use,
                'conformity_route' => $from?->conformity_route,
                'units_external' => (bool) $from?->units_external,
                'notified_body' => $from?->notified_body,
                'notified_body_number' => $from?->notified_body_number,
                // The declaration's details carry over; the certificate belongs to the version it was issued for.
                'declaration' => $from?->declaration,
                'author_name' => $from->author_name ?? $by->name,
                'inventory_none' => $from?->inventory_none,
                'created_by' => $by->id,
            ]);

            $from ? $this->copy($from, $version) : $this->seedRiskMatrix($version);

            return $version;
        });
    }

    public function seedRiskMatrix(ProductVersion $version): void
    {
        $risk = $this->pack->risk();
        foreach ($risk['levels'] ?? [] as $level) {
            $version->riskLevels()->create([
                'rank' => $level['rank'],
                'name' => Text::of($level['name']),
                'color' => $level['color'],
            ]);
        }
        foreach ($risk['matrix'] ?? [] as $impact => $ranks) {
            foreach (array_values($ranks) as $i => $rank) {
                $version->riskMatrixCells()->create([
                    'impact' => (int) $impact,
                    'likelihood' => $i + 1,
                    'level_rank' => $rank,
                ]);
            }
        }
    }

    private function copy(ProductVersion $from, ProductVersion $to): void
    {
        $keyMap = [$from->id => $to->id];

        foreach ($from->identifiers as $identifier) {
            $to->identifiers()->create($identifier->only(['kind', 'value', 'notes', 'position']));
        }
        foreach ($from->scopingAnswers as $answer) {
            $to->scopingAnswers()->create($answer->only(['question', 'value', 'updated_by']));
        }
        foreach ($from->riskLevels as $level) {
            $to->riskLevels()->create($level->only(['rank', 'name', 'color']));
        }
        foreach ($from->riskMatrixCells as $cell) {
            $to->riskMatrixCells()->create($cell->only(['impact', 'likelihood', 'level_rank']));
        }
        foreach ($from->riskEntries as $entry) {
            $copy = $to->riskEntries()->create($entry->only([
                'position', 'threat', 'description', 'stride', 'risk', 'impact', 'likelihood',
                'articles', 'requirements', 'remediation', 'implementation', 'residual_rank', 'updated_by',
            ]) + ['source_id' => $entry->id]);
            $keyMap[$entry->id] = $copy->id;
        }

        $itemMap = [];
        foreach ($from->inventoryItems as $item) {
            $copy = $to->inventoryItems()->create($item->only(['list', 'code', 'name', 'description', 'attributes', 'members', 'position', 'updated_by']) + ['source_id' => $item->id]);
            $itemMap[$item->id] = $copy->id;
            $keyMap[$item->id] = $copy->id;
        }
        foreach ($from->inventoryLinks as $link) {
            $to->inventoryLinks()->create([
                'relation' => $link->relation,
                'from_item_id' => $itemMap[$link->from_item_id],
                'to_item_id' => $itemMap[$link->to_item_id],
                'attributes' => $link->attributes,
            ]);
        }
        // Shared walks (SharedWalks) stay shared, in groups of their own in the new version.
        $groupMap = [];
        foreach ($from->evaluations as $evaluation) {
            $copy = $to->evaluations()->create([
                ...$evaluation->only(['standard', 'requirement', 'decision_node', 'path', 'verdict', 'justification', 'shared_source', 'shared_untied', 'updated_by']),
                'inventory_item_id' => $evaluation->inventory_item_id ? $itemMap[$evaluation->inventory_item_id] : null,
                'shared_group' => $evaluation->shared_group ? ($groupMap[$evaluation->shared_group] ??= (string) Str::ulid()) : null,
            ]);
            $keyMap[$evaluation->id] = $copy->id;
        }
        foreach ($from->einfoEntries as $entry) {
            $copy = $to->einfoEntries()->create([
                ...$entry->only(['einfo', 'text', 'updated_by']),
                'inventory_item_id' => $entry->inventory_item_id ? $itemMap[$entry->inventory_item_id] : null,
            ]);
            $keyMap[$entry->id] = $copy->id;
        }
        foreach ($from->documents as $document) {
            $path = 'documents/'.$to->id.'/'.Str::ulid();
            Storage::disk('local')->copy($document->path, $path);
            $to->documents()->create([...$document->only(['uploaded_by', 'original_name', 'mime', 'size', 'sha256', 'extracted_text']), 'path' => $path]);
        }

        // Keep the AI provenance marks of copied texts (the mark disappears on edit anyway).
        FieldOrigin::whereIn('target_key', array_keys($keyMap))->get()->each(function (FieldOrigin $origin) use ($keyMap) {
            FieldOrigin::create([
                ...$origin->only(['target_type', 'field', 'origin', 'value_hash', 'accepted_by']),
                'target_key' => $keyMap[$origin->target_key],
            ]);
        });
    }
}
