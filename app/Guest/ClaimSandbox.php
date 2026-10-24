<?php

namespace App\Guest;

use App\Actions\CreateAccount;
use App\Actions\PersonalOrganisation;
use App\Auth\SecurityLog;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * "Keep my work": the guest proved control of an e-mail address (code), so
 * the sandbox becomes a real account.
 *
 *  - convert(): the address is new. The guest row itself becomes the account
 *    (same id, same products, same organisation renamed). Invited to an
 *    organisation and nothing made in the sandbox: no organisation of their
 *    own, they join by accepting the invitation (PersonalOrganisation).
 *  - merge(): the address already has an account. The guest's products move
 *    into it (owner and organisation), what the guest wrote is attributed to
 *    that account, then the guest and its workspace are deleted.
 */
class ClaimSandbox
{
    /**
     * Columns that name who did something on the guest's products: on a
     * merge they are handed to the existing account instead of becoming null.
     */
    private const AUTHORSHIP = [
        'product_versions' => ['created_by', 'submitted_by'],
        'scoping_answers' => ['updated_by'],
        'risk_entries' => ['updated_by'],
        'inventory_items' => ['updated_by'],
        'evaluations' => ['updated_by'],
        'einfo_entries' => ['updated_by'],
        'evaluation_reviews' => ['reviewer_id'],
        'version_reviews' => ['reviewer_id'],
        'documents' => ['uploaded_by'],
        'ai_suggestions' => ['requested_by', 'decided_by'],
        'field_origins' => ['accepted_by'],
        'invitations' => ['invited_by'],
        'product_members' => ['granted_by'],
    ];

    public function __construct(private readonly SandboxPurger $purger) {}

    /**
     * @param  array{name: string, email: string, organisation: string, product_news: bool}  $data  organisation '' = named after the person
     */
    public function convert(User $guest, array $data): User
    {
        return DB::transaction(function () use ($guest, $data) {
            $guest->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'email_verified_at' => now(),
                'organisation_name' => $data['organisation'] !== '' ? $data['organisation'] : null,
                'wants_product_news' => $data['product_news'],
                'terms_accepted_at' => now(),
                'terms_version' => (string) config('redact.auth.terms_version'),
                'is_guest' => false,
                'guest_expires_at' => null,
            ])->save();

            $products = Product::withTrashed()->where('owner_id', $guest->getKey())->count();

            // Invited to an organisation (PersonalOrganisation): the sandbox workspace stays as their
            // own organisation only when it holds their products; empty, it goes and they join by
            // accepting the invitation.
            $invited = PersonalOrganisation::pendingInvitation($data['email']) !== null;
            foreach ($this->workspaces($guest) as $organisation) {
                if ($invited && ! $organisation->products()->withTrashed()->exists()) {
                    activity()->withoutLogging(fn () => $organisation->delete());

                    continue;
                }
                $organisation->update(['name' => CreateAccount::organisationName($data['name'], $data['organisation'])]);
                // The demo's fictitious manufacturer (DemoProduct) never becomes the person's legal identity.
                $settings = (array) $organisation->settings;
                if (! empty($settings[DemoProduct::MANUFACTURER_FLAG])) {
                    unset($settings[DemoProduct::MANUFACTURER_FLAG]);
                    $organisation->forceFill(['legal_name' => null, 'address' => null, 'country' => null, 'settings' => $settings ?: null])->save();
                }
            }

            activity(Sandbox::LOG)->causedBy($guest)
                ->withProperties(['path' => 'converted', 'products' => $products])
                ->log('sandbox kept');

            return $guest->refresh();
        });
    }

    /**
     * @param  array{name: string, email: string, organisation: string, product_news: bool}  $data  organisation '' = named after the person
     */
    public function merge(User $guest, User $account, array $data): User
    {
        return DB::transaction(function () use ($guest, $account, $data) {
            $organisation = $this->targetOrganisation($account, $data['organisation']);
            $guestId = $guest->getKey();
            $accountId = $account->getKey();

            $products = Product::withTrashed()->where('owner_id', $guestId)->get();
            foreach ($products as $product) {
                $product->forceFill([
                    'owner_id' => $accountId,
                    'organisation_id' => $organisation->getKey(),
                ])->save();
            }

            foreach (self::AUTHORSHIP as $table => $columns) {
                foreach ($columns as $column) {
                    DB::table($table)->where($column, $guestId)->update([$column => $accountId]);
                }
            }

            // What the guest did on the products is now the account's history.
            $morph = $guest->getMorphClass();
            Activity::query()
                ->where('causer_type', $morph)->where('causer_id', $guestId)
                ->where(fn ($q) => $q->whereNull('log_name')->orWhereNotIn('log_name', ['security', Sandbox::LOG]))
                ->update(['causer_id' => $accountId]);

            $workspaces = $this->workspaces($guest);

            activity(Sandbox::LOG)->causedBy($account)
                ->withProperties(['path' => 'merged', 'products' => $products->count()])
                ->log('sandbox kept');
            SecurityLog::log('guest sandbox merged', $account, ['products' => $products->count()], $account);

            $this->purger->deleteAccount($guest);
            activity()->withoutLogging(function () use ($workspaces) {
                foreach ($workspaces as $workspace) {
                    if (! $workspace->products()->withTrashed()->exists()) {
                        $workspace->delete();
                    }
                }
            });

            return $account;
        });
    }

    /** The account's first organisation where it is admin, else a new one. */
    private function targetOrganisation(User $account, string $name): Organisation
    {
        $organisation = $account->organisations()
            ->wherePivot('role', 'admin')
            ->orderBy('organisation_members.created_at')
            ->orderBy('organisations.id')
            ->first();

        if ($organisation !== null) {
            return $organisation;
        }

        $organisation = Organisation::create(['name' => CreateAccount::organisationName((string) $account->name, $name), 'created_by' => $account->getKey()]);
        $organisation->members()->attach($account->getKey(), ['role' => 'admin']);

        return $organisation;
    }

    /**
     * Organisations of the guest that only the guest belongs to (its "Guest workspace").
     *
     * @return list<Organisation>
     */
    private function workspaces(User $guest): array
    {
        return $guest->organisations()->withCount('members')->get()
            ->filter(fn (Organisation $org) => (int) $org->getAttribute('members_count') === 1)
            ->values()->all();
    }
}
