<?php

namespace App\Enums;

/**
 * Where a piece of content comes from. The four AI/human marks and their wording are
 * shared with CRAted: a star always means the AI, a person icon a person. "From inventory"
 * (boxes icon) marks an E.Info text the content pack filled in from the inventory and a
 * person saved unchanged; it is not AI. "Imported from your workbook" (spreadsheet icon) marks a text
 * read from the person's own cetome REDact workbook by the exact importer; it is not AI either.
 * "From VEX" (shield icon) marks a text taken from the manufacturer's VEX statement (App\Sbom\Vex); not AI.
 */
enum Origin: string
{
    case AiGenerated = 'ai_generated';
    case AiEnhanced = 'ai_enhanced';
    case HumanEdited = 'human_edited';
    case Human = 'human';
    case FromInventory = 'from_inventory';
    case Imported = 'imported';
    case FromVex = 'from_vex';

    public function label(): string
    {
        return __('ai.origin.'.$this->value.'.label');
    }

    public function tooltip(): string
    {
        return __('ai.origin.'.$this->value.'.tooltip');
    }

    public function isAi(): bool
    {
        return $this === self::AiGenerated || $this === self::AiEnhanced;
    }
}
