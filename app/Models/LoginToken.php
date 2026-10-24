<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One e-mail sign-in challenge: a magic link and a 6-digit code bound to the
 * requesting browser. Only hashes are stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $purpose
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
#[Fillable(['user_id', 'purpose', 'token_hash', 'code_hash', 'bind_hash', 'new_email', 'intended', 'expires_at'])]
class LoginToken extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
