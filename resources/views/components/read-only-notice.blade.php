{{-- Why the page cannot be edited: support access, the demo product, a paid product no longer covered by the subscription or a free product beyond the plan, reviewer, or a version that is not a draft. --}}
@props(['access', 'version' => null])
@if ($access->isSupportAccess())
    <div class="a-alert a-alert-warning"><x-lucide-eye aria-hidden="true" /><span>{{ __('common.support_access') }}</span></div>
@elseif ($access->isDemo())
    <div class="a-alert" role="status"><x-lucide-lock aria-hidden="true" /><span>{{ __('products.demo.read_only') }}</span></div>
@elseif ($access->isBillingLocked())
    <div class="a-alert a-alert-warning" role="status">
        <x-lucide-lock aria-hidden="true" />
        <span>{{ $access->overPlan ? __('plans.locked.over_banner') : __('plans.locked.banner') }}
            @if ($access->orgAdmin)
                @if ($access->overPlan)
                    <a href="{{ \App\Billing\Plan::billingEnabled() ? route('billing.show', $access->billingLocked) : \App\Billing\Plan::contactUrl() }}" class="a-link-btn">{{ \App\Billing\Plan::billingEnabled() ? __('plans.locked.add') : __('plans.talk') }}</a>
                @else
                    <a href="{{ route('billing.show', $access->billingLocked) }}" class="a-link-btn">{{ __('plans.locked.reactivate') }}</a>
                @endif
            @else
                {{ __('plans.locked.ask_admin') }}
            @endif
        </span>
    </div>
@elseif ($version && ! $version->isEditable() && $access->canEdit())
    <div class="a-alert"><x-lucide-lock aria-hidden="true" /><span>{{ __('versions.locked', ['status' => mb_strtolower($version->status->label())]) }}</span></div>
@endif
