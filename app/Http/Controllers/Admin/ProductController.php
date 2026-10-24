<?php

namespace App\Http\Controllers\Admin;

use App\Admin\ProductDirectory;
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
