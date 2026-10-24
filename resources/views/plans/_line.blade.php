{{--
    One-line plan summary of an organisation (dashboard, account):
    "Free plan · 1 of 1 product · 37 AI units left · Talk to us to upgrade".
    Expects $plan (App\Billing\Plan); the link goes to Plan and billing for an organisation admin.
--}}
@php
    $organisation = $plan->organisation;
    $limit = $plan->productLimit();
    $ai = $plan->aiAllowance();
    $isAdmin = auth()->check() && $organisation->isAdmin(auth()->user());
@endphp
<p class="a-plan-line">
    <x-lucide-badge-check aria-hidden="true" />
    <span class="a-plan-line-name">{{ $plan->label() }}</span>
    @if (! empty($showOrganisation))
        <span class="a-plan-line-sep" aria-hidden="true">·</span><span>{{ $organisation->name }}</span>
    @endif
    <span class="a-plan-line-sep" aria-hidden="true">·</span>
    <span>{{ $limit === null ? trans_choice('plans.line.products_unlimited', $plan->productCount(), ['count' => $plan->productCount()]) : trans_choice('plans.line.products', $limit, ['used' => $plan->productCount(), 'limit' => $limit]) }}</span>
    <span class="a-plan-line-sep" aria-hidden="true">·</span>
    @if ($ai['own'])
        {{-- Self-hosted install: its own AI key and model, not limited by a plan. --}}
        <span>{{ __('plans.ai.own_key') }}</span>
    @elseif ($ai['units'] === 0)
        {{-- Free plan: no AI (it comes with a paid product). --}}
        <span title="{{ __('plans.ai.paid_only') }}">{{ __('plans.ai.none') }}</span>
    @else
        <span title="{{ __('plans.line.ai_help', ['left' => $ai['left'], 'units' => $ai['units'], 'period' => __('plans.periods.'.$ai['period'])]) }}">{{ __('plans.line.ai_left', ['left' => $ai['left']]) }}</span>
    @endif
    @if ($plan->support())
        <span class="a-plan-line-sep" aria-hidden="true">·</span><span>{{ __('plans.line.support') }}</span>
    @endif
    {{-- Enterprise and a self-hosted install have nothing to upgrade: no link. --}}
    @unless ($plan->unlimited())
        <span class="a-plan-line-sep" aria-hidden="true">·</span>
        @if ($isAdmin && \App\Billing\Plan::billingEnabled())
            <a href="{{ route('billing.show', $organisation) }}" class="a-link-btn">{{ __('plans.line.billing') }}</a>
        @else
            <a href="{{ \App\Billing\Plan::contactUrl() }}" class="a-link-btn" rel="noopener">{{ __('plans.line.talk') }}</a>
        @endif
    @endunless
</p>
