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
 * What an organisation's plan allows (config redact.plans, staff overrides in organisations.limits).
 *
 *  - The FIRST product is free forever: owner + 1 team member, a one-time AI trial, full rules,
 *    downloads and imports (never limited by a plan).
 *  - Each additional product is either PAID (Stripe subscription "products", one unit per
 *    product) or GRANTED by cetome (override `extra_products`, e.g. partners): unlimited team,
 *    a monthly AI allowance per product, pooled at organisation level, support.
 *  - `enterprise` (custom contract, set by staff): no product or team limit.
 *
 * A product records the allowance it was created on (products.plan_slot). Products created
 * before plans existed (null) are grandfathered: never read-only, unlimited team; they use the
 * free slot first, so only NEW creations beyond the limits are refused. When the subscription
 * ends (cancelled, unpaid) or shrinks, the paid products it no longer covers become read-only
 * (newest first), never deleted; the free product stays editable.
 */
final class Plan
{
    public const FREE = 'free';

    public const ENTERPRISE = 'enterprise';

    /** @var list<string> */
    public const PLANS = [self::FREE, self::ENTERPRISE];

    public const SLOT_FREE = 'free';

    public const SLOT_GRANTED = 'granted';

    public const SLOT_PAID = 'paid';

    /** The demo product an account loads (App\Guest\DemoProduct, products.is_demo): never counted, never locked. */
    public const SLOT_DEMO = 'demo';

    /** @var list<string> */
    public const PERIODS = ['once', 'day', 'month', 'year'];

    /** Keys staff may override per organisation (admin console). */
    public const OVERRIDES = ['extra_products', 'team_members', 'ai_units', 'ai_period', 'support'];

    /**
     * @param  list<string>  $locked  ids of the products the plan no longer covers (read-only)
     */
    private function __construct(
        public readonly Organisation $organisation,
        public readonly string $plan,
        public readonly int $freeSlots,
        public readonly int $grantedSlots,
        public readonly int $paidSlots,
        public readonly int $freeUsed,
        public readonly int $grantedUsed,
        public readonly int $paidUsed,
        public readonly ?Subscription $subscription,
        public readonly array $locked,
        public readonly int $grandfathered = 0,
        public readonly int $coveredGranted = 0,
        public readonly int $coveredPaid = 0,
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

        $plan = in_array($organisation->plan, self::PLANS, true) ? $organisation->plan : self::FREE;
        $subscription = Subscription::query()
            ->where('organisation_id', $organisation->getKey())
            ->where('type', (string) config('redact.billing.subscription', 'products'))
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $paidSlots = $subscription?->valid() ? max(0, (int) $subscription->quantity) : 0;

        /** @var Collection<int, Product> $products */
        // The demo product (is_demo) takes no slot, never counts and is never locked.
        $products = $organisation->products()->notDemo()->orderBy('created_at')->orderBy('id')->get(['id', 'plan_slot', 'created_at']);
        $granted = $products->where('plan_slot', self::SLOT_GRANTED)->values();
        $paid = $products->where('plan_slot', self::SLOT_PAID)->values();
        $grantedSlots = max(0, (int) (self::override($organisation, 'extra_products') ?? 0));

        // Free products beyond the free allowance (e.g. a sandbox merged into an account that already
        // has its free product): a spare granted or paid slot covers them, otherwise the newest are
        // read-only like any product beyond its plan. Grandfathered products (no slot) use the free
        // allowance first and are never locked.
        $freeSlots = max(0, (int) config('redact.plans.free.products', 1));
        $grandfathered = $products->filter(fn (Product $p) => $p->plan_slot === null)->count();
        $free = $products->where('plan_slot', self::SLOT_FREE)->values();
        $freeAllowance = max(0, $freeSlots - $grandfathered);
        $excess = max(0, $free->count() - $freeAllowance);
        $coveredGranted = $plan === self::ENTERPRISE ? 0 : min($excess, max(0, $grantedSlots - $granted->count()));
        $coveredPaid = $plan === self::ENTERPRISE ? 0 : min($excess - $coveredGranted, max(0, $paidSlots - $paid->count()));

        // Enterprise covers everything; otherwise the newest products beyond their allowance are read-only.
        $locked = $plan === self::ENTERPRISE ? [] : array_values(array_merge(
            $free->slice($freeAllowance + $coveredGranted + $coveredPaid)->pluck('id')->all(),
            $granted->slice($grantedSlots)->pluck('id')->all(),
            $paid->slice($paidSlots)->pluck('id')->all(),
        ));

        return new self(
            organisation: $organisation,
            plan: $plan,
            freeSlots: $freeSlots,
            grantedSlots: $grantedSlots,
            paidSlots: $paidSlots,
            freeUsed: $products->filter(fn (Product $p) => $p->plan_slot === null || $p->plan_slot === self::SLOT_FREE)->count(),
            grantedUsed: $granted->count(),
            paidUsed: $paid->count(),
            subscription: $subscription,
            locked: $locked,
            grandfathered: $grandfathered,
            coveredGranted: $coveredGranted,
            coveredPaid: $coveredPaid,
        );
    }

    /** Stripe is set up (keys and the product price): self-service paid products. */
    public static function billingEnabled(): bool
    {
        return (string) config('cashier.key') !== ''
            && (string) config('cashier.secret') !== ''
            && (string) config('redact.billing.price') !== '';
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

    public static function priceLabel(): string
    {
        return (string) config('redact.plans.paid.price_label', '100 €');
    }

    /** A staff override, or null when the config default applies. */
    public static function override(Organisation $organisation, string $key): mixed
    {
        $limits = is_array($organisation->limits) ? $organisation->limits : [];

        return $limits[$key] ?? null;
    }

    public function isEnterprise(): bool
    {
        return $this->plan === self::ENTERPRISE;
    }

    /** Has the organisation paid or granted products (unlimited team, monthly AI, support)? */
    public function hasExtras(): bool
    {
        return $this->isEnterprise() || $this->grantedSlots + $this->paidSlots > 0;
    }

    /** The allowance the next product is created on (free first, then granted, then paid), null when none is left. */
    public function nextSlot(): ?string
    {
        if ($this->freeUsed < $this->freeSlots) {
            return self::SLOT_FREE;
        }
        // Slots already covering extra free products are taken.
        if ($this->isEnterprise() || $this->grantedUsed + $this->coveredGranted < $this->grantedSlots) {
            return self::SLOT_GRANTED;
        }
        if ($this->paidUsed + $this->coveredPaid < $this->paidSlots) {
            return self::SLOT_PAID;
        }

        return null;
    }

    public function productLimitReached(): bool
    {
        return $this->nextSlot() === null;
    }

    public function productCount(): int
    {
        return $this->freeUsed + $this->grantedUsed + $this->paidUsed;
    }

    /** Products the plan holds (null = no limit). Grandfathered products above it stay. */
    public function productLimit(): ?int
    {
        return $this->isEnterprise() ? null : max($this->grandfathered, $this->freeSlots) + $this->grantedSlots + $this->paidSlots;
    }

    /** Paid product slots not used by a product yet (bought, product not created). */
    public function unusedPaidSlots(): int
    {
        return max(0, $this->paidSlots - $this->paidUsed - $this->coveredPaid);
    }

    /** Paid products the subscription no longer covers (what "Reactivate" pays for again). */
    public function lockedPaidCount(): int
    {
        return $this->isEnterprise() ? 0 : max(0, $this->paidUsed - $this->paidSlots);
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

    /** Can an organisation admin buy another product slot in Stripe right now? */
    public function canBuy(): bool
    {
        return self::billingEnabled() && ! $this->isEnterprise()
            && in_array($this->subscriptionState(), ['none', 'ended', 'active'], true);
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
            ? __('plans.refusal.products_pay', ['price' => self::priceLabel()])
            : trans_choice('plans.refusal.products', max(1, $count), ['count' => max(1, $count)]);
    }

    // ---- Team -------------------------------------------------------------------------------

    /** People a product's team may hold besides its owner (null = no limit): only the free product is limited. */
    public function teamLimit(Product $product): ?int
    {
        if ($this->isEnterprise() || $product->plan_slot !== self::SLOT_FREE) {
            return null;
        }
        $override = self::override($this->organisation, 'team_members');

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
     * The organisation's AI units: a one-time trial on the free product alone, a monthly
     * allowance per paid or granted product (pooled), or the enterprise allowance. "own": not
     * limited by the plan: a self-hosted install (its own AI key, no staff AI override), or an
     * organisation answered by its own key or model (App\Ai\OrganisationAi: paid and Enterprise on the SaaS).
     *
     * @return array{units: int, period: string, used: int, left: int, trial: bool, renews: ?CarbonInterface, own: bool}
     */
    public function aiAllowance(): array
    {
        if ($this->isEnterprise()) {
            $units = (int) config('redact.plans.enterprise.ai_units', 5000);
            $period = (string) config('redact.plans.enterprise.ai_period', 'month');
        } elseif ($this->grantedSlots + $this->paidSlots > 0) {
            $units = (int) config('redact.plans.paid.ai_units', 500) * ($this->grantedSlots + $this->paidSlots);
            $period = (string) config('redact.plans.paid.ai_period', 'month');
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

    /** Does the plan include AI at all (free plan: no; paid, granted, enterprise or a staff override: yes)? */
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

        return (bool) ($this->hasExtras() ? config('redact.plans.paid.support', true) : config('redact.plans.free.support', false));
    }

    /** "Free plan", "Paid plan", "Enterprise plan". */
    public function label(): string
    {
        return __('plans.name.'.($this->isEnterprise() ? 'enterprise' : ($this->hasExtras() ? 'paid' : 'free')));
    }
}
