{{--
    New product when the organisation's plan has no product left (App\Billing\Plan):
    Stripe Checkout for the first paid product, a confirmed extra unit on an existing
    subscription, or "Talk to us" when billing is off. Nothing is lost: nothing was created.
--}}
@php
    $price = \App\Billing\Plan::priceLabel();
    $state = $plan->subscriptionState();
@endphp
<x-layouts.app :title="__('plans.add.title')">
    <x-ui.page-header :title="__('plans.add.title')" :subtitle="__('plans.add.lead', ['price' => $price])">
        <x-slot:eyebrow>{{ __('plans.add.organisation', ['name' => $organisation->name]) }}</x-slot:eyebrow>
    </x-ui.page-header>

    <div class="a-grid-main">
        <div class="a-stack min-w-0">
            @if ($plan->canBuy() && $isAdmin && $plan->hasActiveSubscription())
                {{-- A subscription exists: confirm the extra monthly price, then one more unit. --}}
                <form method="POST" action="{{ route('billing.products.add', $organisation) }}" class="a-panel">
                    @csrf
                    <div class="a-panel-head"><h2 class="a-h3">{{ __('plans.add.confirm_title') }}</h2></div>
                    <div class="a-panel-body a-stack gap-3!">
                        <p>{{ trans_choice('plans.add.confirm_text', $plan->paidSlots, ['price' => $price, 'count' => $plan->paidSlots]) }}</p>
                        <label class="a-check">
                            <input type="checkbox" name="confirm" value="1" required @error('confirm') aria-invalid="true" @enderror>
                            <span>{{ __('plans.add.confirm_check', ['price' => $price]) }}</span>
                        </label>
                        @error('confirm')<p class="a-error">{{ $message }}</p>@enderror
                        @include('billing._withdrawal')
                    </div>
                    <div class="a-panel-foot">
                        <button type="submit" class="a-btn a-btn-primary"><x-lucide-plus aria-hidden="true" />{{ __('plans.add.confirm') }}</button>
                        <a href="{{ route('billing.show', $organisation) }}" class="a-btn a-btn-ghost">{{ __('plans.billing.title') }}</a>
                    </div>
                </form>
            @elseif ($plan->canBuy() && $isAdmin)
                {{-- No subscription yet (or it ended): Stripe Checkout, then back here with the product available. --}}
                <form method="POST" action="{{ route('billing.checkout', $organisation) }}" class="a-panel">
                    @csrf
                    <div class="a-panel-head"><h2 class="a-h3">{{ __('plans.refusal.products_pay', ['price' => $price]) }}</h2></div>
                    <div class="a-panel-body a-stack gap-3!">
                        <p class="a-help">{{ __('plans.add.checkout_help') }}</p>
                        @include('billing._withdrawal')
                    </div>
                    <div class="a-panel-foot">
                        <button type="submit" class="a-btn a-btn-primary"><x-lucide-credit-card aria-hidden="true" />{{ __('plans.add.checkout') }}</button>
                        <span class="a-help">{{ $price }} / {{ __('plans.periods.month') }}</span>
                    </div>
                </form>
            @elseif ($state === 'past_due' && $isAdmin && \App\Billing\Plan::billingEnabled())
                <div class="a-alert a-alert-warning" role="status">
                    <x-lucide-credit-card aria-hidden="true" />
                    <span>{{ __('plans.add.past_due') }} <a href="{{ route('billing.show', $organisation) }}" class="a-link-btn">{{ __('plans.billing.title') }}</a></span>
                </div>
            @elseif (\App\Billing\Plan::billingEnabled() && ! $plan->isEnterprise() && ! $isAdmin)
                <div class="a-alert" role="status"><x-lucide-info aria-hidden="true" /><span>{{ $plan->productRefusal() }} {{ __('plans.add.not_admin') }}</span></div>
            @else
                {{-- Billing off (no Stripe keys) or a custom plan: cetome sets it up. --}}
                <section class="a-panel">
                    <div class="a-panel-body a-stack gap-2!">
                        <p class="a-plan-hint"><x-lucide-package aria-hidden="true" /><span>{{ $plan->productRefusal() }}</span></p>
                        <p class="a-help">{{ __('plans.add.contact_lead') }}</p>
                    </div>
                    <div class="a-panel-foot">
                        <a href="{{ \App\Billing\Plan::contactUrl() }}" class="a-btn a-btn-primary" rel="noopener"><x-lucide-message-circle aria-hidden="true" />{{ __('plans.talk') }}</a>
                    </div>
                </section>
            @endif

            @include('plans._line', ['plan' => $plan])
        </div>

        <aside class="a-panel self-start" aria-labelledby="includes-title">
            <div class="a-panel-head"><h2 class="a-h3" id="includes-title">{{ __('plans.add.includes') }}</h2></div>
            <ul class="a-panel-body a-plan-features">
                <li><x-lucide-users aria-hidden="true" />{{ __('plans.add.team') }}</li>
                <li><x-lucide-sparkles aria-hidden="true" />{{ __('plans.add.ai', ['units' => (int) config('redact.plans.paid.ai_units')]) }}</li>
                <li><x-lucide-life-buoy aria-hidden="true" />{{ __('plans.add.support') }}</li>
                <li><x-lucide-download aria-hidden="true" />{{ __('plans.add.rules') }}</li>
            </ul>
        </aside>
    </div>
</x-layouts.app>
