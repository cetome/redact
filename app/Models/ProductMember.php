<?php

namespace App\Models;

use App\Enums\ProductRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $product_id
 * @property int $user_id
 * @property ProductRole $role
 * @property bool $external
 * @property Carbon|null $expires_at
 * @property-read User $user
 */
#[Fillable(['product_id', 'user_id', 'role', 'external', 'granted_by', 'expires_at'])]
class ProductMember extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'role' => ProductRole::class,
            'external' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['product_id', 'user_id', 'role', 'external', 'expires_at'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('team');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
