<?php

namespace App\Guest;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The guest sandbox ("Try without an account"): rules shared by the
 * controllers, the middleware, the purge and the views.
 *
 *  - A guest is a real users row with is_guest = true, an address
 *    guest-<ulid>@guest.invalid (RFC 2606: can never receive mail) and its
 *    own "Guest workspace" organisation.
 *  - Full workflow and reports. No AI, no documents, no team, never staff,
 *    one product.
 *  - guest_expires_at slides to now + ttl_hours (TouchGuestSandbox), then
 *    redact:purge-guests deletes everything. Creating an account in time
 *    keeps the work (ClaimSandbox).
 */
final class Sandbox
{
    /** Domain of every guest address. ".invalid" is reserved and never routable. */
    public const EMAIL_DOMAIN = 'guest.invalid';

    /** Activity log name for sandbox events (started, kept, expired). No personal data. */
    public const LOG = 'guest';

    public static function enabled(): bool
    {
        return (bool) config('redact.guest.enabled', true);
    }

    public static function ttlHours(): int
    {
        return max(1, (int) config('redact.guest.ttl_hours', 24));
    }

    public static function expiresFromNow(): Carbon
    {
        return now()->addHours(self::ttlHours());
    }

    public static function newEmail(): string
    {
        return 'guest-'.Str::lower((string) Str::ulid()).'@'.self::EMAIL_DOMAIN;
    }

    /** Addresses under a reserved TLD (".invalid") never receive mail. */
    public static function isReservedAddress(?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email, " \t\n\r\0\x0B<>"));

        return $email === '' || str_ends_with($email, '.invalid');
    }

    public static function isGuest(?User $user): bool
    {
        return $user !== null && (bool) $user->is_guest;
    }

    public static function maxProducts(): int
    {
        return max(1, (int) config('redact.guest.max_products', 1));
    }

    /** True when $user is a guest who already has as many products as a sandbox may hold. */
    public static function productLimitReached(User $user): bool
    {
        return self::isGuest($user)
            && Product::query()->where('owner_id', $user->getKey())->count() >= self::maxProducts();
    }

    /** Where a signed-in guest belongs: their product, else the product form. */
    public static function home(User $user): string
    {
        $product = Product::query()->where('owner_id', $user->getKey())->orderBy('created_at')->first();

        return $product !== null ? route('products.show', $product) : route('products.create');
    }

    /** When the sandbox of $user is deleted, in their time zone and language (banner, confirm dialog). */
    public static function expiryLabel(User $user): string
    {
        $expires = $user->guest_expires_at ?? self::expiresFromNow();

        return $expires->copy()
            ->setTimezone($user->timezone ?: config('app.timezone'))
            ->locale(app()->getLocale())
            ->isoFormat('D MMMM YYYY, HH:mm');
    }
}
