<?php

namespace App\Models;

use App\Enums\ProductRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $id
 * @property string $organisation_id
 * @property int $owner_id
 * @property string $name
 * @property string|null $reference
 * @property string|null $description
 * @property string|null $image_path
 * @property string|null $plan_slot free | granted | paid | demo (App\Billing\Plan), null = created before plans (grandfathered)
 * @property bool $is_demo the demo product an account loaded (App\Guest\DemoProduct): read-only, never counted by the plan
 * @property-read Organisation $organisation
 * @property-read User $owner
 */
#[Fillable(['organisation_id', 'owner_id', 'name', 'reference', 'description', 'category'])]
class Product extends Model
{
    use HasUlids, LogsActivity, Prunable, SoftDeletes;

    /** Deleted products can be restored by cetome for 30 days, then they are gone for good. */
    public const RESTORE_DAYS = 30;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_demo' => 'boolean'];
    }

    /** @param Builder<Product> $query */
    public function scopeNotDemo(Builder $query): void
    {
        $query->where('is_demo', false);
    }

    /** @return Builder<Product> */
    public function prunable(): Builder
    {
        return static::onlyTrashed()->where('deleted_at', '<', now()->subDays(self::RESTORE_DAYS));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'reference', 'description', 'category', 'owner_id'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('product');
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<ProductMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProductMember::class);
    }

    /** @return HasMany<ProductVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProductVersion::class)->orderByDesc('created_at');
    }

    /** @return HasOne<ProductVersion, $this> the most recent version (what the product page shows) */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(ProductVersion::class)->latestOfMany('created_at');
    }

    /** @return HasMany<ProductIdentifier, $this> the models of the product (one row each, with an optional note) */
    public function models(): HasMany
    {
        return $this->hasMany(ProductIdentifier::class)->where('kind', ProductIdentifier::MODEL)->orderBy('position');
    }

    /** Where "open the product" leads: the Overview of its latest version, else the product page. */
    public function homeUrl(): string
    {
        $latest = $this->relationLoaded('latestVersion') ? $this->latestVersion : $this->versions()->first(['id']);

        return $latest ? route('versions.show', $latest) : route('products.show', $this);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** The person's role on this product, or null when they have none. */
    public function roleOf(User $user): ?ProductRole
    {
        if ($this->owner_id === $user->getKey()) {
            return ProductRole::Owner;
        }

        $member = $this->members()
            ->where('user_id', $user->getKey())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        return $member?->role;
    }
}
