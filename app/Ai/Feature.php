<?php

namespace App\Ai;

/**
 * What the AI can be asked to do, and what each call costs in "units"
 * (CRAted's model: a one-question call is 1 unit, a whole-product draft 10).
 */
enum Feature: string
{
    case ImproveText = 'improve_text';
    case Check = 'check';
    case SuggestPath = 'suggest_path';
    case DraftFromDocuments = 'draft_from_documents';
    case SuggestScoping = 'suggest_scope';

    /** Base units of one call. */
    public function units(): int
    {
        return match ($this) {
            self::ImproveText, self::Check, self::SuggestPath, self::SuggestScoping => 1,
            self::DraftFromDocuments => 10,
        };
    }

    /** Units of a draft: the base plus 1 per 2 MB of documents, so a manual is not priced like a leaflet. */
    public static function draftUnits(int $documentBytes): int
    {
        return self::DraftFromDocuments->units() + (int) ceil(max(0, $documentBytes) / (2 * 1024 * 1024));
    }

    /** Checks are judged at temperature 0; rewriting may vary a little. */
    public function temperature(): float
    {
        return $this === self::ImproveText ? 0.2 : 0.0;
    }

    /** "Uses 1 AI unit." The quiet line at the end of every AI tooltip. */
    public static function costHint(int $units): string
    {
        return trans_choice('ai.units.uses', $units, ['count' => $units]);
    }

    public function label(): string
    {
        return __('ai.features.'.$this->value);
    }
}
