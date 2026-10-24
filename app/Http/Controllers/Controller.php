<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVersion;
use App\Support\ProductAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;

abstract class Controller
{
    /**
     * Authorise or answer 404: a refusal must not reveal that the record exists.
     * Someone who can see a product made read-only by its plan (subscription ended,
     * App\Billing\Plan) is told why instead: "Your subscription has ended: reactivate to edit".
     */
    protected function allow(string $ability, mixed $model): void
    {
        if (Gate::allows($ability, $model)) {
            return;
        }

        $product = $model instanceof ProductVersion ? $model->product : ($model instanceof Product ? $model : null);
        $user = request()->user();
        if ($product !== null && $user !== null) {
            $access = ProductAccess::for($user, $product);
            if ($access->isBillingLocked() && $access->canView()) {
                throw new HttpResponseException(request()->expectsJson()
                    ? response()->json(['message' => __('plans.locked.banner')], 409)
                    : back()->with('error', __('plans.locked.banner')));
            }
        }

        abort(404);
    }

    /**
     * The visitor's rights on a product. Logs cetome support access once per
     * session and product, so customers' data is never read silently.
     */
    protected function access(Product $product): ProductAccess
    {
        $access = ProductAccess::for(request()->user(), $product);

        if ($access->isSupportAccess()) {
            $key = 'support_access.'.$product->id;
            if (! session()->has($key)) {
                session()->put($key, true);
                activity('admin')
                    ->causedBy(request()->user())
                    ->performedOn($product)
                    ->withProperties(['ip' => request()->ip()])
                    ->log('support access');
            }
        }

        return $access;
    }
}
