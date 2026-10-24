<?php

namespace App\Models;

use App\Enums\Origin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Something the AI proposed. It stays here, in the AI channel, until a person
 * accepts it (the value is copied into the human field and a FieldOrigin
 * remembers where it came from) or rejects it.
 *
 * @property string $id
 * @property string $product_version_id
 * @property string $feature
 * @property string $target_type
 * @property string $target_key
 * @property string $field
 * @property Origin $origin
 * @property array<string, mixed> $payload
 * @property string $status
 * @property string $provider
 * @property string $model
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 */
#[Fillable(['product_version_id', 'feature', 'target_type', 'target_key', 'field', 'origin', 'payload', 'status',
    'provider', 'model', 'requested_by'])]
class AiSuggestion extends Model
{
    use HasUlids, LogsActivity;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'origin' => Origin::class,
            'decided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['feature', 'target_type', 'target_key', 'field', 'status'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('ai');
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'product_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
