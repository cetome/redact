<?php

namespace App\Models;

use App\Guest\Sandbox;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * A REDact account. Passwordless: e-mail link/code, then passkeys.
 *
 * Platform roles (cetome staff) come from spatie/laravel-permission;
 * product roles live on product_members.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $active
 * @property int $session_epoch
 * @property string $locale
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property string $timezone
 * @property bool $is_guest
 * @property bool $shortcuts_enabled
 * @property Carbon|null $guest_expires_at
 * @property-read int|null $products_count
 */
#[Fillable(['name', 'email', 'organisation_name', 'locale', 'timezone', 'wants_product_news', 'shortcuts_enabled'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable, PasskeyAuthenticatable;

    use HasRoles {
        assignRole as private grantRoles;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'active' => 'boolean',
            'wants_product_news' => 'boolean',
            'shortcuts_enabled' => 'boolean',
            'session_epoch' => 'integer',
            'is_guest' => 'boolean',
            'guest_expires_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'active', 'locale', 'organisation_name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('account');
    }

    /** @return BelongsToMany<Organisation, $this> */
    public function organisations(): BelongsToMany
    {
        return $this->belongsToMany(Organisation::class, 'organisation_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<ProductMember, $this> */
    public function productMemberships(): HasMany
    {
        return $this->hasMany(ProductMember::class);
    }

    /** @return HasMany<LoginToken, $this> */
    public function loginTokens(): HasMany
    {
        return $this->hasMany(LoginToken::class);
    }

    /** cetome staff with access to the admin console. A sandbox guest never is. */
    public function isStaff(): bool
    {
        return ! $this->is_guest && $this->hasAnyRole(['super-admin', 'staff']);
    }

    /**
     * Platform roles (spatie/laravel-permission). A sandbox guest can never
     * hold one, whoever asks (console, artisan, seeder).
     */
    public function assignRole(...$roles): static
    {
        if ($this->is_guest) {
            throw new LogicException('A guest sandbox account cannot hold a platform role.');
        }

        return $this->grantRoles(...$roles);
    }

    /** No e-mail ever goes to a reserved address (sandbox guests use *.invalid). */
    public function routeNotificationForMail(): ?string
    {
        return Sandbox::isReservedAddress($this->email) ? null : $this->email;
    }

    /**
     * No passwords: what Laravel binds the remember-me cookie to is the session
     * epoch, so revoking sessions also voids every remembered device (App\Auth\Remember).
     */
    public function getAuthPassword(): string
    {
        return 'session-epoch:'.(int) $this->session_epoch;
    }

    /**
     * Sign out every session of this account (e-mail change, passkey removal,
     * admin action), remembered devices included.
     */
    public function revokeSessions(): void
    {
        $this->increment('session_epoch', 1, ['remember_token' => bin2hex(random_bytes(30))]);
        $this->loginTokens()->whereNull('used_at')->update(['used_at' => now()]);
    }
}
