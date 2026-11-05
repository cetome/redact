<?php

namespace App\Admin;

use App\Auth\SecurityLog;
use App\Billing\ProratedRefund;
use App\Documents\DocumentStore;
use App\Guest\SandboxPurger;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Cashier;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Stripe\Exception\InvalidRequestException;

/**
 * Deletes an account for good from the console (super-admin, owner 2026-11-04): the person, and the
 * organisations where they are the only member with every product, version and file.
 *
 *  - Paid organisations: their Stripe customer is deleted, which cancels the subscription at once.
 *    With a refund, the unused part of the period is refunded first (App\Billing\ProratedRefund).
 *    Stripe keeps the invoices and payments (accounting records), REDact keeps nothing.
 *  - Refused while the person owns products in, or is the only admin of, an organisation shared
 *    with others: ownership or the admin role is handed over first (the team pages).
 *  - Stripe goes first: when Stripe refuses, nothing is deleted.
 *  - The security log keeps its entries (its own retention), like any deleted account.
 */
class AccountEraser
{
    public function __construct(private readonly SandboxPurger $purger, private readonly ProratedRefund $refunds) {}

    /**
     * What deleting the account takes with it, and what stops it.
     *
     * @return array{organisations: Collection<int, Organisation>, products: int, blockers: list<string>}
     */
    public function plan(User $user): array
    {
        $organisations = $user->organisations()->withCount('members')->get();
        $own = $organisations->filter(fn (Organisation $o) => (int) $o->getAttribute('members_count') === 1)->values();

        $blockers = [];
        foreach ($organisations->diff($own) as $organisation) {
            $owns = Product::withTrashed()->where('organisation_id', $organisation->getKey())->where('owner_id', $user->getKey())->exists();
            $lastAdmin = $organisation->isAdmin($user) && $organisation->members()->wherePivot('role', 'admin')->count() === 1;
            if ($owns) {
                $blockers[] = __('admin.users.erase.owns_products', ['organisation' => $organisation->name]);
            } elseif ($lastAdmin) {
                $blockers[] = __('admin.users.erase.last_admin', ['organisation' => $organisation->name]);
            }
        }

        return [
            'organisations' => $own,
            'products' => Product::withTrashed()->whereIn('organisation_id', $own->modelKeys())->count(),
            'blockers' => $blockers,
        ];
    }

    /**
     * What a refund would give back, per paying organisation: the unused part of the current period.
     *
     * @param  Collection<int, Organisation>  $organisations
     * @return list<array{organisation: Organisation, amount: string, paid: string, date: string, days: int, total: int}>
     */
    public function refundable(Collection $organisations): array
    {
        $refundable = [];
        foreach ($organisations as $organisation) {
            $refund = $this->refunds->describe($organisation);
            if ($refund !== null) {
                $refundable[] = ['organisation' => $organisation] + $refund;
            }
        }

        return $refundable;
    }

    /**
     * Deletes the account. $refund: the last payment of each paying organisation is refunded first.
     *
     * @return array{organisations: int, products: int, refunds: list<string>, customers: int}
     */
    public function erase(User $user, User $by, bool $refund): array
    {
        $plan = $this->plan($user);
        if ($plan['blockers'] !== []) {
            throw new RuntimeException(implode(' ', $plan['blockers']));
        }
        $organisations = $plan['organisations'];

        // 1. Stripe: the refund, then the customer (its subscriptions end with it).
        $refunds = [];
        $customers = 0;
        foreach ($organisations as $organisation) {
            if (! $organisation->hasStripeId()) {
                continue;
            }
            $stripe = Cashier::stripe();
            if ($refund && ($amount = $this->refunds->issue($organisation, $by)) !== null) {
                $refunds[] = $amount;
            }
            try {
                $stripe->customers->delete((string) $organisation->stripe_id);
                $customers++;
            } catch (InvalidRequestException $e) {
                // Already deleted in the dashboard: nothing left at Stripe.
                if ($e->getStripeCode() !== 'resource_missing') {
                    throw $e;
                }
            }
        }

        // 2. REDact: products and their files, the organisations, the account.
        $products = Product::withTrashed()->whereIn('organisation_id', $organisations->modelKeys())->get();
        $versionIds = ProductVersion::query()->whereIn('product_id', $products->modelKeys())->pluck('id')->all();
        $summary = ['organisations' => $organisations->count(), 'products' => $products->count(), 'refunds' => $refunds, 'customers' => $customers];

        DB::transaction(function () use ($user, $by, $organisations, $products, $versionIds, $summary, $refund) {
            SecurityLog::log('account erased', $user, ['by' => $by->getKey(), 'user_id' => $user->getKey(), 'refund' => $refund] + $summary, $by);

            activity()->withoutLogging(function () use ($user, $organisations, $products, $versionIds) {
                foreach ([
                    [(new Product)->getMorphClass(), $products->modelKeys()],
                    [(new ProductVersion)->getMorphClass(), $versionIds],
                    [(new Organisation)->getMorphClass(), $organisations->modelKeys()],
                    [$user->getMorphClass(), [$user->getKey()]],
                ] as [$type, $ids]) {
                    if ($ids !== []) {
                        Activity::query()->where('subject_type', $type)->whereIn('subject_id', $ids)
                            ->where(fn ($q) => $q->whereNull('log_name')->orWhere('log_name', '!=', 'security'))
                            ->delete();
                    }
                }

                foreach ($products as $product) {
                    $product->forceDelete();
                }
                $subscriptions = DB::table('subscriptions')->whereIn('organisation_id', $organisations->modelKeys())->pluck('id');
                DB::table('subscription_items')->whereIn('subscription_id', $subscriptions)->delete();
                DB::table('subscriptions')->whereIn('id', $subscriptions)->delete();

                $this->purger->deleteAccount($user);

                foreach ($organisations as $organisation) {
                    $organisation->delete();
                }
            });
        });

        // Files after the rows: a failed transaction must not leave rows without their files.
        foreach ($versionIds as $versionId) {
            Storage::disk(DocumentStore::DISK)->deleteDirectory('documents/'.$versionId);
        }
        foreach ($products->modelKeys() as $productId) {
            Storage::disk('local')->deleteDirectory('products/'.$productId);
        }
        foreach ($organisations->modelKeys() as $organisationId) {
            Storage::disk('local')->deleteDirectory('organisations/'.$organisationId);
        }
        Storage::disk('local')->deleteDirectory('users/'.$user->getKey());

        return $summary;
    }
}
