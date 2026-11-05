<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Catalogue;
use App\Billing\Plan;
use App\Billing\PlanDefinition;
use App\Billing\StripeSettings;
use App\Models\Organisation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * "Plans and prices" (console): what each plan above Free includes (Paid, Enterprise, and the
 * ones made here: Business...), and its Stripe prices: the plan per month and per year, and the
 * add-on "Additional product" per month and per year. Staff read; a super-admin changes plans,
 * adds or deletes one, and creates prices in Stripe (in the mode of the keys in use). Logged
 * ("admin").
 */
class PlanCatalogueController extends AdminController
{
    public function __construct(private readonly Catalogue $catalogue) {}

    public function index(Request $request): View
    {
        // Subscriptions on each price (several items, or the one price of a one-item subscription).
        $counts = DB::table('subscription_items')->join('subscriptions', 'subscriptions.id', '=', 'subscription_items.subscription_id')
            ->whereNotIn('subscriptions.stripe_status', ['canceled', 'incomplete_expired'])
            ->groupBy('subscription_items.stripe_price')->pluck(DB::raw('count(*)'), 'subscription_items.stripe_price')->all();

        return view('admin.plans.index', [
            'catalogue' => $this->catalogue,
            'plans' => $this->catalogue->plans(),
            'prices' => collect($this->catalogue->prices()),
            'counts' => array_map('intval', $counts),
            'assigned' => Organisation::query()->where('plan', '!=', Plan::FREE)->groupBy('plan')->pluck(DB::raw('count(*)'), 'plan')->map(fn ($n) => (int) $n)->all(),
            'mode' => app(StripeSettings::class)->mode(),
            'currency' => strtoupper((string) config('cashier.currency', 'eur')),
            'canEdit' => $this->isSuperAdmin($request),
        ]);
    }

    /** A new plan (Business...): its key, names and what it includes. */
    public function store(Request $request): RedirectResponse
    {
        $this->requireSuperAdmin($request);
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,30}$/', Rule::notIn(array_merge([Plan::FREE], array_keys($this->catalogue->plans())))],
        ] + $this->rules(), ['key.regex' => __('plan_catalogue.errors.key'), 'key.not_in' => __('plan_catalogue.errors.key_taken')]);

        $this->catalogue->savePlan($data['key'], $this->attributes($data), $this->staffUser($request));
        $this->log($request, 'plan created', ['plan' => $data['key'], 'attributes' => $this->attributes($data)]);

        return redirect()->route('admin.plans.index')->with('status', __('plan_catalogue.created', ['name' => $this->catalogue->plan($data['key'])?->name()]));
    }

    public function update(Request $request, string $plan): RedirectResponse
    {
        $this->requireSuperAdmin($request);
        $definition = $this->find($plan);
        $data = $request->validate($this->rules());

        $this->catalogue->savePlan($plan, $this->attributes($data, $definition), $this->staffUser($request));
        $saved = $this->find($plan);
        $this->log($request, 'plan changed', ['plan' => $plan, 'old' => $this->summary($definition), 'attributes' => $this->summary($saved)]);

        $redirect = redirect()->route('admin.plans.index')->with('status', __('plan_catalogue.saved', ['name' => $saved->name()]));
        if ($saved->name('en') !== $definition->name('en')) {
            try {
                $this->catalogue->renameProducts($plan);
            } catch (Throwable $e) {
                $redirect->with('error', __('plan_catalogue.errors.stripe', ['error' => $e->getMessage()]));
            }
        }

        return $redirect;
    }

    /** Only a plan made here, and only when no organisation or subscription uses it. */
    public function destroy(Request $request, string $plan): RedirectResponse
    {
        $this->requireSuperAdmin($request);
        $definition = $this->find($plan);
        $prices = collect($this->catalogue->prices())->where('plan', $plan)->pluck('id')->all();
        $used = Organisation::query()->where('plan', $plan)->exists()
            || DB::table('subscription_items')->whereIn('stripe_price', $prices)->exists();
        if ($definition->builtIn || $used) {
            return back()->with('error', __($definition->builtIn ? 'plan_catalogue.errors.built_in' : 'plan_catalogue.errors.in_use'));
        }

        $this->catalogue->deletePlan($plan, $this->staffUser($request));
        $this->log($request, 'plan deleted', ['plan' => $plan]);

        return redirect()->route('admin.plans.index')->with('status', __('plan_catalogue.deleted', ['name' => $definition->name()]));
    }

    /** A price in Stripe for the plan or its add-on, per month or per year: the one new subscriptions use. */
    public function price(Request $request, string $plan): RedirectResponse
    {
        $this->requireSuperAdmin($request);
        $definition = $this->find($plan);
        abort_if(app(StripeSettings::class)->mode() === null, 404);
        $data = $request->validate([
            'item' => ['required', Rule::in(Catalogue::ITEMS)],
            'interval' => ['required', Rule::in(Catalogue::INTERVALS)],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $bag = 'price_'.$plan.'_'.$data['item'].'_'.$data['interval'];

        try {
            $row = $this->catalogue->createPrice($plan, $data['item'], $data['interval'], (int) $data['amount'] * 100, $this->staffUser($request));
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['amount' => __('plan_catalogue.errors.stripe', ['error' => $e->getMessage()])], $bag);
        }
        $label = StripeSettings::label((int) $row['amount'], (string) $row['currency']);
        $this->log($request, 'price created', ['plan' => $plan, 'item' => $data['item'], 'interval' => $data['interval'], 'price' => $row['id'], 'amount' => $label, 'mode' => $row['mode']]);

        return redirect()->route('admin.plans.index')->with('status', __('plan_catalogue.price.created', [
            'name' => $definition->name(), 'item' => __('plan_catalogue.items.'.$data['item']), 'price' => $label, 'interval' => __('plan_catalogue.per.'.$data['interval']),
        ]));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $rules = [
            'products' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'team_members' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'ai_units' => ['required', 'integer', 'min:0', 'max:100000000'],
            'ai_period' => ['required', Rule::in(Plan::PERIODS)],
            'extra_product_ai_units' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'support' => ['nullable', 'boolean'],
            'public' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ];
        foreach (array_keys((array) config('redact.locales', ['en' => 'English'])) as $locale) {
            $rules['names.'.$locale] = ['nullable', 'string', 'max:60'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?PlanDefinition $definition = null): array
    {
        $int = fn (string $field) => isset($data[$field]) && $data[$field] !== '' ? (int) $data[$field] : null;

        return [
            'products' => $int('products'),
            'team_members' => $int('team_members'),
            'ai_units' => (int) $data['ai_units'],
            'ai_period' => (string) $data['ai_period'],
            'extra_product_ai_units' => $int('extra_product_ai_units'),
            'support' => (bool) ($data['support'] ?? false),
            'public' => (bool) ($data['public'] ?? false),
            'active' => (bool) ($data['active'] ?? false),
            // A built-in plan keeps its translated name unless one is typed.
            'names' => array_filter(array_map(fn ($v) => trim((string) $v), (array) ($data['names'] ?? []))),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(PlanDefinition $plan): array
    {
        return [
            'products' => $plan->products, 'team_members' => $plan->teamMembers, 'ai_units' => $plan->aiUnits, 'ai_period' => $plan->aiPeriod,
            'extra_product_ai_units' => $plan->extraProductAiUnits, 'support' => $plan->support, 'public' => $plan->public, 'active' => $plan->active, 'names' => $plan->names,
        ];
    }

    private function find(string $plan): PlanDefinition
    {
        $definition = $this->catalogue->plan($plan);
        abort_if($definition === null, 404);

        return $definition;
    }

    /** @param  array<string, mixed>  $properties */
    private function log(Request $request, string $description, array $properties): void
    {
        activity('admin')->causedBy($this->staffUser($request))->withProperties($properties)->log($description);
    }
}
