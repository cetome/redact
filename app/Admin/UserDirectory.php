<?php

namespace App\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The console's list of accounts: search, filters and the counts shown per row.
 * Guest sandbox accounts (App\Guest) are hidden unless the "guests" filter is chosen.
 */
final class UserDirectory
{
    public const STATUSES = ['active', 'disabled', 'unverified', 'staff', 'guests'];

    /**
     * @param  array{q?: string|null, status?: string|null}  $filters
     * @return Builder<User>
     */
    public function query(array $filters): Builder
    {
        $query = User::query()
            ->select('users.*')
            ->withCount('passkeys')
            ->with(['organisations:id,name', 'roles:id,name'])
            ->selectSub(
                DB::table('products')
                    ->whereNull('products.deleted_at')
                    ->where(fn ($q) => $q->whereColumn('products.owner_id', 'users.id')
                        ->orWhereExists(fn ($m) => $m->from('product_members')
                            ->whereColumn('product_members.product_id', 'products.id')
                            ->whereColumn('product_members.user_id', 'users.id')))
                    ->selectRaw('COUNT(*)'),
                'products_count'
            );

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn (Builder $q) => $q
                ->where('users.name', 'like', $like)
                ->orWhere('users.email', 'like', $like)
                ->orWhere('users.organisation_name', 'like', $like)
                ->orWhereHas('organisations', fn (Builder $o) => $o->where('name', 'like', $like)));
        }

        $query->where('users.is_guest', ($filters['status'] ?? null) === 'guests');

        match ($filters['status'] ?? null) {
            'active' => $query->where('users.active', true),
            'disabled' => $query->where('users.active', false),
            'unverified' => $query->whereNull('users.email_verified_at'),
            'staff' => $query->whereHas('roles', fn (Builder $r) => $r->whereIn('name', ['staff', 'super-admin'])),
            default => null,
        };

        return $query->orderByDesc('users.created_at')->orderByDesc('users.id');
    }

    /** Organisation shown for a person: their organisations, else the name they typed at sign-up. */
    public static function organisationOf(User $user): string
    {
        $names = $user->organisations->pluck('name')->filter()->all();

        return $names !== [] ? implode(', ', $names) : (string) $user->organisation_name;
    }

    /** @return list<string> */
    public static function csvHeader(): array
    {
        return ['id', 'name', 'email', 'organisation', 'created_at', 'email_verified_at', 'last_login_at',
            'last_login_method', 'passkeys', 'products', 'active', 'roles', 'locale'];
    }

    /** @return \Generator<int, list<mixed>> */
    public function csvRows(array $filters): \Generator
    {
        foreach ($this->query($filters)->reorder()->lazyById(500, 'users.id', 'id') as $user) {
            yield [
                $user->id,
                $user->name,
                $user->email,
                self::organisationOf($user),
                $user->created_at,
                $user->email_verified_at,
                $user->last_login_at,
                $user->last_login_method,
                $user->passkeys_count,
                $user->products_count,
                $user->active,
                $user->roles->pluck('name')->implode(' '),
                $user->locale,
            ];
        }
    }
}
