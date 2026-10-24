<?php

namespace App\Models;

use App\Enums\Origin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Remembers that a human field holds text a person accepted from the AI.
 * The mark stays only while the text is unchanged: any edit makes it
 * "Human Edited" (same rule as CRAted).
 *
 * @property string $target_type
 * @property string $target_key
 * @property string $field
 * @property Origin $origin
 * @property string $value_hash
 * @property string|null $ai_suggestion_id
 * @property int|null $accepted_by
 */
#[Fillable(['target_type', 'target_key', 'field', 'origin', 'value_hash', 'ai_suggestion_id', 'accepted_by'])]
class FieldOrigin extends Model
{
    protected function casts(): array
    {
        return ['origin' => Origin::class];
    }

    public static function hashValue(?string $value): string
    {
        return hash('sha256', trim((string) $value));
    }

    /** The origin to display for the field's current value. */
    public function originFor(?string $currentValue): Origin
    {
        return self::hashValue($currentValue) === $this->value_hash ? $this->origin : Origin::HumanEdited;
    }
}
