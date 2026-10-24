{{--
    The four offers, shared by the home page and /pricing (one source): open source (the engine),
    web-based Free and Paid, self-hosted with cetome rules. Numbers from PublicController::plans()
    (config('redact.plans'), App\Billing\Plan): $price, $included, $free, $paid, $contactUrl, $billing, $pricingContactUrl, $openSourceUrl.
    Owner, 2026-10-23: free = 1 product without AI; paid = :price a month with 2 products included,
    +:price a month for each further product.
--}}
@php
    $members = (int) ($free['team_members'] ?? 1);
    $freeAi = (int) ($free['ai_units'] ?? 0);
    $paidAi = (int) ($paid['ai_units'] ?? 0);
    $freeProducts = (int) ($free['products'] ?? 1);
@endphp
<ul class="r-plans">
    {{-- Open source: the engine, self-hosted. --}}
    <li class="r-plan">
        <p class="r-plan-tag">{{ __('public.pricing.plans.open.tag') }}</p>
        <h3 class="r-plan-name"><x-lucide-code class="r-plan-icon" aria-hidden="true" />{{ __('public.pricing.plans.open.name') }}</h3>
        <p class="r-plan-price">{{ __('public.pricing.plans.open.price') }}</p>
        <ul class="r-plan-points">
            @foreach ((array) __('public.pricing.plans.open.points') as $point)
                <li><x-lucide-check aria-hidden="true" />{{ $point }}</li>
            @endforeach
        </ul>
        @if ($openSourceUrl !== '')
            <a href="{{ $openSourceUrl }}" class="c-btn c-btn-outline r-plan-cta">{{ __('public.pricing.plans.open.cta') }}</a>
        @else
            <span class="c-btn c-btn-outline r-plan-cta" aria-disabled="true">{{ __('public.pricing.plans.open.soon') }}</span>
        @endif
    </li>

    {{-- Web-based, free: the first product, no AI (from config). --}}
    <li class="r-plan">
        <p class="r-plan-tag">{{ __('public.pricing.plans.free.tag') }}</p>
        <h3 class="r-plan-name"><x-lucide-globe class="r-plan-icon" aria-hidden="true" />{{ __('public.pricing.plans.free.name') }}</h3>
        <p class="r-plan-price">{{ __('public.pricing.plans.free.price') }}</p>
        <ul class="r-plan-points">
            <li><x-lucide-check aria-hidden="true" />{{ trans_choice('public.pricing.plans.free.products', $freeProducts, ['count' => $freeProducts]) }}</li>
            @foreach ((array) __('public.pricing.plans.free.points') as $point)
                <li><x-lucide-check aria-hidden="true" />{{ $point }}</li>
            @endforeach
            <li><x-lucide-check aria-hidden="true" />{{ trans_choice('public.pricing.plans.free.team', $members, ['members' => $members]) }}</li>
            @if ($freeAi > 0)
                <li><x-lucide-check aria-hidden="true" />{{ __('public.pricing.cells.trial', ['units' => $freeAi]) }}</li>
            @else
                <li class="is-off"><x-lucide-minus aria-hidden="true" />{{ __('public.pricing.plans.free.no_ai') }}</li>
            @endif
        </ul>
        <x-account-link class="c-btn c-btn-outline r-plan-cta">{{ __('public.pricing.plans.free.cta') }}</x-account-link>
    </li>

    {{-- Web-based, paid: the price a month with $included products (highlighted: full border and tint). --}}
    <li class="r-plan is-featured">
        <p class="r-plan-tag">{{ __('public.pricing.plans.paid.tag') }}</p>
        <h3 class="r-plan-name"><x-lucide-sparkles class="r-plan-icon" aria-hidden="true" />{{ __('public.pricing.plans.paid.name') }}</h3>
        <p class="r-plan-price">{{ $price }}
            <span class="r-plan-unit">{{ __('public.pricing.per_month') }} · {{ __('public.pricing.excl_vat') }}</span></p>
        <ul class="r-plan-points">
            <li><x-lucide-check aria-hidden="true" />{{ __('public.pricing.plans.paid.included', ['count' => $included]) }}</li>
            <li><x-lucide-plus aria-hidden="true" />{{ __('public.pricing.plans.paid.more', ['price' => $price]) }}</li>
            <li><x-lucide-check aria-hidden="true" />{{ __('public.pricing.plans.paid.ai', ['units' => $paidAi]) }}</li>
            @foreach ((array) __('public.pricing.plans.paid.points') as $point)
                <li><x-lucide-check aria-hidden="true" />{{ $point }}</li>
            @endforeach
            @if ($billing)
                <li><x-lucide-check aria-hidden="true" />{{ __('public.pricing.plans.paid.checkout') }}</li>
            @endif
        </ul>
        {{-- Without Stripe (no check-out yet): "Talk to us" on the pricing topic of the contact form. --}}
        @if ($billing)
            <x-account-link class="c-btn c-btn-primary r-plan-cta" :account-label="__('public.pricing.plans.paid.cta_account')" :account-href="route('products.create')">{{ __('public.pricing.plans.paid.cta') }}</x-account-link>
        @else
            <a href="{{ $pricingContactUrl }}" class="c-btn c-btn-primary r-plan-cta">{{ __('plans.talk') }}</a>
        @endif
    </li>

    {{-- Self-hosted with the cetome rules: on request. --}}
    <li class="r-plan">
        <p class="r-plan-tag">{{ __('public.pricing.plans.self.tag') }}</p>
        <h3 class="r-plan-name"><x-lucide-server class="r-plan-icon" aria-hidden="true" />{{ __('public.pricing.plans.self.name') }}</h3>
        <p class="r-plan-price">{{ __('public.pricing.plans.self.price') }}</p>
        <ul class="r-plan-points">
            @foreach ((array) __('public.pricing.plans.self.points') as $point)
                <li><x-lucide-check aria-hidden="true" />{{ $point }}</li>
            @endforeach
        </ul>
        <a href="{{ $contactUrl }}" class="c-btn c-btn-outline r-plan-cta">{{ __('public.pricing.plans.self.cta') }}</a>
    </li>
</ul>
