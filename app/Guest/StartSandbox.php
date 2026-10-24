<?php

namespace App\Guest;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a guest account and its "Guest workspace" organisation (the guest
 * is its admin, so the full product workflow works unchanged).
 */
class StartSandbox
{
    public function __invoke(string $locale): User
    {
        return DB::transaction(function () use ($locale) {
            $user = new User([
                'name' => __('guest.name'),
                'email' => Sandbox::newEmail(),
                'locale' => $locale,
            ]);
            $user->forceFill([
                'is_guest' => true,
                'guest_expires_at' => Sandbox::expiresFromNow(),
            ])->save();

            $organisation = Organisation::create([
                'name' => __('guest.organisation'),
                'created_by' => $user->getKey(),
            ]);
            $organisation->members()->attach($user->getKey(), ['role' => 'admin']);

            return $user->refresh();
        });
    }
}
