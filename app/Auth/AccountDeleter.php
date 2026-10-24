<?php

namespace App\Auth;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Deletes an account (self-service or purge of unverified accounts).
 *
 *  - Refused while the person owns products (products.owner_id): ownership
 *    must be transferred or the products deleted first.
 *  - Organisations where the person is the only member and that hold no
 *    product are deleted with it.
 *  - Activity: the person is removed as causer of ordinary entries, entries
 *    about the account itself are deleted, the security log is kept (its
 *    retention period applies).
 */
class AccountDeleter
{
    public function ownsProducts(User $user): bool
    {
        return DB::table('products')->where('owner_id', $user->getKey())->exists();
    }

    public function delete(User $user, string $reason): void
    {
        DB::transaction(function () use ($user, $reason) {
            SecurityLog::log('account deleted', $user, ['reason' => $reason, 'user_id' => $user->getKey()]);

            $soleOrganisations = $user->organisations()->get()->filter(
                fn (Organisation $org) => $org->members()->count() === 1
                    && ! DB::table('products')->where('organisation_id', $org->getKey())->exists()
            );

            $id = $user->getKey();
            $morph = $user->getMorphClass();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $id)->delete();
            }

            $user->delete();

            foreach ($soleOrganisations as $org) {
                $org->delete();
            }

            Activity::query()
                ->where('subject_type', $morph)->where('subject_id', $id)
                ->where(fn ($q) => $q->whereNull('log_name')->orWhere('log_name', '!=', 'security'))
                ->delete();

            Activity::query()
                ->where('causer_type', $morph)->where('causer_id', $id)
                ->where(fn ($q) => $q->whereNull('log_name')->orWhere('log_name', '!=', 'security'))
                ->update(['causer_type' => null, 'causer_id' => null]);
        });
    }
}
