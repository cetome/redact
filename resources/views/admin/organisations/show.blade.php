@php
    $state = $plan->subscriptionState();
    $ai = $plan->aiAllowance();
    $limits = $organisation->limits ?? [];
    $support = array_key_exists('support', $limits) ? ($limits['support'] ? 'yes' : 'no') : '';
    $paidOnStripe = $organisation->plan === 'free' && $plan->subscription?->valid();
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
            {{-- Super-admins only change a plan (OrganisationController::update): other staff read it. --}}
            <fieldset class="a-panel-body a-stack gap-3! a-fieldset" @disabled(! $canGrant)>
                <div class="grid gap-3 md:grid-cols-2">
                    <div class="a-field">
                        <label for="plan" class="a-label">{{ __('plans.admin.plan') }}</label>
                        {{-- What is in place: a plan granted here, else what Stripe pays for, else Free. Saving the
                             form as shown changes nothing. "free" = nothing granted here (the subscription, if any, decides). --}}
                        <select id="plan" name="plan" class="a-select" @if ($paidOnStripe) aria-describedby="plan-help" @endif>
                            <option value="free" @selected(old('plan', $organisation->plan) === 'free')>{{ $paidOnStripe ? __('plans.admin.plans.stripe', ['plan' => $plan->label()]) : __('plans.admin.plans.free') }}</option>
                            @foreach ($plans as $key => $definition)
                                <option value="{{ $key }}" @selected(old('plan', $organisation->plan) === $key)>{{ $definition->name() }}{{ $definition->active ? '' : ' ('.__('plans.admin.inactive').')' }}</option>
                            @endforeach
                        </select>
                        @if ($paidOnStripe)
                            <p id="plan-help" class="a-help">{{ __('plans.admin.stripe_help') }}</p>
                        @endif
                        @error('plan')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="a-field">
                        <label for="plan_ends_at" class="a-label">{{ __('plans.admin.ends_at') }}</label>
                        <input id="plan_ends_at" name="plan_ends_at" type="date" class="a-input" min="{{ now()->addDay()->toDateString() }}"
                               value="{{ old('plan_ends_at', $organisation->plan_ends_at?->isFuture() ? $organisation->plan_ends_at->toDateString() : '') }}"
                               aria-describedby="plan_ends_at-help" @error('plan_ends_at') aria-invalid="true" @enderror>
                        <p id="plan_ends_at-help" class="a-help">{{ __('plans.admin.ends_at_help') }}</p>
                        @error('plan_ends_at')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
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
            </fieldset>
            <div class="a-panel-foot">
                @if (! $canGrant)
                    <p class="a-help">{{ __('plans.admin.super_only') }}</p>
                @else
                <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-save aria-hidden="true" />{{ __('plans.admin.save') }}</button>
                @endif
                @if ($canGrant && $organisation->plan !== 'free' && $organisation->plan_ends_at !== null)
                    <button type="submit" form="end-trial" class="a-btn a-btn-outline a-btn-sm a-spacer"
                            x-data="confirmSubmit" data-confirm="{{ __('plans.admin.end_trial_confirm') }}" @click="check"><x-lucide-circle-x aria-hidden="true" />{{ __('plans.admin.end_trial') }}</button>
                @endif
            </div>
        </form>
        @if ($organisation->plan !== 'free' && $organisation->plan_ends_at !== null)
            <form id="end-trial" method="POST" action="{{ route('admin.organisations.trial.end', $organisation) }}" hidden>@csrf @method('DELETE')</form>
        @endif

        {{-- Billing state --}}
        <section class="a-panel" aria-labelledby="billing-heading">
            <div class="a-panel-head"><h2 id="billing-heading" class="a-h3">{{ __('plans.admin.details') }}</h2></div>
            <dl class="a-panel-body a-dl">
                <dt>{{ __('plans.billing.plan') }}</dt>
                <dd>
                    {{ $plan->label() }}
                    @if ($plan->onTrial())
                        <span class="a-row-sub">{{ __('plans.trial.until', ['date' => $plan->trialEndsAt->isoFormat('D MMMM YYYY')]) }}</span>
                    @elseif ($organisation->plan !== 'free' && $organisation->plan_ends_at?->isPast())
                        <span class="a-row-sub">{{ __('plans.trial.ended', ['date' => $organisation->plan_ends_at->isoFormat('D MMMM YYYY')]) }}</span>
                    @endif
                    @if ($plan->interval)
                        <span class="a-row-sub">{{ __('plans.interval.'.$plan->interval) }}</span>
                    @endif
                </dd>
                <dt>{{ __('plans.billing.products') }}</dt>
                <dd>{{ $plan->productsSummary() }}</dd>
                <dt>{{ __('plans.billing.subscription') }}</dt>
                <dd>
                    {{ __('plans.states.'.$state, ['date' => $plan->subscription?->ends_at?->isoFormat('D MMMM YYYY')]) }}
                    @if ($plan->subscription)
                        <span class="a-row-sub">{{ $plan->subscription->stripe_status }} · {{ trans_choice('plans.billing.quantity', $plan->paidSlots, ['count' => $plan->paidSlots]) }}</span>
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

        @if ($end !== null)
            {{-- End the subscription now, the account kept (super-admin, OrganisationController::endSubscription). --}}
            <form method="POST" action="{{ route('admin.organisations.subscription.end', $organisation) }}" data-recent-auth class="a-panel xl:col-span-2" aria-labelledby="end-heading"
                  x-data="confirmSubmit" data-confirm="{{ __('plans.admin.end.confirm') }}" @submit="check">
                @csrf
                @method('DELETE')
                <div class="a-panel-head"><h2 id="end-heading" class="a-h3">{{ __('plans.admin.end.title') }}</h2></div>
                <div class="a-panel-body a-stack gap-2!">
                    <p>{{ __('plans.admin.end.lead') }}</p>
                    @if ($end['stripeError'])
                        <p class="a-help">{{ __('plans.admin.end.stripe_error') }}</p>
                    @elseif ($end['refund'] === null)
                        <p class="a-help">{{ __('plans.admin.end.no_payment') }}</p>
                    @endif
                </div>
                <div class="a-panel-foot flex flex-wrap gap-2">
                    @if ($end['refund'] !== null)
                        <button type="submit" name="refund" value="1" class="a-btn a-btn-danger a-btn-sm">
                            <x-lucide-undo-2 aria-hidden="true" />{{ __('plans.admin.end.refund', $end['refund']) }}</button>
                    @endif
                    <button type="submit" name="refund" value="0" class="a-btn {{ $end['refund'] !== null ? 'a-btn-outline' : 'a-btn-danger' }} a-btn-sm">
                        <x-lucide-circle-x aria-hidden="true" />{{ __('plans.admin.end.no_refund') }}</button>
                </div>
            </form>
        @endif
    </div>

    <section class="a-section" aria-labelledby="products-heading">
        <h2 id="products-heading" class="a-h2 mb-2">{{ __('plans.admin.products') }}</h2>
        <div class="a-table-wrap">
            <table class="a-table a-table-compact">
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.products.name') }}</th>
                        <th scope="col">{{ __('plans.admin.product_slot') }}</th>
                        <th scope="col" class="max-md:hidden">{{ __('admin.products.owner') }}</th>
                        <th scope="col" class="max-md:hidden">{{ __('plans.admin.created') }}</th>
                        @if ($canGrant)<th scope="col"><span class="a-sr-only">{{ __('products.delete') }}</span></th>@endif
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
                            <td class="max-md:hidden">{{ $product->owner?->email ?? '—' }}</td>
                            <td class="is-nowrap a-muted max-md:hidden">{{ $product->created_at?->isoFormat('D MMMM YYYY') }}</td>
                            @if ($canGrant)
                                <td class="is-nowrap w-px text-right">
                                    @unless ($product->trashed())
                                        {{-- Super-admin: delete it, its slot goes back to the plan. --}}
                                        <form method="POST" action="{{ route('admin.products.destroy', $product) }}" data-recent-auth
                                              x-data="confirmSubmit" data-confirm="{{ __('plans.admin.delete_product_confirm', ['name' => $product->name]) }}" @submit="check">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="a-btn a-btn-ghost a-btn-sm a-btn-icon a-row-delete" title="{{ __('products.delete') }}" aria-label="{{ __('products.delete') }}"><x-lucide-trash-2 aria-hidden="true" /><span class="a-sr-only">{{ __('products.delete') }}</span></button>
                                        </form>
                                    @endunless
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canGrant ? 5 : 4 }}" class="a-muted">{{ __('admin.products.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
