<?php

namespace App\Http\Controllers;

use App\Actions\CreateProduct;
use App\Billing\Billing;
use App\Billing\Plan;
use App\Guest\Sandbox;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductModels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class ProductController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (Sandbox::productLimitReached($request->user())) {
            return $this->sandboxFull();
        }

        $user = $request->user();
        $organisations = $user->organisations()->get();
        abort_if($organisations->isEmpty(), 404);

        // Only organisations whose plan has a product left; none: how to add one (App\Billing\Plan).
        if (! $user->is_guest) {
            $open = $organisations->reject(fn ($o) => Plan::for($o)->productLimitReached())->values();
            if ($open->isEmpty()) {
                return $this->upgrade($user, $organisations->first(fn ($o) => $o->isAdmin($user)) ?? $organisations->first());
            }
            $organisations = $open;
        }

        return view('products.create', ['organisations' => $organisations]);
    }

    /**
     * Creating a product IS scoping it (cetome.com RED scoping): the name, the
     * applicability questions, and the version gets those answers straight away.
     */
    public function store(Request $request, CreateProduct $create): RedirectResponse
    {
        $user = $request->user();
        if (Sandbox::productLimitReached($user)) {
            return $this->sandboxFull();
        }
        $data = $request->validate([
            'organisation_id' => ['required', 'string', Rule::in($user->organisations()->pluck('organisations.id'))],
            'name' => ['nullable', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
            'version_label' => ['nullable', 'string', 'max:40'],
            'answers' => ['nullable', 'array'],
            // The applied "Suggest with AI" proposal (encrypted token, checked server-side).
            'ai_scoping' => ['nullable', 'string', 'max:20000'],
        ]);

        $version = $create($user, $data);

        return redirect()->route('versions.edit', $version)->with('next', $create->createdMessage($version));
    }

    /**
     * Opening a product leads to the Overview of its latest version (which shows the product,
     * its versions and its team). A product without a version keeps a small page to start one.
     */
    public function show(Product $product): View|RedirectResponse
    {
        $access = $this->access($product);
        abort_unless($access->canView(), 404);

        if ($latest = $product->versions()->first(['id'])) {
            return redirect()->route('versions.show', $latest);
        }

        $product->load(['organisation', 'models']);

        return view('products.show', ['product' => $product, 'access' => $access]);
    }

    public function edit(Request $request, Product $product): View
    {
        $access = $this->access($product);
        // A read-only product (subscription ended, the demo) keeps its settings page for deleting it.
        abort_unless($access->canManage() || ($access->isReadOnlyProduct() && $access->canDelete()), 404);

        // The product card shows the product version and the documents of the latest version.
        $latest = $product->versions()->first();
        $product->load('models');

        return view('products.edit', [
            'product' => $product,
            'access' => $access,
            'latest' => $latest,
            'offerReduce' => $this->offersReduce($request->user(), $product),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->allow('manage', $product);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
            ...ProductModels::rules(),
        ]);

        $product->update(Arr::only($data, ['name', 'reference', 'description']));
        if ($request->has('models_sent')) { // the card's models block was in the form (even with every row removed)
            ProductModels::sync($product, $data['models'] ?? []);
        }
        // The product version (hardware, software) of the latest version, when that version can be edited.
        $latest = $product->versions()->first();
        if ($latest && $request->hasAny(['hardware_version', 'software_version']) && $this->access($product)->canEdit($latest)) {
            ProductModels::saveVersionNumbers($latest, $data);
        }

        return redirect()->route('products.show', $product)->with('status', __('common.saved'));
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $this->allow('delete', $product);

        $user = $request->user();
        $reduce = $request->boolean('reduce_subscription') && $this->offersReduce($user, $product);

        // A sandbox product and the demo are not kept for restoring: they go for good (the demo can be loaded again).
        $user->is_guest || $product->is_demo ? $product->forceDelete() : $product->delete();
        $status = $product->is_demo ? __('products.demo.removed') : __('products.deleted', ['name' => $product->name]);

        // "Also reduce your subscription": only when the deletion leaves a paid slot unused.
        $organisation = $product->organisation;
        if ($reduce && Plan::fresh($organisation)->unusedPaidSlots() > 0) {
            try {
                app(Billing::class)->removeProduct($organisation, $user);
                $status .= ' '.__('plans.billing.reduced');
            } catch (Throwable $e) {
                report($e);
                $status .= ' '.__('plans.billing.reduce_failed');
            }
        }

        return redirect()->route('dashboard')->with('status', $status);
    }

    /** New product with no product left on the plans of the person's organisations: how to add one. */
    private function upgrade(User $user, Organisation $organisation): View
    {
        return view('products.upgrade', [
            'organisation' => $organisation,
            'plan' => Plan::fresh($organisation),
            'isAdmin' => $organisation->isAdmin($user),
        ]);
    }

    /** Deleting a paid product may also take one unit off the subscription (an organisation admin decides). */
    private function offersReduce(User $user, Product $product): bool
    {
        return $product->plan_slot === Plan::SLOT_PAID && ! $user->is_guest && Plan::billingEnabled()
            && $product->organisation->isAdmin($user)
            && Plan::for($product->organisation)->subscriptionState() === 'active';
    }

    /** A guest sandbox holds one product: say so, kindly, and point to an account. */
    private function sandboxFull(): RedirectResponse
    {
        return redirect()->route('dashboard')->with('error', __('guest.product_limit'));
    }
}
