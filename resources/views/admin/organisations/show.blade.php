@php
    $state = $plan->subscriptionState();
    $ai = $plan->aiAllowance();
    $limits = $organisation->limits ?? [];
    $support = array_key_exists('support', $limits) ? ($limits['support'] ? 'yes' : 'no') : '';
@endphp
<x-layouts.app :title="$organisation->name" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="$organisation->name" :subtitle="$plan->label()">
        <x-slot:eyebrow><a href="{{ route('admin.organisations.index') }}">← {{ __('plans.admin.back') }}</a></x-slot:eyebrow>
    </x-ui.page-header>

    @unless ($billingEnabled)
        <div class="a-alert" role="status"><x-lucide-info aria-hidden="true" /><span>{{ __('plans.admin.billing_off') }}</span></div>
    @endunless

    <div class="grid items-start gap-4 xl:grid-cols-3">
        {{-- Plan and overrides (logged) --}}
        <form method="POST" action="{{ route('admin.organisations.plan', $organisation) }}" class="a-panel xl:col-span-2" aria-labelledby="plan-heading">
            @csrf
            @method('PUT')
            <div class="a-panel-head"><h2 id="plan-heading" class="a-h3">{{ __('plans.admin.overrides') }}</h2></div>
            <div class="a-panel-body a-stack gap-3!">
                <div class="a-field">
                    <label for="plan" class="a-label">{{ __('plans.admin.plan') }}</label>
                    <select id="plan" name="plan" class="a-select">
                        @foreach (\App\Billing\Plan::PLANS as $value)
                            <option value="{{ $value }}" @selected(old('plan', $organisation->plan) === $value)>{{ __('plans.admin.plans.'.$value) }}</option>
                        @endforeach
                    </select>
                    @error('plan')<p class="a-error">{{ $message }}</p>@enderror
                </div>
                <div class="a-plan-form">
                    @foreach (['extra_products', 'team_members', 'ai_units'] as $field)
                        <div class="a-field">
                            <label for="{{ $field }}" class="a-label">{{ __('plans.admin.fields.'.$field) }}</label>
                            <input id="{{ $field }}" name="{{ $field }}" type="number" min="0" class="a-input a-input-sm"
                                value="{{ old($field, $limits[$field] ?? '') }}" placeholder="{{ __('plans.admin.default') }}" @error($field) aria-invalid="true" @enderror>
                            @error($field)<p class="a-error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                    <div class="a-field">
                        <label for="ai_period" class="a-label">{{ __('plans.admin.fields.ai_period') }}</label>
                        <select id="ai_period" name="ai_period" class="a-select a-input-sm">
                            <option value="">{{ __('plans.admin.default') }}</option>
                            @foreach (\App\Billing\Plan::PERIODS as $period)
                                <option value="{{ $period }}" @selected(old('ai_period', $limits['ai_period'] ?? '') === $period)>{{ __('plans.periods.'.$period) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="a-field">
                        <label for="support" class="a-label">{{ __('plans.admin.fields.support') }}</label>
                        <select id="support" name="support" class="a-select a-input-sm">
                            <option value="">{{ __('plans.admin.default') }}</option>
                            <option value="yes" @selected(old('support', $support) === 'yes')>{{ __('plans.admin.yes') }}</option>
                            <option value="no" @selected(old('support', $support) === 'no')>{{ __('plans.admin.no') }}</option>
                        </select>
                    </div>
                </div>
                <p class="a-help">{{ __('plans.admin.overrides_help') }}</p>
            </div>
            <div class="a-panel-foot">
                <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-save aria-hidden="true" />{{ __('plans.admin.save') }}</button>
            </div>
        </form>

        {{-- Billing state --}}
        <section class="a-panel" aria-labelledby="billing-heading">
            <div class="a-panel-head"><h2 id="billing-heading" class="a-h3">{{ __('plans.admin.details') }}</h2></div>
            <dl class="a-panel-body a-dl">
                <dt>{{ __('plans.billing.products') }}</dt>
                <dd>{{ __('plans.billing.products_value', ['free' => $plan->freeUsed, 'paid' => $plan->paidUsed, 'granted' => $plan->grantedUsed]) }}</dd>
                <dt>{{ __('plans.billing.subscription') }}</dt>
                <dd>
                    {{ __('plans.states.'.$state, ['date' => $plan->subscription?->ends_at?->isoFormat('D MMMM YYYY')]) }}
                    @if ($plan->subscription)
                        <span class="a-row-sub">{{ $plan->subscription->stripe_status }} · {{ trans_choice('plans.billing.quantity', (int) $plan->subscription->quantity, ['count' => (int) $plan->subscription->quantity]) }}</span>
                    @endif
                </dd>
                @if ($plan->locked !== [])
                    <dt>{{ __('plans.locked.badge') }}</dt>
                    <dd>{{ trans_choice('plans.billing.locked', count($plan->locked), ['count' => count($plan->locked)]) }}</dd>
                @endif
                <dt>{{ __('plans.billing.ai') }}</dt>
                <dd>{{ $ai['own'] ? __('plans.ai.own_key') : ($ai['units'] === 0 ? __('plans.ai.not_included') : __('plans.billing.ai_value', ['left' => $ai['left'], 'units' => $ai['units'], 'period' => __('plans.periods.'.$ai['period'])])) }}</dd>
                <dt>{{ __('plans.billing.support') }}</dt>
                <dd>{{ $plan->support() ? __('plans.billing.support_yes') : __('plans.billing.support_no') }}</dd>
                <dt>{{ __('plans.admin.stripe_customer') }}</dt>
                <dd class="font-mono">{{ $organisation->stripe_id ?? '—' }}</dd>
                <dt>{{ __('plans.admin.members') }}</dt>
                <dd>
                    @foreach ($organisation->members as $member)
                        <a href="{{ route('admin.users.show', $member) }}" class="a-link-btn block">{{ $member->email }}</a>
                    @endforeach
                </dd>
            </dl>
        </section>
    </div>

    <section class="a-section" aria-labelledby="products-heading">
        <h2 id="products-heading" class="a-h2 mb-2">{{ __('plans.admin.products') }}</h2>
        <div class="a-table-wrap">
            <table class="a-table a-table-compact">
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.products.name') }}</th>
                        <th scope="col">{{ __('plans.admin.product_slot') }}</th>
                        <th scope="col">{{ __('admin.products.owner') }}</th>
                        <th scope="col">{{ __('plans.admin.created') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr @class(['is-muted' => $product->trashed()])>
                            <th scope="row">
                                @if ($product->trashed())
                                    {{ $product->name }}
                                @else
                                    <a href="{{ route('products.show', $product) }}" class="a-row-link">{{ $product->name }}</a>
                                @endif
                            </th>
                            <td>
                                <span class="a-badge a-badge-neutral">{{ __('plans.slots.'.($product->plan_slot ?? 'legacy')) }}</span>
                                @if (! $product->trashed() && $plan->isLocked($product))
                                    <span class="a-badge a-badge-warning"><x-lucide-lock aria-hidden="true" />{{ __('plans.locked.badge') }}</span>
                                @endif
                            </td>
                            <td>{{ $product->owner?->email ?? '—' }}</td>
                            <td class="is-nowrap a-muted">{{ $product->created_at?->isoFormat('D MMMM YYYY') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="a-muted">{{ __('admin.products.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
