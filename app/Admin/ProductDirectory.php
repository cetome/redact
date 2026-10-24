<?php

namespace App\Admin;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The console's list of customer products, with the figures support needs at a glance.
 */
final class ProductDirectory
{
    /**
     * @param  array{q?: string|null}  $filters
     * @return Builder<Product>
     */
    public function query(array $filters): Builder
    {
        $query = Product::query()
            ->select('products.*')
            ->with(['organisation:id,name', 'owner:id,name,email'])
            ->withCount(['versions', 'members'])
            ->selectSub(
                DB::table('product_versions')->whereColumn('product_versions.product_id', 'products.id')
                    ->orderByDesc('created_at')->limit(1)->select('status'),
                'latest_status'
            )
            ->selectSub(
                DB::table('product_versions')->whereColumn('product_versions.product_id', 'products.id')
                    ->orderByDesc('created_at')->limit(1)->select('label'),
                'latest_label'
            )
            ->selectSub(
                DB::table('request_logs')->whereColumn('request_logs.product_id', 'products.id')
                    ->selectRaw('MAX(created_at)'),
                'last_hit_at'
            );

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn (Builder $q) => $q
                ->where('products.name', 'like', $like)
                ->orWhere('products.reference', 'like', $like)
                ->orWhereHas('organisation', fn (Builder $o) => $o->where('name', 'like', $like))
                ->orWhereHas('owner', fn (Builder $o) => $o->where('email', 'like', $like)));
        }

        return $query->orderByDesc('products.updated_at');
    }
}
