<?php

namespace App\Guest;

use App\Documents\DocumentStore;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * Deletes an expired sandbox for good: its products (soft-deleted ones too)
 * with every version, assessment row and stored file, its workspace
 * organisation, its sessions and sign-in tokens, then the guest itself.
 *
 *  - products.owner_id restricts deleting the user, so products go first
 *    (versions and assessment rows cascade in the database);
 *  - activity about the sandbox is deleted; the "guest" log keeps anonymous
 *    entries (console figures); the security log keeps its own retention,
 *    like any deleted account (App\Auth\AccountDeleter);
 *  - request_logs rows lose the user id but keep guest = true, so usage
 *    analytics still count them as guest page views.
 */
class SandboxPurger
{
    /**
     * @return array{products: int, versions: int, files: int, organisations: int}
     */
    public function purge(User $guest): array
    {
        $products = Product::withTrashed()->where('owner_id', $guest->getKey())->get();
        $versionIds = ProductVersion::query()->whereIn('product_id', $products->modelKeys())->pluck('id')->all();
        $workspaces = $guest->organisations()->withCount('members')->get()
            ->filter(fn (Organisation $org) => (int) $org->getAttribute('members_count') === 1)
            ->values();

        $counts = [
            'products' => $products->count(),
            'versions' => count($versionIds),
            'files' => DB::table('documents')->whereIn('product_version_id', $versionIds)->count(),
            'organisations' => $workspaces->count(),
        ];
        $ageHours = $guest->created_at ? (int) $guest->created_at->diffInHours(now()) : null;

        DB::transaction(function () use ($guest, $products, $versionIds, $workspaces, $counts, $ageHours) {
            activity()->withoutLogging(function () use ($guest, $products, $versionIds, $workspaces) {
                $subjects = [
                    [$guest->getMorphClass(), [$guest->getKey()]],
                    [(new Product)->getMorphClass(), $products->modelKeys()],
                    [(new ProductVersion)->getMorphClass(), $versionIds],
                    [(new Organisation)->getMorphClass(), $workspaces->modelKeys()],
                ];
                foreach ($subjects as [$type, $ids]) {
                    if ($ids !== []) {
                        Activity::query()->where('subject_type', $type)->whereIn('subject_id', $ids)
                            ->where(fn ($q) => $q->whereNull('log_name')->orWhereNotIn('log_name', ['security', Sandbox::LOG]))
                            ->delete();
                    }
                }

                foreach ($products as $product) {
                    $product->forceDelete();
                }

                $this->deleteAccount($guest);

                foreach ($workspaces as $organisation) {
                    if (! $organisation->products()->withTrashed()->exists()) {
                        $organisation->delete();
                    }
                }
            });

            activity(Sandbox::LOG)->withProperties($counts + ['age_hours' => $ageHours])->log('sandbox expired');
        });

        // Files after the rows: a failed transaction must not leave rows without their files.
        foreach ($versionIds as $versionId) {
            Storage::disk(DocumentStore::DISK)->deleteDirectory('documents/'.$versionId);
        }
        // Product pictures (App\Assessment\ProductImage, e.g. the demo product's).
        foreach ($products->modelKeys() as $productId) {
            Storage::disk('local')->deleteDirectory('products/'.$productId);
        }

        return $counts;
    }

    /**
     * Removes the guest row and what hangs on it (sessions and roles here;
     * sign-in tokens, passkeys and memberships cascade). Its products must
     * already be gone or moved.
     */
    public function deleteAccount(User $guest): void
    {
        $id = $guest->getKey();
        $morph = $guest->getMorphClass();

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $id)->delete();
        }

        DB::table((string) config('permission.table_names.model_has_roles', 'model_has_roles'))
            ->where('model_type', $morph)->where('model_id', $id)->delete();

        DB::table('request_logs')->where('user_id', $id)->update(['user_id' => null, 'guest' => true]);

        // Ordinary history goes, sandbox figures stay anonymous, the security log is left as is.
        Activity::query()
            ->where('causer_type', $morph)->where('causer_id', $id)
            ->where(fn ($q) => $q->whereNull('log_name')->orWhereNotIn('log_name', ['security', Sandbox::LOG]))
            ->delete();
        Activity::query()
            ->where('log_name', Sandbox::LOG)
            ->where('causer_type', $morph)->where('causer_id', $id)
            ->update(['causer_type' => null, 'causer_id' => null]);

        activity()->withoutLogging(fn () => $guest->delete());
    }
}
