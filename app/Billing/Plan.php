<?php

namespace App\Billing;

use App\Ai\OrganisationAi;
use App\Models\AiUsage;
use App\Models\ContactMessage;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;

/**
 * What an organisation's plan allows (config redact.plans and the console's catalogue,
 * App\Billing\Catalogue; staff overrides in organisations.limits).
 *
 *  - The FIRST product is free forever: owner + 1 team member, no AI by default, full rules,
 *    downloads and imports (never limited by a plan).
 *  - A plan (Premium, Enterprise, Business...) includes a number of products (the free one counted),
 *    a team size, AI units per period and support. It comes from, in this order: a contract set
 *    by staff (organisations.plan, no end date), the Stripe subscription "products" (its plan
 *    price, plus one add-on unit or one more plan unit per additional product), a trial set by
 *    staff (organisations.plan until plan_ends_at, no card), or none (Free).
 *  - Staff may also grant extra products (override `extra_products`, e.g. partners).
 *
 * A product records the allowance it was created on (products.plan_slot). Products created
 * before plans existed (null) are grandfathered: never read-only, unlimited team; they use the
 * free slot first, so only NEW creations beyond the limits are refused. Paid and granted products
 * share the paid and granted allowance; when it shrinks (subscription cancelled or reduced, trial
 * over), the newest ones become read-only, never deleted; the free product stays editable.
 */
final class Plan
{
    public const FREE = 'free';

    public const ENTERPRISE = 'enterprise';

    public const SLOT_FREE = 'free';

    public const SLOT_GRANTED = 'granted';

    public const SLOT_PAID = 'paid';

    /** The demo product an account loads (App\Guest\DemoProduct, products.is_demo): never counted, never locked. */
    public const SLOT_DEMO = 'demo';

    /** @var list<string> */
    public const PERIODS = ['once', 'day', 'month', 'year'];

    /** Keys staff may override per organisation (admin console). */
    public const OVERRIDES = ['extra_products', 'team_members', 'ai_units', 'ai_period', 'support'];

    /** Where the plan comes from. */
    public const SOURCE_CONTRACT = 'contract';

    public const SOURCE_SUBSCRIPTION = 'subscription';

    public const SOURCE_TRIAL = 'trial';

    /**
     * @param  list<string>  $locked  ids of the products the plan no longer covers (read-only)
     * @param  int  $extraUnits  products the subscription pays beyond what its plan includes
     */
    private function __construct(
        public readonly Organisation $organisation,
        public readonly ?PlanDefinition $definition,
        public readonly ?string $source,
        public readonly ?CarbonInterface $trialEndsAt,
        public readonly ?string $interval,
        public readonly int $extraUnits,
        public readonly int $freeSlots,
        public readonly int $grantedSlots,
        public readonly int $paidSlots,
        public readonly int $freeUsed,
        public readonly int $grantedUsed,
        public readonly int $paidUsed,
        public readonly ?Subscription $subscription,
        public readonly array $locked,
        public readonly int $grandfathered = 0,
        public readonly int $coveredFree = 0,
    ) {}

    /** The plan of $organisation, worked out once per request (pages ask many times). */
    public static function for(Organisation $organisation): self
    {
        $memo = app()->bound('request') ? request()->attributes : null;
        $key = 'plan.'.$organisation->getKey();
        if ($memo?->has($key)) {
            return $memo->get($key);
        }
        $plan = self::fresh($organisation);
        $memo?->set($key, $plan);

        return $plan;
    }

    /** Always from the database (before creating a product, inviting, spending AI units). */
    public static function fresh(Organisation $organisation): self
    {
        if (app()->bound('request')) {
            request()->attributes->remove('plan.'.$organisation->getKey());
        }
        $catalogue = app(Catalogue::class);
        $freeSlots = max(0, (int) config('redact.plans.free.products', 1));

        // Set by staff: a contract (no end date) or a trial until plan_ends_at.
        $assigned = $organisation->plan !== self::FREE ? $catalogue->plan($organisation->plan) : null;
        $endsAt = $organisation->plan_ends_at;
        if ($assigned !== null && $endsAt !== null && $endsAt->isPast()) {
            $assigned = null;
        }

        $subscription = Subscription::query()->with('items')
            ->where('organisation_id', $organisation->getKey())
            ->where('type', (string) config('redact.billing.subscription', 'products'))
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        [$subscribed, $paidSlots, $extraUnits, $interval] = $subscription?->valid()
            ? self::readSubscription($subscription, $catalogue, $freeSlots)
            : [null, 0, 0, null];

        [$definition, $source] = match (true) {
            $assigned !== null && $endsAt === null => [$assigned, self::SOURCE_CONTRACT],
            $subscribed !== null => [$subscribed, self::SOURCE_SUBSCRIPTION],
            $assigned !== null => [$assigned, self::SOURCE_TRIAL],
            default => [null, null],
        };
        $grantedSlots = max(0, (int) (self::override($organisation, 'extra_products') ?? 0))
            + ($definition !== null && $source !== self::SOURCE_SUBSCRIPTION ? $definition->slotsBeyond($freeSlots) : 0);

        /** @var Collection<int, Product> $products */
        // The demo product (is_demo) takes no slot, never counts and is never locked.
        $products = $organisation->products()->notDemo()->orderBy('created_at')->orderBy('id')->get(['id', 'plan_slot', 'created_at']);
        $extras = $products->filter(fn (Product $p) => in_array($p->plan_slot, [self::SLOT_GRANTED, self::SLOT_PAID], true))->values();

        // Free products beyond the free allowance (e.g. a sandbox merged into an account that already
        // has its free product): a spare paid or granted slot covers them, otherwise the newest are
        // read-only like any product beyond its plan. Grandfathered products (no slot) use the free
        // allowance first and are never locked.
        $grandfathered = $products->filter(fn (Product $p) => $p->plan_slot === null)->count();
        $free = $products->where('plan_slot', self::SLOT_FREE)->values();
        $freeAllowance = max(0, $freeSlots - $grandfathered);
        // A plan without a product limit and a self-hosted install: nothing is ever locked.
        $unlimited = ($definition?->unlimited() ?? false) || self::selfHosted();
        $capacity = $grantedSlots + $paidSlots;
        $coveredFree = $unlimited ? 0 : min(max(0, $free->count() - $freeAllowance), max(0, $capacity - $extras->count()));

        // Paid and granted products share their allowance: the newest beyond it are read-only.
        $locked = $unlimited ? [] : array_values(array_merge(
            $free->slice($freeAllowance + $coveredFree)->pluck('id')->all(),
            $extras->slice($capacity)->pluck('id')->all(),
        ));

        return new self(
            organisation: $organisation,
            definition: $definition,
            source: $source,
            trialEndsAt: $source === self::SOURCE_TRIAL ? $endsAt : null,
            interval: $source === self::SOURCE_SUBSCRIPTION ? $interval : null,
            extraUnits: $source === self::SOURCE_SUBSCRIPTION ? $extraUnits : 0,
            freeSlots: $freeSlots,
            grantedSlots: $grantedSlots,
            paidSlots: $paidSlots,
            freeUsed: $products->filter(fn (Product $p) => $p->plan_slot === null || $p->plan_slot === self::SLOT_FREE)->count(),
            grantedUsed: $extras->where('plan_slot', self::SLOT_GRANTED)->count(),
            paidUsed: $extras->where('plan_slot', self::SLOT_PAID)->count(),
            subscription: $subscription,
            locked: $locked,
            grandfathered: $grandfathered,
            coveredFree: $coveredFree,
        );
    }

    /**
     * The plan a valid subscription pays for, the paid product slots it gives (what the plan
     * includes beyond the free product, plus one per add-on unit or extra plan unit), those extra
     * units, and its interval.
     *
     * @return array{0: PlanDefinition, 1: int, 2: int, 3: string}
     */
    private static function readSubscription(Subscription $subscription, Catalogue $catalogue, int $freeSlots): array
    {
        // Stripe price => quantity (a subscription never holds the same price twice).
        $items = $subscription->items->isNotEmpty()
            ? $subscription->items->pluck('quantity', 'stripe_price')->all()
            : [(string) $subscription->stripe_price => $subscription->quantity];

        $plan = null;
        $interval = 'month';
        $planUnits = 0;
        $extra = 0;
        foreach ($items as $price => $quantity) {
            $quantity = (int) $quantity;
            $described = $catalogue->describe((string) $price);
            $plan ??= $described['plan'];
            if ($described['item'] === Catalogue::ITEM_PLAN) {
                $interval = $described['interval'];
                $planUnits += max(0, $quantity);
            } else {
                $extra += max(0, $quantity);
            }
        }
        $definition = $catalogue->plan($plan) ?? $catalogue->plan(Catalogue::DEFAULT)
            ?? new PlanDefinition(Catalogue::DEFAULT, 2, null, 500, 'month', 500, true, true, true, true);
        $extraUnits = max(0, $planUnits - 1) + $extra;

        return [$definition, $definition->slotsBeyond($freeSlots) + $extraUnits, $extraUnits, $interval];
    }

    /** Stripe is set up (keys and a plan price): self-service plans. */
    public static function billingEnabled(): bool
    {
        return (string) config('cashier.key') !== ''
            && (string) config('cashier.secret') !== ''
            && app(Catalogue::class)->billable();
    }

    /**
     * A self-hosted install (config redact.self_hosted; automatic: billing not configured): it pays
     * its own AI provider, so the AI is not limited by the SaaS plans (aiAllowance()['own']). The
     * per-person quotas (App\Ai\Quota) and a staff AI override of an organisation still apply.
     */
    public static function selfHosted(): bool
    {
        $setting = config('redact.self_hosted');

        return is_bool($setting) ? $setting : ! self::billingEnabled();
    }

    /**
     * "Talk to us": REDACT_SALES_URL, else the REDact contact form with $topic preselected
     * (/contact/pricing; topics: App\Models\ContactMessage::TOPICS).
     */
    public static function contactUrl(string $topic = 'pricing'): string
    {
        $url = trim((string) config('redact.billing.contact_url'));
        if ($url !== '') {
            return $url;
        }

        return in_array($topic, ContactMessage::TOPICS, true) ? route('contact.topic', $topic) : route('contact');
    }

    /** What one additional product costs on the featured plan (pricing page, terms), per $interval. */
    public static function priceLabel(string $interval = 'month'): string
    {
        $catalogue = app(Catalogue::class);

        return $catalogue->label($catalogue->featured()->key ?? Catalogue::DEFAULT, Catalogue::ITEM_EXTRA, $interval);
    }

    /** What one additional product costs this organisation (its plan, per its interval). */
    public function extraPriceLabel(): string
    {
        $catalogue = app(Catalogue::class);

        return $catalogue->label($this->definition->key ?? ($catalogue->featured()->key ?? Catalogue::DEFAULT), Catalogue::ITEM_EXTRA, $this->interval ?? 'month');
    }

    /** "per month" or "per year": the organisation's billing interval (monthly without one). */
    public function per(): string
    {
        return __('plans.per.'.($this->interval ?? 'month'));
    }

    /** A staff override, or null when the config default applies. */
    public static function override(Organisation $organisation, string $key): mixed
    {
        $limits = is_array($organisation->limits) ? $organisation->limits : [];

        return $limits[$key] ?? null;
    }

    /** The plan's key ("paid", "enterprise"...), or "free". */
    public function key(): string
    {
        return $this->definition->key ?? self::FREE;
    }

    /**
     * A plan set by staff without an end date (Enterprise or another contract): no online
     * payment, the contract decides.
     */
    public function isEnterprise(): bool
    {
        return $this->source === self::SOURCE_CONTRACT;
    }

    /** A plan set by staff until a date (no card). */
    public function onTrial(): bool
    {
        return $this->source === self::SOURCE_TRIAL;
    }

    /** No product limit: a plan without one, or a self-hosted install (the SaaS plans do not apply). */
    public function unlimited(): bool
    {
        return ($this->definition?->unlimited() ?? false) || self::selfHosted();
    }

    /** Has the organisation a plan or extra products (team, AI, support)? */
    public function hasExtras(): bool
    {
        return $this->definition !== null || $this->grantedSlots + $this->paidSlots > 0;
    }

    /** The allowance the next product is created on (free first, then granted, then paid), null when none is left. */
    public function nextSlot(): ?string
    {
        if ($this->freeUsed < $this->freeSlots) {
            return self::SLOT_FREE;
        }
        if ($this->unlimited()) {
            return self::SLOT_GRANTED;
        }
        // Slots already covering extra free products are taken.
        if ($this->grantedUsed + $this->paidUsed + $this->coveredFree >= $this->grantedSlots + $this->paidSlots) {
            return null;
        }

        return $this->grantedUsed < $this->grantedSlots ? self::SLOT_GRANTED : self::SLOT_PAID;
    }

    public function productLimitReached(): bool
    {
        return $this->nextSlot() === null;
    }

    public function productCount(): int
    {
        return $this->freeUsed + $this->grantedUsed + $this->paidUsed;
    }

    /** "3 of 4": the products created against all the organisation may have, or "3, no limit". */
    public function productsSummary(): string
    {
        $limit = $this->productLimit();

        return $limit === null
            ? __('plans.billing.products_unlimited', ['used' => $this->productCount()])
            : __('plans.billing.products_value', ['used' => $this->productCount(), 'total' => $limit]);
    }

    /** Products the plan holds (null = no limit). Grandfathered products above it stay. */
    public function productLimit(): ?int
    {
        return $this->unlimited() ? null : max($this->grandfathered, $this->freeSlots) + $this->grantedSlots + $this->paidSlots;
    }

    /** Paid product slots not used by a product yet (bought, product not created). */
    public function unusedPaidSlots(): int
    {
        $spare = $this->grantedSlots + $this->paidSlots - $this->grantedUsed - $this->paidUsed - $this->coveredFree;

        return max(0, min($this->paidSlots, $spare));
    }

    /** Paid or granted products the plan no longer covers (what "Reactivate" pays for again). */
    public function lockedPaidCount(): int
    {
        return $this->unlimited() ? 0 : max(0, $this->grantedUsed + $this->paidUsed - $this->grantedSlots - $this->paidSlots);
    }

    public function isLocked(Product $product): bool
    {
        return in_array($product->getKey(), $this->locked, true);
    }

    /**
     * Subscription state for the UI: none, active, past_due (a payment failed, Stripe retries),
     * cancelling (cancelled, paid until the period ends), ended (cancelled or unpaid).
     */
    public function subscriptionState(): string
    {
        $subscription = $this->subscription;
        if ($subscription === null) {
            return 'none';
        }
        if (! $subscription->valid()) {
            return 'ended';
        }
        if ($subscription->pastDue()) {
            return 'past_due';
        }

        return $subscription->onGracePeriod() ? 'cancelling' : 'active';
    }

    /** Can an organisation admin buy a plan or another product in Stripe right now? */
    public function canBuy(): bool
    {
        return self::billingEnabled() && ! $this->isEnterprise()
            && in_array($this->subscriptionState(), ['none', 'ended', 'active'], true);
    }

    /**
     * The app bar's "Upgrade" (owner 2026-11-04: subscribe without going back to the pricing page):
     * the person administers an organisation on Free that can buy online.
     */
    public static function showsUpgrade(?User $user): bool
    {
        if ($user === null || $user->is_guest || ! self::billingEnabled()) {
            return false;
        }
        $organisation = $user->organisations()->get()->first(fn (Organisation $o) => $o->isAdmin($user));
        if ($organisation === null) {
            return false;
        }
        $plan = self::for($organisation);

        return $plan->definition === null && $plan->canBuy() && ! $plan->hasActiveSubscription();
    }

    /**
     * The most products bought online, the free one counted (owner 2026-11-04): 11. With Premium (2
     * included, 100 € a month or 1000 € a year, the same for each one more) that is 10 000 € a year,
     * or 1000 € a month. Beyond it: "Talk to us".
     */
    public const MAX_ONLINE_PRODUCTS = 11;

    /**
     * The organisation whose plan and billing the person manages (account menu, owner 2026-11-04):
     * one they administer, the paying one first. Null for a guest, a member only, or when there is
     * nothing to bill (no online payment and no Stripe customer).
     */
    public static function billingOrganisation(?User $user): ?Organisation
    {
        if ($user === null || $user->is_guest) {
            return null;
        }
        $organisations = $user->organisations()->get()->filter(fn (Organisation $o) => $o->isAdmin($user));
        $organisation = $organisations->first(fn (Organisation $o) => $o->hasStripeId()) ?? $organisations->first();
        if ($organisation === null || (! self::billingEnabled() && ! $organisation->hasStripeId())) {
            return null;
        }

        return $organisation;
    }

    /** The subscription already pays for the most products sold online: one more is "Talk to us". */
    public function onlineMaximumReached(): bool
    {
        return $this->freeSlots + $this->paidSlots >= self::MAX_ONLINE_PRODUCTS;
    }

    /** Add a slot to the existing subscription (confirm screen) rather than a new Checkout. */
    public function hasActiveSubscription(): bool
    {
        return $this->subscriptionState() === 'active';
    }

    /** The message when no product can be added. */
    public function productRefusal(): string
    {
        $count = $this->freeSlots + $this->grantedSlots + $this->paidSlots;

        return self::billingEnabled() && ! $this->isEnterprise()
            ? __('plans.refusal.products_pay', ['price' => $this->extraPriceLabel(), 'per' => $this->per()])
            : trans_choice('plans.refusal.products', max(1, $count), ['count' => max(1, $count)]);
    }

    // ---- Team -------------------------------------------------------------------------------

    /**
     * People a product's team may hold besides its owner (null = no limit): the plan's team size
     * for every product, or, without a plan, the free product's.
     */
    public function teamLimit(Product $product): ?int
    {
        if ($this->unlimited()) {
            return null;
        }
        $override = self::override($this->organisation, 'team_members');
        if ($this->definition !== null) {
            return $override !== null ? max(0, (int) $override) : $this->definition->teamMembers;
        }
        if ($product->plan_slot !== self::SLOT_FREE) {
            return null;
        }

        return max(0, (int) ($override ?? config('redact.plans.free.team_members', 1)));
    }

    /** Distinct people on the team besides the owner: members whose access runs, and open invitations. */
    public static function teamCount(Product $product): int
    {
        return count(self::teamEmails($product));
    }

    /** Would inviting $email take a seat the plan does not have? Re-inviting someone already counted never does. */
    public function teamWouldExceed(Product $product, string $email): bool
    {
        $limit = $this->teamLimit($product);
        if ($limit === null) {
            return false;
        }
        $emails = self::teamEmails($product);

        return ! in_array(Str::lower($email), $emails, true) && count($emails) >= $limit;
    }

    public function teamFull(Product $product): bool
    {
        $limit = $this->teamLimit($product);

        return $limit !== null && self::teamCount($product) >= $limit;
    }

    public function teamRefusal(Product $product): string
    {
        $limit = (int) $this->teamLimit($product);

        return trans_choice('plans.refusal.team', $limit, ['count' => $limit]);
    }

    /** @return list<string> */
    private static function teamEmails(Product $product): array
    {
        $memberIds = $product->members()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('user_id');
        $members = User::query()->whereIn('id', $memberIds)->where('id', '!=', $product->owner_id)->pluck('email');
        $pending = $product->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->pluck('email');

        return $members->merge($pending)->map(fn ($e) => Str::lower((string) $e))->unique()->values()->all();
    }

    // ---- AI ---------------------------------------------------------------------------------

    /**
     * The organisation's AI units: the plan's allowance plus what each additional product adds
     * (pooled), extra products granted alone (the default plan's per-product units), or the free
     * allowance (none by default, or a one-time trial). "own": not limited by the plan: a
     * self-hosted install (its own AI key, no staff AI override), or an organisation answered by
     * its own key or model (App\Ai\OrganisationAi: paid and Enterprise on the SaaS).
     *
     * @return array{units: int, period: string, used: int, left: int, trial: bool, renews: ?CarbonInterface, own: bool}
     */
    public function aiAllowance(): array
    {
        $granted = max(0, (int) (self::override($this->organisation, 'extra_products') ?? 0));
        if ($this->definition !== null) {
            $units = $this->definition->aiUnits + $this->definition->extraProductAiUnits * ($this->extraUnits + $granted);
            $period = $this->definition->aiPeriod;
        } elseif ($this->grantedSlots > 0) {
            $default = app(Catalogue::class)->plan(Catalogue::DEFAULT);
            $units = ($default->extraProductAiUnits ?? (int) config('redact.plans.paid.ai_units', 500)) * $this->grantedSlots;
            $period = $default->aiPeriod ?? 'month';
        } else {
            $units = (int) config('redact.plans.free.ai_units', 50);
            $period = (string) config('redact.plans.free.ai_period', 'once');
        }
        $units = max(0, (int) (self::override($this->organisation, 'ai_units') ?? $units));
        $period = (string) (self::override($this->organisation, 'ai_period') ?? $period);
        $period = in_array($period, self::PERIODS, true) ? $period : 'month';

        $since = match ($period) {
            'once' => null,
            'day' => now()->subDay(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
        };
        $renews = match ($period) {
            'month' => now()->startOfMonth()->addMonth(),
            'year' => now()->startOfYear()->addYear(),
            default => null,
        };

        // Refused calls never spent anything; failed ones did (the provider still charged). Calls
        // answered by the organisation's own model ("own:…") never consume the plan's units.
        $used = (int) AiUsage::query()
            ->where('organisation_id', $this->organisation->getKey())
            ->where('outcome', '!=', 'refused')
            ->where('provider', 'not like', 'own:%')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->sum('units');

        return [
            'units' => $units,
            'period' => $period,
            'used' => $used,
            'left' => max(0, $units - $used),
            'trial' => $period === 'once',
            'renews' => $renews,
            'own' => (self::selfHosted() && self::override($this->organisation, 'ai_units') === null)
                || app(OrganisationAi::class)->active($this->organisation, $this),
        ];
    }

    /** `null` when the organisation has $units AI units left, else the message to show. */
    public function aiRefusal(int $units = 1): ?string
    {
        $allowance = $this->aiAllowance();
        if ($allowance['own'] || $allowance['units'] >= $allowance['used'] + $units) {
            return null;
        }
        // The free plan has no AI: it comes with the first paid (or granted) product.
        if ($allowance['units'] === 0) {
            return __('plans.ai.paid_only');
        }

        if ($allowance['trial']) {
            return __('plans.refusal.ai_trial', ['units' => $allowance['units']]);
        }

        return $allowance['renews']
            ? __('plans.refusal.ai_period', ['units' => $allowance['units'], 'date' => $allowance['renews']->locale(app()->getLocale())->isoFormat('D MMMM YYYY')])
            : __('plans.refusal.ai_rolling', ['units' => $allowance['units']]);
    }

    /** Does the plan include AI at all (free plan: no; a plan, granted products or a staff override: yes)? */
    public function aiIncluded(): bool
    {
        $allowance = $this->aiAllowance();

        return $allowance['own'] || $allowance['units'] > 0;
    }

    /**
     * Where an organisation admin turns the AI on (Plan and billing, or "Talk to us" when billing is
     * off); null for anyone else or when the plan already includes the AI.
     */
    public function aiUpgradeUrl(?User $user): ?string
    {
        if ($user === null || $user->is_guest || $this->aiIncluded() || ! $this->organisation->isAdmin($user)) {
            return null;
        }

        return self::billingEnabled() && ! $this->isEnterprise() ? route('billing.show', $this->organisation) : self::contactUrl();
    }

    public function support(): bool
    {
        $override = self::override($this->organisation, 'support');
        if ($override !== null) {
            return (bool) $override;
        }
        if ($this->definition !== null) {
            return $this->definition->support;
        }

        return (bool) ($this->hasExtras() ? config('redact.plans.paid.support', true) : config('redact.plans.free.support', false));
    }

    /** "Free plan", "Paid plan", "Enterprise plan", "Premium", "Self-hosted". */
    public function label(): string
    {
        if (self::selfHosted() && $this->source !== self::SOURCE_CONTRACT) {
            return __('plans.name.self_hosted');
        }
        if ($this->definition !== null) {
            return $this->definition->name();
        }

        return __('plans.name.'.($this->hasExtras() ? 'paid' : 'free'));
    }
}
