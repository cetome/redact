<?php

namespace App\Http\Controllers\Admin;

use App\Admin\ProductDirectory;
use App\Models\Product;
use App\Support\PathPaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customer products. Each row links to the customer's product page, where
 * staff get read-only support access (banner shown, visit logged).
 * Search in the session (POST …/filter), pages /admin/products/page/{n}.
 */
class ProductController extends AdminController
{
    private const FILTERS = 'admin.products';

    private const RULES = ['q' => ['nullable', 'string', 'max:120']];

    /**
     * Deletes a customer's product (super-admin, owner 2026-11-05: on the hosted service only a
     * super-admin deletes products, except in Enterprise organisations). Kept as deleted like any
     * product (restorable by cetome); its slot goes back to the organisation's plan.
     */
    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $this->requireSuperAdmin($request);
        abort_if($product->trashed(), 404);
        $organisation = $product->organisation;
        $product->delete();

        activity('admin')
            ->causedBy($this->staffUser($request))
            ->performedOn($product)
            ->event('product_deleted')
            ->withProperties(['product' => $product->name, 'organisation' => $organisation->name, 'slot' => $product->plan_slot])
            ->log('product deleted');

        return redirect()->route('admin.organisations.show', $organisation)->with('status', __('products.deleted', ['name' => $product->name]));
    }

    public function index(Request $request, ProductDirectory $directory, int $page = 1): View
    {
        $filters = $this->rememberedFilters($request, self::FILTERS, self::RULES);

        return view('admin.products.index', [
            'products' => PathPaginator::of($directory->query($filters), 25, $page, route('admin.products.index')),
            'filters' => ['q' => $filters['q']],
        ]);
    }

    public function filter(Request $request): RedirectResponse
    {
        return $this->rememberFilters($request, self::FILTERS, self::RULES, route('admin.products.index'));
    }
}
