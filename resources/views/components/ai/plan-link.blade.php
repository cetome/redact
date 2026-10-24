{{--
    "AI comes with a paid plan": the way to turn it on, for an organisation admin only (Plan and
    billing, or "Talk to us" when billing is off). Renders nothing when the plan is not the reason.
    <x-ai.plan-link :scope="$version" />  (a ProductVersion or an Organisation; default: the person's first admin organisation)
--}}
@props(['scope' => null])
@php
    $planUser = auth()->user();
    $planScope = $scope ?? $planUser?->organisations()->wherePivot('role', 'admin')->first();
    $planUrl = $planScope ? \App\Ai\Availability::upgradeUrl($planUser, $planScope) : null;
@endphp
@if ($planUrl)
    <a href="{{ $planUrl }}" {{ $attributes->class(['a-link-btn']) }}>{{ $planUrl === \App\Billing\Plan::contactUrl() ? __('plans.talk') : __('plans.ai.add_product') }}</a>
@endif
