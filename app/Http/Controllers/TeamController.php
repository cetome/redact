<?php

namespace App\Http\Controllers;

use App\Actions\ProductTeam;
use App\Actions\TransferOwnership;
use App\Billing\Plan;
use App\Enums\ProductRole;
use App\Mail\ProductInvitation;
use App\Models\Invitation;
use App\Models\Product;
use App\Models\ProductMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Product team: owner, contributors, reviewers and assessors (internal or external).
 * Anyone outside the product's organisation is external.
 */
class TeamController extends Controller
{
    public function index(Product $product, ?string $panel = null): View
    {
        $access = $this->access($product);
        abort_unless($access->canView(), 404);

        $product->load(['owner', 'members.user', 'organisation']);
        $invitations = $product->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->get();
        $plan = Plan::for($product->organisation);
        $teamLimit = $plan->teamLimit($product);
        $hasReviewer = $product->members->contains(fn ($m) => $m->role === ProductRole::Reviewer)
            || $invitations->contains(fn ($i) => $i->role === ProductRole::Reviewer);

        return view('team.index', [
            'product' => $product,
            'invitations' => $invitations,
            'access' => $access,
            // The free product's team: owner + N (any role). Submitting for review needs a reviewer: suggest one.
            'teamLimit' => $teamLimit,
            'teamCount' => $teamLimit === null ? null : Plan::teamCount($product),
            'teamFull' => $plan->teamFull($product),
            'suggestReviewer' => $teamLimit !== null && ! $hasReviewer,
            // Transfer ownership (owner and organisation admins): the organisation's other members.
            'candidates' => $access->canManage() && ! request()->user()->is_guest ? app(TransferOwnership::class)->candidates($product) : collect(),
            'transferring' => $panel === 'transfer',
            // /team/reviewer, /team/assessor: the invitation starts with that role.
            'panel' => $panel,
            'roles' => ProductTeam::ROLES,
        ]);
    }

    /** Hand the product over to another member of the organisation (App\Actions\TransferOwnership). */
    public function transfer(Request $request, Product $product, TransferOwnership $transfer): RedirectResponse
    {
        abort_if($request->user()->is_guest, 404);
        $this->allow('manage', $product);

        $data = $request->validate([
            'owner_id' => ['required', 'integer'],
            'previous' => ['required', Rule::in([ProductRole::Contributor->value, ProductRole::Reviewer->value, TransferOwnership::LEAVE])],
        ]);
        $newOwner = User::query()->findOrFail($data['owner_id']);
        $wasOwner = $product->owner_id === $request->user()->id;

        try {
            $transfer($product, $newOwner, $data['previous'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $status = __('transfer.done', ['product' => $product->name, 'name' => $newOwner->name]);

        // The previous owner who left the product no longer sees it.
        return $wasOwner && $data['previous'] === TransferOwnership::LEAVE && ! $product->organisation->isAdmin($request->user())
            ? redirect()->route('dashboard')->with('status', $status)
            : redirect()->route('team.index', $product)->with('status', $status);
    }

    public function invite(Request $request, Product $product, ProductTeam $team): RedirectResponse
    {
        // A sandbox has no team: invitations would mail outsiders from a throwaway account.
        abort_if($request->user()->is_guest, 404);
        $this->allow('manage', $product);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:191'],
            'role' => ['required', Rule::in(ProductTeam::roleValues())],
        ]);
        $email = Str::lower($data['email']);
        $role = ProductRole::from($data['role']);

        // The free product's team is limited (App\Billing\Plan); someone already counted is never refused.
        if ($refusal = $team->refusal($product, $email)) {
            return back()->withInput()->with('error', $refusal);
        }

        // One mail per address and product per day, 50 invitations per inviter per day.
        $perAddress = 'invite:'.$product->id.':'.sha1($email);
        $perInviter = 'invite-by:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($perAddress, 1) || RateLimiter::tooManyAttempts($perInviter, 50)) {
            return back()->with('status', __('team.invite_sent'));
        }
        RateLimiter::hit($perAddress, 86400);
        RateLimiter::hit($perInviter, 86400);

        $existing = User::where('email', $email)->first();
        if ($existing && $team->isOnTeam($product, $existing)) {
            return back()->with('status', __('team.invite_sent'));
        }

        [$invitation, $token] = $team->invite($product, $email, $role, $request->user());
        // In the language of the invited account, else of the person inviting.
        Mail::to($email)->locale($existing?->preferredLocale() ?? app()->getLocale())->queue(new ProductInvitation($invitation, (string) $token));

        // Same neutral answer whatever the address, so accounts cannot be probed.
        return back()->with('status', __('team.invite_sent'));
    }

    public function update(Request $request, Product $product, ProductMember $member): RedirectResponse
    {
        $this->allow('manage', $product);
        abort_unless($member->product_id === $product->id, 404);

        $data = $request->validate([
            'role' => ['required', Rule::in(ProductTeam::roleValues())],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $member->update($data + ['granted_by' => $request->user()->id]);

        return back()->with('status', __('common.saved'));
    }

    public function destroy(Request $request, Product $product, ProductMember $member): RedirectResponse
    {
        $user = $request->user();
        abort_unless($member->product_id === $product->id, 404);
        // The owner manages the team; anyone may leave a product.
        abort_unless(Gate::allows('manage', $product) || $member->user_id === $user->id, 404);

        $member->delete();

        return $member->user_id === $user->id
            ? redirect()->route('dashboard')->with('status', __('team.left'))
            : back()->with('status', __('team.removed'));
    }

    public function revoke(Product $product, Invitation $invitation): RedirectResponse
    {
        $this->allow('manage', $product);
        abort_unless($invitation->product_id === $product->id, 404);

        $invitation->delete();
        activity('team')->causedBy(request()->user())->performedOn($product)->event('invitation_revoked')
            ->withProperties(['invitation_id' => $invitation->id, 'email' => $invitation->email])
            ->log('invitation revoked');

        return back()->with('status', __('team.invitation_revoked'));
    }
}
