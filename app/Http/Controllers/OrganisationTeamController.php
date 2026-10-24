<?php

namespace App\Http\Controllers;

use App\Actions\ProductTeam;
use App\Actions\TransferOwnership;
use App\Billing\Plan;
use App\Enums\ProductRole;
use App\Mail\OrganisationInvitation;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The organisation's Team page: everyone in the organisation (role Admin or Member), the
 * outsiders on its products (External), the products each person is on with their product
 * role, and the pending invitations. Organisation admins (who also manage billing, the legal
 * details and every product: ProductAccess) invite people, with products in the same step,
 * put a person on several products at once, change organisation roles, remove a person from
 * the organisation and every one of its products, and revoke invitations. Members read it.
 *
 * Product roles stay on each product's Team page (TeamController); both go through
 * App\Actions\ProductTeam for the plan's team limit. Guests never reach it (GuestSandbox).
 * Anyone outside the organisation gets a 404, a member who tries to change something a 403.
 */
class OrganisationTeamController extends Controller
{
    public const ROLES = ['admin', 'member'];

    /** /team: the person's organisation (the first by name when there are several). */
    public function mine(Request $request): RedirectResponse
    {
        $organisation = $request->user()->organisations()->orderBy('name')->first();
        abort_unless($organisation !== null, 404);

        return redirect()->route('organisations.team', $organisation);
    }

    public function index(Request $request, Organisation $organisation, ProductTeam $team): View
    {
        $user = $request->user();
        $this->authorizeMember($request, $organisation);
        $isAdmin = $organisation->isAdmin($user);

        $products = $this->products($organisation);
        $people = $this->people($organisation, $products);
        $plan = Plan::for($organisation);

        // The person whose dialog is open (/team/{person}, loaded in place by data-dialog-fetch).
        $person = $request->route('person');
        $editing = $isAdmin && $person !== null ? $people->firstWhere('user.id', (int) $person) : null;

        return view('organisations.team', [
            'organisation' => $organisation,
            'organisations' => $user->organisations()->orderBy('name')->get(),
            'isAdmin' => $isAdmin,
            'people' => $people,
            'products' => $products,
            'invitations' => $isAdmin ? $this->pending($organisation, $products) : collect(),
            'adminCount' => $people->where('role', 'admin')->count(),
            // Free product's team (App\Billing\Plan): seats left for someone new.
            'seats' => $products->mapWithKeys(fn (Product $p) => [$p->id => [
                'limit' => $plan->teamLimit($p),
                'full' => $plan->teamFull($p),
            ]]),
            'editing' => $editing,
            // For the open person: products the plan would refuse them on (not counted yet, team full).
            'refusedFor' => $editing ? $products->filter(fn (Product $p) => $plan->teamWouldExceed($p, $editing['user']->email))->pluck('id')->flip() : collect(),
            'inviting' => $isAdmin && ($request->route('panel') === 'invite' || session()->hasOldInput('invite_email')),
            'viewerProducts' => $isAdmin ? $products->pluck('id')->flip() : $people->firstWhere('user.id', $user->id)['products']->pluck('product.id')->flip(),
            'roles' => ProductTeam::ROLES,
        ]);
    }

    /** Invite someone to the organisation, with products and a product role each, in one e-mail. */
    public function invite(Request $request, Organisation $organisation, ProductTeam $team): RedirectResponse
    {
        $inviter = $request->user();
        $this->authorizeAdmin($request, $organisation);

        $data = $request->validate([
            'invite_email' => ['required', 'email:rfc', 'max:191'],
            'organisation_role' => ['required', Rule::in(self::ROLES)],
            'products' => ['nullable', 'array'],
            'products.*' => ['nullable', Rule::in(ProductTeam::roleValues())],
        ], [], ['invite_email' => __('team.email')]);
        $email = Str::lower($data['invite_email']);
        $chosen = $this->chosenProducts($organisation, $data['products'] ?? []);

        $existing = User::query()->where('email', $email)->first();
        if ($existing && $organisation->hasMember($existing)) {
            // Already in: the products are given now, nothing to accept.
            if ($error = $this->refusals($team, $chosen, $existing)) {
                return back()->withInput()->with('error', $error);
            }
            $this->applyProducts($team, $existing, $chosen, $inviter);

            return back()->with('status', __('orgteam.already_member', ['name' => $existing->name]));
        }

        if ($error = $this->refusals($team, $chosen, null, $email)) {
            return back()->withInput()->with('error', $error);
        }

        // Three mails per address and organisation per day, 50 invitations per inviter per day (shared with product invitations).
        $perAddress = 'org-invite:'.$organisation->id.':'.sha1($email);
        $perInviter = 'invite-by:'.$inviter->id;
        if (RateLimiter::tooManyAttempts($perAddress, 3) || RateLimiter::tooManyAttempts($perInviter, 50)) {
            return back()->withInput()->with('error', __('orgteam.too_many'));
        }
        RateLimiter::hit($perAddress, 86400);
        RateLimiter::hit($perInviter, 86400);

        $token = Str::random(48);
        $invitation = DB::transaction(function () use ($organisation, $email, $data, $chosen, $inviter, $team, $token) {
            // A new invitation replaces the one still pending for this address.
            $this->pendingFor($organisation, $email)->each(fn (Invitation $old) => $this->deleteInvitation($old));

            $invitation = Invitation::create([
                'organisation_id' => $organisation->id,
                'organisation_role' => $data['organisation_role'],
                'email' => $email,
                'external' => false,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(14),
            ]);
            foreach ($chosen as $item) {
                $team->invite($item['product'], $email, $item['role'], $inviter, $invitation);
            }

            activity('team')->causedBy($inviter)->performedOn($organisation)->event('invited')->withProperties([
                'invitation_id' => $invitation->id,
                'email' => $email,
                'organisation_role' => $data['organisation_role'],
                'products' => collect($chosen)->map(fn ($i) => ['product_id' => $i['product']->id, 'role' => $i['role']->value])->values()->all(),
            ])->log('organisation invitation sent');

            return $invitation;
        });

        Mail::to($email)->queue(new OrganisationInvitation($invitation, $token));

        return back()->with('status', __('orgteam.invited', ['email' => $email]));
    }

    /** Organisation role and the products of one person (add, change, remove), all or nothing. */
    public function update(Request $request, Organisation $organisation, User $person, ProductTeam $team): RedirectResponse
    {
        $by = $request->user();
        $this->authorizeAdmin($request, $organisation);
        $isMember = $organisation->hasMember($person);
        abort_unless($isMember || $this->isExternal($organisation, $person), 404);

        $data = $request->validate([
            'organisation_role' => ['nullable', Rule::in(self::ROLES)],
            'products' => ['nullable', 'array'],
            'products.*' => ['nullable', Rule::in(ProductTeam::roleValues())],
        ]);

        // Organisation role: never leave the organisation without an admin.
        $currentRole = $isMember ? $this->roleOf($organisation, $person) : null;
        $newRole = $isMember ? ($data['organisation_role'] ?? $currentRole) : null;
        if ($currentRole === 'admin' && $newRole !== 'admin' && $this->adminCount($organisation) <= 1) {
            return back()->with('error', __('orgteam.last_admin', ['name' => $person->name]));
        }

        // Products: only the ones of this organisation the person does not own.
        $products = $this->products($organisation)->reject(fn (Product $p) => $p->owner_id === $person->id);
        // A form without product selects (all owned, or none left) changes no product.
        $wanted = $data['products'] ?? [];
        $adds = [];
        $changes = [];
        $removals = [];
        foreach (array_key_exists('products', $data) ? $products : [] as $product) {
            $member = $product->members->firstWhere('user_id', $person->id);
            $active = $member && ($member->expires_at === null || $member->expires_at->isFuture());
            $role = filled($wanted[$product->id] ?? null) ? ProductRole::from($wanted[$product->id]) : null;
            if ($role && ! $active) {
                $adds[] = ['product' => $product, 'role' => $role];
            } elseif ($role && $member->role !== $role) {
                $changes[] = ['member' => $member, 'role' => $role];
            } elseif (! $role && $member) {
                $removals[] = $member;
            }
        }
        if ($error = $this->refusals($team, $adds, $person)) {
            return back()->with('error', $error);
        }

        DB::transaction(function () use ($organisation, $person, $by, $team, $currentRole, $newRole, $adds, $changes, $removals) {
            if ($newRole !== null && $newRole !== $currentRole) {
                $organisation->members()->updateExistingPivot($person->id, ['role' => $newRole]);
                activity('team')->causedBy($by)->performedOn($organisation)->event('role_changed')->withProperties([
                    'user_id' => $person->id, 'old' => $currentRole, 'new' => $newRole,
                ])->log('organisation role changed');
            }
            $this->applyProducts($team, $person, $adds, $by);
            foreach ($changes as $change) {
                $change['member']->update(['role' => $change['role'], 'granted_by' => $by->id]);
            }
            foreach ($removals as $member) {
                $member->delete();
            }
        });

        return redirect()->route('organisations.team', $organisation)->with('status', __('common.saved'));
    }

    /**
     * Remove a person from the organisation and from every one of its products. The products
     * they own need a new owner, chosen among the other members (new_owner[product id]).
     */
    public function destroy(Request $request, Organisation $organisation, User $person, TransferOwnership $transfer): RedirectResponse
    {
        $by = $request->user();
        $this->authorizeAdmin($request, $organisation);
        $isMember = $organisation->hasMember($person);
        abort_unless($isMember || $this->isExternal($organisation, $person), 404);

        if ($isMember && $this->roleOf($organisation, $person) === 'admin' && $this->adminCount($organisation) <= 1) {
            return back()->with('error', __('orgteam.last_admin', ['name' => $person->name]));
        }

        $owned = $organisation->products()->where('owner_id', $person->id)->orderBy('name')->get();
        $newOwners = (array) $request->input('new_owner', []);
        $candidates = $organisation->members()->whereKeyNot($person->id)->where('is_guest', false)->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $missing = $owned->reject(fn (Product $p) => in_array((int) ($newOwners[$p->id] ?? 0), $candidates, true));
        if ($missing->isNotEmpty()) {
            return back()->with('error', trans_choice('orgteam.needs_owner', $missing->count(), [
                'name' => $person->name,
                'products' => $missing->pluck('name')->implode(', '),
            ]));
        }

        DB::transaction(function () use ($organisation, $person, $by, $owned, $newOwners, $isMember, $transfer) {
            // Each owned product is handed over first (the new owner gets an e-mail), the person leaving it.
            foreach ($owned as $product) {
                $transfer($product, User::query()->findOrFail((int) $newOwners[$product->id]), TransferOwnership::LEAVE, $by);
            }
            ProductMember::query()->where('user_id', $person->id)
                ->whereIn('product_id', $organisation->products()->withTrashed()->select('id'))
                ->get()->each->delete();
            if ($isMember) {
                $organisation->members()->detach($person->id);
            }
            activity('team')->causedBy($by)->performedOn($organisation)->event('removed')->withProperties([
                'user_id' => $person->id,
                'new_owners' => $owned->mapWithKeys(fn (Product $p) => [$p->id => (int) $newOwners[$p->id]])->all(),
            ])->log('removed from the organisation');
        });

        if ($person->id === $by->id) {
            return redirect()->route('dashboard')->with('status', __('orgteam.left', ['organisation' => $organisation->name]));
        }

        return redirect()->route('organisations.team', $organisation)->with('status', __('orgteam.removed', ['name' => $person->name]));
    }

    public function revoke(Request $request, Organisation $organisation, Invitation $invitation): RedirectResponse
    {
        $this->authorizeAdmin($request, $organisation);
        $ours = $invitation->isForOrganisation()
            ? $invitation->organisation_id === $organisation->id
            : $invitation->product !== null && $invitation->product->organisation_id === $organisation->id;
        abort_unless($ours && $invitation->accepted_at === null, 404);

        $this->deleteInvitation($invitation);
        activity('team')->causedBy($request->user())->performedOn($organisation)->event('invitation_revoked')
            ->withProperties(['invitation_id' => $invitation->id, 'product_id' => $invitation->product_id, 'email' => $invitation->email])
            ->log('invitation revoked');

        return back()->with('status', __('team.invitation_revoked'));
    }

    // ---- Data -----------------------------------------------------------------------------

    /** @return EloquentCollection<int, Product> */
    private function products(Organisation $organisation): EloquentCollection
    {
        return $organisation->products()->with(['members'])->orderBy('name')->get();
    }

    /**
     * Everyone in the organisation, then the outsiders on its products, each with their
     * products: [user, role (admin|member|external), products: [product, role, expired]].
     *
     * @param  EloquentCollection<int, Product>  $products
     * @return Collection<int, array{user: User, role: string, products: Collection<int, array{product: Product, role: ProductRole, expired: bool}>}>
     */
    private function people(Organisation $organisation, EloquentCollection $products): Collection
    {
        $members = $organisation->members()->orderBy('name')->get();
        $memberIds = $members->pluck('id')->flip();
        $externalIds = $products->flatMap(fn (Product $p) => $p->members->pluck('user_id'))
            ->reject(fn ($id) => isset($memberIds[$id]))->unique();
        $externals = User::query()->whereIn('id', $externalIds)->orderBy('name')->get();

        $productsOf = function (User $user) use ($products): Collection {
            return $products->map(function (Product $product) use ($user) {
                if ($product->owner_id === $user->id) {
                    return ['product' => $product, 'role' => ProductRole::Owner, 'expired' => false];
                }
                $member = $product->members->firstWhere('user_id', $user->id);

                return $member ? ['product' => $product, 'role' => $member->role, 'expired' => $member->expires_at?->isPast() ?? false] : null;
            })->filter()->values();
        };

        return $members->map(fn (User $u) => ['user' => $u, 'role' => $u->getRelationValue('pivot')->role === 'admin' ? 'admin' : 'member', 'products' => $productsOf($u)])
            ->concat($externals->map(fn (User $u) => ['user' => $u, 'role' => 'external', 'products' => $productsOf($u)]))
            ->values();
    }

    /**
     * Pending invitations: to the organisation (with their products) and to one of its products.
     *
     * @param  EloquentCollection<int, Product>  $products
     * @return EloquentCollection<int, Invitation>
     */
    private function pending(Organisation $organisation, EloquentCollection $products): EloquentCollection
    {
        return Invitation::query()
            ->whereNull('accepted_at')->where('expires_at', '>', now())->whereNull('parent_id')
            ->where(fn ($q) => $q->where(fn ($o) => $o->where('organisation_id', $organisation->id)->whereNull('product_id'))
                ->orWhereIn('product_id', $products->pluck('id')))
            ->with(['product', 'children.product'])
            ->orderBy('email')->get();
    }

    /** @return EloquentCollection<int, Invitation> */
    private function pendingFor(Organisation $organisation, string $email): EloquentCollection
    {
        return Invitation::query()->where('organisation_id', $organisation->id)->whereNull('product_id')
            ->where('email', $email)->whereNull('accepted_at')->get();
    }

    private function deleteInvitation(Invitation $invitation): void
    {
        $invitation->children()->delete();
        $invitation->delete();
    }

    /**
     * @param  array<string, ?string>  $wanted  product id => role value
     * @return list<array{product: Product, role: ProductRole}>
     */
    private function chosenProducts(Organisation $organisation, array $wanted): array
    {
        $wanted = array_filter($wanted, fn ($role) => filled($role));
        if ($wanted === []) {
            return [];
        }

        return $organisation->products()->whereIn('id', array_keys($wanted))->orderBy('name')->get()
            ->map(fn (Product $p) => ['product' => $p, 'role' => ProductRole::from($wanted[$p->id])])
            ->values()->all();
    }

    /**
     * The plan's team limit on each new seat (App\Actions\ProductTeam), one message naming the products refused.
     *
     * @param  list<array{product: Product, role: ProductRole}>  $items
     */
    private function refusals(ProductTeam $team, array $items, ?User $user, ?string $email = null): ?string
    {
        $email ??= (string) $user?->email;
        $refused = collect($items)
            ->reject(fn ($item) => $user && $item['product']->owner_id === $user->id)
            ->map(fn ($item) => ($reason = $team->refusal($item['product'], $email)) ? ['product' => $item['product']->name, 'reason' => $reason] : null)
            ->filter();

        return $refused->isEmpty() ? null : __('orgteam.refused', [
            'products' => $refused->pluck('product')->implode(', '),
            'reason' => $refused->first()['reason'],
        ]);
    }

    /** @param  list<array{product: Product, role: ProductRole}>  $items */
    private function applyProducts(ProductTeam $team, User $user, array $items, User $by): void
    {
        foreach ($items as $item) {
            $member = $team->add($item['product'], $user, $item['role'], $by->id);
            if ($member && $member->expires_at !== null && $member->expires_at->isPast()) {
                $member->update(['expires_at' => null]);
            }
        }
    }

    private function roleOf(Organisation $organisation, User $user): ?string
    {
        return $organisation->members()->whereKey($user->id)->first()?->getRelationValue('pivot')->role;
    }

    private function adminCount(Organisation $organisation): int
    {
        return $organisation->members()->wherePivot('role', 'admin')->count();
    }

    private function isExternal(Organisation $organisation, User $user): bool
    {
        return ProductMember::query()->where('user_id', $user->id)
            ->whereIn('product_id', $organisation->products()->select('id'))->exists();
    }

    private function authorizeMember(Request $request, Organisation $organisation): void
    {
        $user = $request->user();
        abort_if($user->is_guest, 404);
        abort_unless($organisation->hasMember($user), 404);
    }

    private function authorizeAdmin(Request $request, Organisation $organisation): void
    {
        $this->authorizeMember($request, $organisation);
        abort_unless($organisation->isAdmin($request->user()), 403);
    }
}
