<?php

namespace App\Actions;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sign-up: an unverified account plus its own organisation, of which the
 * person is admin (none while an organisation invitation waits for the address:
 * PersonalOrganisation). The e-mail sign-in that follows verifies the address.
 * The organisation is not asked at sign-up (owner, 2026-10-23): it is named
 * after the person until an admin renames it (Team page); the declaration of
 * conformity has its own manufacturer details.
 */
class CreateAccount
{
    /**
     * @param  array{name: string, email: string, organisation?: ?string, product_news?: bool, locale?: string}  $data
     */
    public function __invoke(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = new User([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'organisation_name' => self::organisation($data) !== '' ? self::organisation($data) : null,
                'locale' => $data['locale'] ?? config('app.locale'),
                'wants_product_news' => (bool) ($data['product_news'] ?? false),
            ]);
            $user->forceFill([
                'terms_accepted_at' => now(),
                'terms_version' => (string) config('redact.auth.terms_version'),
            ])->save();

            // Invited to an organisation: no organisation of their own (PersonalOrganisation).
            if (PersonalOrganisation::pendingInvitation($user->email) === null) {
                $organisation = Organisation::create([
                    'name' => self::organisationName($data['name'], self::organisation($data)),
                    'created_by' => $user->getKey(),
                ]);
                $organisation->members()->attach($user->getKey(), ['role' => 'admin']);
            }

            // Database defaults (active, session_epoch, ...) into the model.
            return $user->refresh();
        });
    }

    /** The name of a new organisation: the one given, else the person's name. */
    public static function organisationName(string $person, ?string $given): string
    {
        $given = trim((string) $given);

        return $given !== '' ? $given : trim($person);
    }

    /** @param  array<string, mixed>  $data */
    private static function organisation(array $data): string
    {
        return trim((string) ($data['organisation'] ?? ''));
    }
}
