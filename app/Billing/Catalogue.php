<?php

namespace App\Billing;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;

/**
 * The plans and their Stripe prices ("Plans and prices" in the console).
 *
 * Plans above Free: the built-in ones of config redact.plans (Premium, key paid, and Enterprise), edited here, and
 * the ones made here (Business...). Each plan has a price per interval (month, year) and, for each
 * product beyond what it includes, an add-on price per interval ("Additional product"). Without
 * an add-on price for the interval, each additional product is one more unit of the plan price.
 * Stripe needs every price of one subscription on the same interval.
 *
 * Prices are created in Stripe from the console and kept in app_settings "catalogue" with their
 * mode (sandbox or live). A new price is the "current" one, used by new subscriptions; the old
 * ones stay known, so existing subscriptions keep their price and are still recognised.
 * STRIPE_PRICE_PRODUCT_MONTHLY (config redact.billing.price) stands for the Paid plan's monthly
 * price until the console has one.
 */
class Catalogue
{
    public const KEY = 'catalogue';

    public const ITEM_PLAN = 'plan';

    public const ITEM_EXTRA = 'extra_product';

    /** @var list<string> */
    public const ITEMS = [self::ITEM_PLAN, self::ITEM_EXTRA];

    /** @var list<string> */
    public const INTERVALS = ['month', 'year'];

    /** The default public plan (built in). */
    public const DEFAULT = 'paid';

    /** Plan fields the console may set. */
    public const FIELDS = ['products', 'team_members', 'ai_units', 'ai_period', 'extra_product_ai_units', 'support', 'public', 'active', 'names'];

    /** Stripe tax code "Software as a service (SaaS) - business use". */
    public const TAX_CODE = 'txcd_10103001';

    /** @return array{plans: array<string, array<string, mixed>>, prices: list<array<string, mixed>>} */
    public function stored(): array
    {
        $stored = AppSetting::get(self::KEY);

        return [
            'plans' => is_array($stored['plans'] ?? null) ? $stored['plans'] : [],
            'prices' => is_array($stored['prices'] ?? null) ? array_values($stored['prices']) : [],
        ];
    }

    /** @return array<string, PlanDefinition> built-in plans first (config order), then the console's */
    public function plans(): array
    {
        $stored = $this->stored()['plans'];
        $plans = [];
        foreach ((array) config('redact.plans', []) as $key => $defaults) {
            if ($key === Plan::FREE || ! is_array($defaults)) {
                continue;
            }
            $plans[$key] = $this->definition((string) $key, array_merge($defaults, (array) ($stored[$key] ?? [])), true);
        }
        foreach ($stored as $key => $attributes) {
            if (! isset($plans[$key])) {
                $plans[$key] = $this->definition((string) $key, $attributes, false);
            }
        }

        return $plans;
    }

    public function plan(?string $key): ?PlanDefinition
    {
        return $key === null ? null : ($this->plans()[$key] ?? null);
    }

    /**
     * Plans an organisation admin can buy online: active, public, with a plan price.
     *
     * @return list<PlanDefinition>
     */
    public function offered(): array
    {
        return array_values(array_filter($this->plans(), fn (PlanDefinition $p) => $p->active && $p->public && $this->intervals($p->key) !== []));
    }

    /** The plan the pricing page shows first: the default one, else the first public plan. */
    public function featured(): ?PlanDefinition
    {
        $public = array_values(array_filter($this->plans(), fn (PlanDefinition $p) => $p->active && $p->public));

        return $this->plan(self::DEFAULT)?->public ? $this->plan(self::DEFAULT) : ($public[0] ?? null);
    }

    /** Intervals the plan can be bought on (a plan price in the current mode). @return list<string> */
    public function intervals(string $plan): array
    {
        return array_values(array_filter(self::INTERVALS, fn (string $interval) => $this->price($plan, self::ITEM_PLAN, $interval) !== null));
    }

    /** The Stripe price id new subscriptions use, null when there is none. */
    public function price(string $plan, string $item, string $interval): ?string
    {
        $row = $this->current($plan, $item, $interval);
        if ($row !== null) {
            return (string) $row['id'];
        }
        $legacy = trim((string) config('redact.billing.price'));

        return $legacy !== '' && $plan === self::DEFAULT && $item === self::ITEM_PLAN && $interval === 'month' ? $legacy : null;
    }

    /**
     * What a Stripe price of a subscription stands for. A price the catalogue does not know (the
     * legacy one, or one made in the dashboard) is the default plan, monthly, one unit per product.
     *
     * @return array{plan: string, item: string, interval: string}
     */
    public function describe(?string $stripePrice): array
    {
        foreach ($this->stored()['prices'] as $row) {
            if (($row['id'] ?? null) === $stripePrice) {
                return ['plan' => (string) $row['plan'], 'item' => (string) $row['item'], 'interval' => (string) $row['interval']];
            }
        }

        return ['plan' => self::DEFAULT, 'item' => self::ITEM_PLAN, 'interval' => 'month'];
    }

    /** "100 €": the amount of the current price, else the plan's price label in the config. */
    public function label(string $plan, string $item, string $interval): string
    {
        $row = $this->current($plan, $item, $interval);
        if ($row !== null) {
            return StripeSettings::label((int) $row['amount'], (string) $row['currency']);
        }
        // An additional product without its own price costs one more unit of the plan.
        if ($item === self::ITEM_EXTRA && $this->current($plan, self::ITEM_PLAN, $interval) !== null) {
            return $this->label($plan, self::ITEM_PLAN, $interval);
        }
        $key = $interval === 'year' ? 'price_label_yearly' : 'price_label';

        return (string) (config("redact.plans.$plan.$key") ?? config('redact.plans.'.self::DEFAULT.'.'.$key, '—'));
    }

    /**
     * The amount of the current price, in cents (an additional product without its own price costs
     * one more unit of the plan). Null for a price the catalogue does not know (the legacy one).
     */
    public function amount(string $plan, string $item, string $interval): ?int
    {
        $row = $this->current($plan, $item, $interval) ?? ($item === self::ITEM_EXTRA ? $this->current($plan, self::ITEM_PLAN, $interval) : null);

        return $row !== null ? (int) $row['amount'] : null;
    }

    /** Some plan can be bought online in the current mode. */
    public function billable(): bool
    {
        return $this->offered() !== [];
    }

    /**
     * Every price of the catalogue, newest first, with how many subscriptions use it.
     *
     * @return list<array<string, mixed>>
     */
    public function prices(): array
    {
        return array_reverse($this->stored()['prices']);
    }

    /**
     * Create or change a plan (built-in or not). $attributes: FIELDS only.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function savePlan(string $key, array $attributes, User $by): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{1,30}$/', $key) || $key === Plan::FREE) {
            throw new InvalidArgumentException('Invalid plan key '.$key);
        }
        $stored = $this->stored();
        $stored['plans'][$key] = array_intersect_key($attributes, array_flip(self::FIELDS));
        $this->put($stored, $by);
    }

    /** Remove a plan made in the console (built-in ones can only be switched off). */
    public function deletePlan(string $key, User $by): void
    {
        $plan = $this->plan($key);
        if ($plan === null || $plan->builtIn) {
            throw new InvalidArgumentException('Only a plan made in the console can be deleted.');
        }
        $stored = $this->stored();
        unset($stored['plans'][$key]);
        $this->put($stored, $by);
    }

    /**
     * Create a price in Stripe (recurring, per unit, tax exclusive) on the plan's product for
     * that item (created the first time) and make it the current one. Existing subscriptions keep
     * the price they were made with (Stripe never changes a price).
     *
     * @return array<string, mixed> the stored row
     */
    public function createPrice(string $plan, string $item, string $interval, int $cents, User $by): array
    {
        $definition = $this->plan($plan);
        if ($definition === null || ! in_array($item, self::ITEMS, true) || ! in_array($interval, self::INTERVALS, true)) {
            throw new InvalidArgumentException('Unknown plan, item or interval.');
        }
        $mode = (string) app(StripeSettings::class)->mode();
        $stripe = Cashier::stripe();
        $stored = $this->stored();

        $product = null;
        foreach ($stored['prices'] as $row) {
            if ($row['plan'] === $plan && $row['item'] === $item && $row['mode'] === $mode && ! empty($row['product'])) {
                $product = (string) $row['product'];
            }
        }
        $name = self::productName($definition, $item);
        $product ??= $stripe->products->create(['name' => $name, 'tax_code' => self::TAX_CODE, 'metadata' => ['redact_plan' => $plan, 'redact_item' => $item]])->id;

        $currency = strtolower((string) config('cashier.currency', 'eur'));
        $price = $stripe->prices->create([
            'product' => $product,
            'currency' => $currency,
            'unit_amount' => $cents,
            'recurring' => ['interval' => $interval, 'usage_type' => 'licensed'],
            'tax_behavior' => 'exclusive',
            'lookup_key' => 'redact_'.$plan.'_'.$item.'_'.$interval,
            'transfer_lookup_key' => true,
            'nickname' => $name.', '.StripeSettings::label($cents, $currency).' per '.$interval,
            'metadata' => ['redact_plan' => $plan, 'redact_item' => $item],
        ]);

        foreach ($stored['prices'] as $i => $row) {
            if ($row['plan'] === $plan && $row['item'] === $item && $row['interval'] === $interval && $row['mode'] === $mode) {
                $stored['prices'][$i]['current'] = false;
            }
        }
        $row = [
            'id' => $price->id, 'plan' => $plan, 'item' => $item, 'interval' => $interval, 'amount' => $cents,
            'currency' => $currency, 'mode' => $mode, 'product' => $product, 'current' => true, 'created_at' => now()->toIso8601String(),
        ];
        $stored['prices'][] = $row;
        $this->put($stored, $by);

        return $row;
    }

    /** The Stripe product's name (Checkout and invoices show it): "REDact Premium", "REDact Premium: additional product". */
    public static function productName(PlanDefinition $definition, string $item): string
    {
        $name = (string) preg_replace('/\s+plan$/i', '', $definition->name('en'));

        return 'REDact '.$name.($item === self::ITEM_EXTRA ? ': additional product' : '');
    }

    /**
     * Rename the plan's Stripe products of the current mode after its name changed. The other
     * mode's products follow when a price is created there with its keys.
     *
     * @return int the products renamed
     */
    public function renameProducts(string $plan): int
    {
        $definition = $this->plan($plan);
        $mode = app(StripeSettings::class)->mode();
        if ($definition === null || $mode === null) {
            return 0;
        }
        $products = [];
        foreach ($this->stored()['prices'] as $row) {
            if ($row['plan'] === $plan && $row['mode'] === $mode && ! empty($row['product'])) {
                $products[(string) $row['product']] = (string) $row['item'];
            }
        }
        $stripe = Cashier::stripe();
        foreach ($products as $product => $item) {
            $stripe->products->update($product, ['name' => self::productName($definition, $item)]);
        }

        return count($products);
    }

    /** @return array<string, mixed>|null the current price row of the current mode */
    private function current(string $plan, string $item, string $interval): ?array
    {
        $mode = app(StripeSettings::class)->mode();
        if ($mode === null) {
            return null;
        }
        foreach (array_reverse($this->stored()['prices']) as $row) {
            if (($row['current'] ?? false) && $row['plan'] === $plan && $row['item'] === $item && $row['interval'] === $interval && $row['mode'] === $mode) {
                return $row;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $attributes */
    private function definition(string $key, array $attributes, bool $builtIn): PlanDefinition
    {
        $int = fn (string $field, ?int $default = null) => array_key_exists($field, $attributes) && $attributes[$field] !== null && $attributes[$field] !== ''
            ? max(0, (int) $attributes[$field]) : $default;
        $aiUnits = (int) $int('ai_units', 0);
        $period = (string) ($attributes['ai_period'] ?? 'month');

        return new PlanDefinition(
            key: $key,
            products: $int('products'),
            teamMembers: $int('team_members'),
            aiUnits: $aiUnits,
            aiPeriod: in_array($period, Plan::PERIODS, true) ? $period : 'month',
            extraProductAiUnits: (int) $int('extra_product_ai_units', $aiUnits),
            support: (bool) ($attributes['support'] ?? false),
            public: (bool) ($attributes['public'] ?? false),
            active: (bool) ($attributes['active'] ?? true),
            builtIn: $builtIn,
            names: array_filter(array_map(fn ($v) => Str::limit(trim((string) $v), 60, ''), (array) ($attributes['names'] ?? []))),
        );
    }

    /** @param  array{plans: array<string, mixed>, prices: list<array<string, mixed>>}  $stored */
    private function put(array $stored, User $by): void
    {
        AppSetting::put(self::KEY, ['plans' => $stored['plans'], 'prices' => $stored['prices']], $by);
    }
}
