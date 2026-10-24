<?php

namespace App\Actions;

use App\Billing\Plan;
use App\Enums\ProductRole;
use App\Mail\ProductOwnershipTransferred;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Hands a product over to another member of its organisation (product Team page, organisation
 * Team page). The new owner is a member of the organisation, not a guest; an outsider on the team
 * cannot own it (the product belongs to the organisation). The previous owner stays as
 * Contributor or Reviewer, or leaves the product; on the free product a seat must be left for
 * them (App\Billing\Plan). The plan slot never changes. Product logs owner_id ("Handed … over
 * to …" in the history); the new owner gets an e-mail.
 */
class TransferOwnership
{
    public const LEAVE = 'leave';

    /** Who may receive the product: the organisation's members except the owner, no guests. */
    public function candidates(Product $product): Collection
    {
        return $product->organisation->members()
            ->whereKeyNot($product->owner_id)->where('is_guest', false)
            ->orderBy('name')->get(['users.id', 'users.name', 'users.email']);
    }

    /**
     * @param  string  $previous  contributor | reviewer | leave: what the previous owner becomes
     *
     * @throws RuntimeException with the message to show when the hand-over is refused
     */
    public function __invoke(Product $product, User $newOwner, string $previous, User $by): void
    {
        if (! $this->candidates($product)->contains('id', $newOwner->id)) {
            throw new RuntimeException(__('transfer.not_member', ['name' => $newOwner->name, 'organisation' => $product->organisation->name]));
        }
        $oldOwner = $product->owner;

        DB::transaction(function () use ($product, $newOwner, $oldOwner, $previous, $by) {
            $product->members()->where('user_id', $newOwner->id)->get()->each->delete();
            $product->update(['owner_id' => $newOwner->id]);

            if ($previous !== self::LEAVE) {
                $plan = Plan::fresh($product->organisation);
                if ($plan->teamWouldExceed($product, (string) $oldOwner->email)) {
                    throw new RuntimeException(__('transfer.no_seat', ['name' => $oldOwner->name, 'reason' => $plan->teamRefusal($product)]));
                }
                $product->members()->create([
                    'user_id' => $oldOwner->id,
                    'role' => ProductRole::from($previous),
                    'external' => ! $product->organisation->hasMember($oldOwner),
                    'granted_by' => $by->id,
                ]);
            }
        });

        // Only once everything is saved: a hand-over inside a larger change (removal from the
        // organisation) that fails afterwards sends nothing.
        $fresh = $product->fresh();
        DB::afterCommit(fn () => Mail::to($newOwner)->queue(new ProductOwnershipTransferred($fresh, $by)));
    }
}
