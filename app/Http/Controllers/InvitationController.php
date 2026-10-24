<?php

namespace App\Http\Controllers;

use App\Actions\PersonalOrganisation;
use App\Actions\ProductTeam;
use App\Models\Invitation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Accepting an invitation to a product, or to an organisation with the products offered in the
 * same step (its child invitations). The link alone is not enough: the signed-in account must
 * own the invited e-mail address (it was verified at sign-in), so a forwarded link cannot be
 * used by someone else.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $invitation = $this->find($token);

        return $this->page($request, $invitation, route('invitations.accept', $token));
    }

    /**
     * The organisation invitation waiting for the signed-in address, without the e-mailed link:
     * where someone invited lands after signing up (EnsureOrganisation, PersonalOrganisation).
     */
    public function pending(Request $request): View|RedirectResponse
    {
        $invitation = PersonalOrganisation::pendingInvitation((string) $request->user()->email);
        if ($invitation === null || $request->user()->is_guest) {
            return redirect()->route('dashboard');
        }

        return $this->page($request, $invitation, route('invitations.accept-pending', $invitation));
    }

    public function accept(Request $request, string $token, ProductTeam $team): RedirectResponse
    {
        return $this->acceptInvitation($request, $this->find($token), $team);
    }

    /** Accept by id: the signed-in account owns the invited address, which is what the link proves too. */
    public function acceptPending(Request $request, Invitation $invitation, ProductTeam $team): RedirectResponse
    {
        abort_unless($invitation->parent_id === null && $invitation->isPending(), 404);

        return $this->acceptInvitation($request, $invitation, $team);
    }

    /** No thanks: the invitation goes; someone left without an organisation gets their own. */
    public function decline(Request $request, Invitation $invitation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($invitation->parent_id === null && $invitation->isPending() && strcasecmp($invitation->email, $user->email) === 0, 404);

        DB::transaction(function () use ($invitation, $user) {
            $invitation->children()->delete();
            $invitation->delete();
            activity('team')->causedBy($user)->performedOn($invitation->organisation ?? $invitation->product ?? $user)->event('declined')
                ->withProperties(['invitation_id' => $invitation->id, 'product_id' => $invitation->product_id])
                ->log('invitation declined');
            PersonalOrganisation::ensure($user);
        });

        return redirect()->route('dashboard')->with('status', __('orgteam.declined'));
    }

    private function page(Request $request, ?Invitation $invitation, string $acceptUrl): View
    {
        return view('invitations.show', [
            'invitation' => $invitation,
            'offered' => $invitation?->isForOrganisation() ? $this->offered($invitation) : collect(),
            'matches' => $invitation && strcasecmp($invitation->email, $request->user()->email) === 0,
            'acceptUrl' => $acceptUrl,
        ]);
    }

    private function acceptInvitation(Request $request, ?Invitation $invitation, ProductTeam $team): RedirectResponse
    {
        $user = $request->user();
        abort_unless($invitation && strcasecmp($invitation->email, $user->email) === 0, 404);

        if ($invitation->isForOrganisation()) {
            // A sandbox never joins an organisation (it would see its people and products).
            abort_if($user->is_guest, 404);

            return $this->joinOrganisation($invitation, $user, $team);
        }

        DB::transaction(function () use ($invitation, $user, $team) {
            $team->add($invitation->product, $user, $invitation->role, $invitation->invited_by);
            $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();
        });

        return redirect()->route('products.show', $invitation->product)
            ->with('status', __('team.joined', ['product' => $invitation->product->name]));
    }

    /** Organisation membership first (so the products are internal), then each product offered. */
    private function joinOrganisation(Invitation $invitation, User $user, ProductTeam $team): RedirectResponse
    {
        $organisation = $invitation->organisation;

        DB::transaction(function () use ($invitation, $organisation, $user, $team) {
            $current = $organisation->members()->whereKey($user->id)->first();
            if ($current === null) {
                $organisation->members()->attach($user->id, ['role' => $invitation->organisation_role === 'admin' ? 'admin' : 'member']);
            } elseif ($invitation->organisation_role === 'admin' && $current->getRelationValue('pivot')->role !== 'admin') {
                $organisation->members()->updateExistingPivot($user->id, ['role' => 'admin']);
            }

            // Someone already on one of its products as an outsider is now internal.
            $organisation->products()->get()->each(function (Product $product) use ($user) {
                $product->members()->where('user_id', $user->id)->where('external', true)->get()->each->update(['external' => false]);
            });

            foreach ($this->offered($invitation) as $child) {
                $team->add($child->product, $user, $child->role, $invitation->invited_by);
                $child->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();
            }
            $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();

            activity('team')->causedBy($user)->performedOn($organisation)->event('joined')->withProperties([
                'user_id' => $user->id,
                'organisation_role' => $current?->getRelationValue('pivot')->role === 'admin' ? 'admin' : ($invitation->organisation_role ?? 'member'),
                'invitation_id' => $invitation->id,
            ])->log('organisation joined');
        });

        return redirect()->route('organisations.team', $organisation)
            ->with('status', __('orgteam.joined', ['organisation' => $organisation->name]));
    }

    /** @return Collection<int, Invitation> the products still offered (deleted products drop out) */
    private function offered(Invitation $invitation): Collection
    {
        return $invitation->children()->whereNull('accepted_at')->with('product')->get()
            ->filter(fn (Invitation $child) => $child->product !== null && $child->role !== null)->values()->toBase();
    }

    private function find(string $token): ?Invitation
    {
        if (strlen($token) < 20 || strlen($token) > 100) {
            return null;
        }
        $invitation = Invitation::where('token_hash', hash('sha256', $token))->with(['product', 'organisation'])->first();
        if (! $invitation?->isPending()) {
            return null;
        }

        return $invitation->product || ($invitation->isForOrganisation() && $invitation->organisation) ? $invitation : null;
    }
}
