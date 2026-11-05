<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The company that owns products. Its members are "internal"; anyone else
 * invited to one of its products is "external".
 *
 * @property string $id
 * @property string $name
 * @property string|null $logo_path its logo (Settings, PictureController)
 * @property bool $ai_enabled
 * @property array<string, mixed>|null $settings
 * @property string $plan free, or a plan set by staff: a contract, or a trial until plan_ends_at (App\Billing\Plan)
 * @property Carbon|null $plan_ends_at
 * @property array<string, mixed>|null $limits per-organisation overrides set by staff
 * @property int|null $created_by
 * @property string|null $stripe_id
 * @property string|null $legal_name manufacturer's legal name (EU declaration of conformity)
 * @property string|null $security_contact default security contact of the public product pages (e-mail or web address)
 * @property string|null $address
 * @property string|null $country
 * @property string|null $authorised_rep_name
 * @property string|null $authorised_rep_address
 */
#[Fillable(['name', 'created_by', 'ai_enabled', 'settings', 'legal_name', 'address', 'country', 'authorised_rep_name', 'authorised_rep_address', 'security_contact'])]
class Organisation extends Model
{
    // Billable: the organisation is the Stripe customer (Cashier::useCustomerModel, BillingServiceProvider).
    use Billable, HasUlids, LogsActivity;

    protected $attributes = [
        'plan' => 'free',
    ];

    // settings["ai"] holds the organisation's own AI key (encrypted, App\Ai\OrganisationAi): never serialised.
    protected $hidden = ['settings'];

    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
            'settings' => 'array',
            'limits' => 'array',
            'plan_ends_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'ai_enabled', 'plan', 'plan_ends_at', 'limits', 'legal_name', 'address', 'country', 'authorised_rep_name', 'authorised_rep_address', 'security_contact'])->logOnlyDirty()
            ->dontLogEmptyChanges()->useLogName('organisation');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organisation_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Stripe customer e-mail: the person who created the organisation, else its first admin. */
    public function stripeEmail(): ?string
    {
        $creator = $this->created_by ? User::query()->find($this->created_by) : null;

        return $creator->email ?? $this->members()->wherePivot('role', 'admin')->value('email');
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->getKey())->exists();
    }

    public function isAdmin(User $user): bool
    {
        return $this->members()->whereKey($user->getKey())->wherePivot('role', 'admin')->exists();
    }
}
