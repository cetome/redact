<?php

namespace App\Models;

use App\Enums\ProductRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An invitation to a product (product_id, role), or to an organisation (organisation_id,
 * organisation_role, no product) with one child row per product offered in the same step
 * (parent_id): only the parent carries the e-mailed token; the children are pending seats on
 * their products (App\Billing\Plan) and are accepted with it (InvitationController).
 *
 * @property string $id
 * @property string|null $organisation_id
 * @property string|null $organisation_role admin | member (organisation invitation)
 * @property string|null $parent_id
 * @property string|null $product_id
 * @property string $email
 * @property ProductRole|null $role
 * @property bool $external
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property-read Product|null $product
 * @property-read Organisation|null $organisation
 * @property-read User $inviter
 * @property-read Collection<int, Invitation> $children
 */
#[Fillable(['organisation_id', 'organisation_role', 'parent_id', 'product_id', 'email', 'role', 'external', 'token_hash', 'invited_by', 'expires_at'])]
class Invitation extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'role' => ProductRole::class,
            'external' => 'boolean',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * The products offered with an organisation invitation.
     *
     * @return HasMany<Invitation, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Invitation::class, 'parent_id');
    }

    public function isForOrganisation(): bool
    {
        return $this->organisation_id !== null && $this->product_id === null;
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
