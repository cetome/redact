{{-- Plan and billing of an organisation (admins): summary, products and their allowance, next invoice, invoices, Stripe portal. --}}
@php
    $price = \App\Billing\Plan::priceLabel();
    $state = $plan->subscriptionState();
    $ai = $plan->aiAllowance();
@endphp
<x-layouts.app :title="__('plans.billing.title')">
    <x-ui.page-header :title="__('plans.billing.title')" :subtitle="__('plans.billing.lead', ['organisation' => $organisation->name, 'price' => $price])">
        <x-slot:eyebrow>{{ $organisation->name }}</x-slot:eyebrow>
        <x-slot:actions>
            @if ($billingEnabled && $organisation->hasStripeId())
                <form method="POST" action="{{ route('billing.portal', $organisation) }}">
                    @csrf
                    <button type="submit" class="a-btn a-btn-outline"><x-lucide-external-link aria-hidden="true" />{{ __('plans.billing.manage') }}</button>
                </form>
            @endif
            <a href="{{ route('products.create') }}" class="a-btn a-btn-primary"><x-lucide-plus aria-hidden="true" />{{ __('plans.billing.add') }}</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $billingEnabled)
        <div class="a-alert" role="status"><x-lucide-info aria-hidden="true" /><span>{{ __('plans.billing.disabled') }} <a href="{{ \App\Billing\Plan::contactUrl() }}" class="a-link-btn" rel="noopener">{{ __('plans.talk') }}</a></span></div>
    @elseif ($state === 'past_due')
        <div class="a-alert a-alert-warning" role="status"><x-lucide-credit-card aria-hidden="true" /><span>{{ __('plans.add.past_due') }}</span></div>
    @elseif ($plan->locked !== [])
        <div class="a-alert a-alert-warning" role="status"><x-lucide-lock aria-hidden="true" /><span>{{ __('plans.locked.banner') }}</span></div>
    @endif
    @if ($stripeError)
        <div class="a-alert a-alert-warning" role="status"><x-lucide-cloud-off aria-hidden="true" /><span>{{ __('plans.billing.stripe_unreachable') }}</span></div>
    @endif

    <div class="a-grid-main">
        <div class="a-stack min-w-0">
            @if ($plan->lockedPaidCount() > 0 && $plan->canBuy())
                {{-- Paid products the subscription no longer covers: pay for them again. --}}
                <form method="POST" action="{{ route('billing.reactivate', $organisation) }}" class="a-panel" aria-labelledby="reactivate-title">
                    @csrf
                    <div class="a-panel-head"><h2 class="a-h3" id="reactivate-title">{{ __('plans.billing.reactivate_title') }}</h2></div>
                    <div class="a-panel-body a-stack gap-3!">
                        <p class="a-plan-hint"><x-lucide-lock aria-hidden="true" /><span>{{ trans_choice('plans.billing.reactivate_text', $plan->lockedPaidCount(), ['count' => $plan->lockedPaidCount(), 'price' => $price]) }}</span></p>
                        @if ($plan->hasActiveSubscription())
                            <label class="a-check">
                                <input type="checkbox" name="confirm" value="1" required>
                                <span>{{ __('plans.billing.reactivate_check', ['price' => $price]) }}</span>
                            </label>
                            @error('confirm')<p class="a-error">{{ $message }}</p>@enderror
                        @else
                            <p class="a-help">{{ __('plans.add.checkout_help') }}</p>
                        @endif
                        @include('billing._withdrawal')
                    </div>
                    <div class="a-panel-foot">
                        <button type="submit" class="a-btn a-btn-primary"><x-lucide-rotate-ccw aria-hidden="true" />{{ __('plans.locked.reactivate') }}</button>
                    </div>
                </form>
            @endif

            <section class="a-panel" aria-labelledby="summary-title">
                <div class="a-panel-head"><h2 class="a-h3" id="summary-title">{{ __('plans.billing.summary') }}</h2></div>
                <dl class="a-panel-body a-dl">
                    <dt>{{ __('plans.billing.plan') }}</dt>
                    <dd>{{ $plan->label() }}</dd>
                    <dt>{{ __('plans.billing.products') }}</dt>
                    <dd>
                        {{ __('plans.billing.products_value', ['free' => $plan->freeUsed, 'paid' => $plan->paidUsed, 'granted' => $plan->grantedUsed]) }}
                        @if ($plan->unusedPaidSlots() > 0)
                            <span class="a-row-sub">{{ trans_choice('plans.billing.unused', $plan->unusedPaidSlots(), ['count' => $plan->unusedPaidSlots()]) }}</span>
                        @endif
                        @if ($plan->locked !== [])
                            <span class="a-row-sub">{{ trans_choice('plans.billing.locked', count($plan->locked), ['count' => count($plan->locked)]) }}</span>
                        @endif
                    </dd>
                    <dt>{{ __('plans.billing.subscription') }}</dt>
                    <dd>
                        {{ __('plans.states.'.$state, ['date' => $plan->subscription?->ends_at?->isoFormat('D MMMM YYYY')]) }}
                        @if ($plan->subscription?->valid())
                            · {{ trans_choice('plans.billing.quantity', (int) $plan->subscription->quantity, ['count' => (int) $plan->subscription->quantity]) }}
                        @endif
                    </dd>
                    @if ($upcoming)
                        <dt>{{ __('plans.billing.next_invoice') }}</dt>
                        <dd>{{ __('plans.billing.next_invoice_value', ['amount' => $upcoming->total(), 'date' => $upcoming->date()->isoFormat('D MMMM YYYY')]) }}</dd>
                    @endif
                    <dt>{{ __('plans.billing.ai') }}</dt>
                    <dd>{{ $ai['own'] ? __('plans.ai.own_key') : ($ai['units'] === 0 ? __('plans.ai.not_included') : __('plans.billing.ai_value', ['left' => $ai['left'], 'units' => $ai['units'], 'period' => __('plans.periods.'.$ai['period'])])) }}</dd>
                    <dt>{{ __('plans.billing.support') }}</dt>
                    <dd>{{ $plan->support() ? __('plans.billing.support_yes') : __('plans.billing.support_no') }}</dd>
                </dl>
                @if ($billingEnabled && $organisation->hasStripeId())
                    <p class="a-panel-foot a-help">{{ __('plans.billing.manage_help') }}</p>
                @endif
            </section>

            <section class="a-table-wrap" aria-labelledby="products-title">
                <table class="a-table a-table-compact">
                    <caption id="products-title" class="a-sr-only">{{ __('plans.billing.your_products') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('products.product') }}</th>
                            <th scope="col">{{ __('plans.admin.product_slot') }}</th>
                            <th scope="col">{{ __('plans.admin.created') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products as $product)
                            <tr>
                                <th scope="row"><a href="{{ route('products.show', $product) }}" class="a-row-link" data-row-link>{{ $product->name }}</a></th>
                                <td>
                                    <span class="a-badge a-badge-neutral">{{ __('plans.slots.'.($product->plan_slot ?? 'legacy')) }}</span>
                                    @if ($plan->isLocked($product))
                                        <span class="a-badge a-badge-warning"><x-lucide-lock aria-hidden="true" />{{ __('plans.locked.badge') }}</span>
                                    @endif
                                </td>
                                <td class="is-nowrap a-muted">{{ $product->created_at?->isoFormat('D MMMM YYYY') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="a-muted">{{ __('products.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>

        <aside class="a-stack self-start">
            <section class="a-panel" aria-labelledby="invoices-title">
                <div class="a-panel-head"><h2 class="a-h3" id="invoices-title">{{ __('plans.billing.invoices') }}</h2></div>
                @if ($invoices->isEmpty())
                    <p class="a-panel-body a-muted">{{ __('plans.billing.no_invoices') }}</p>
                @else
                    <ul class="a-plan-invoices">
                        @foreach ($invoices as $invoice)
                            @php $stripeInvoice = $invoice->asStripeInvoice(); @endphp
                            <li>
                                <span>{{ $invoice->date()->isoFormat('D MMMM YYYY') }}<span class="a-row-sub">{{ $stripeInvoice->number }}</span></span>
                                <span class="a-plan-invoice-total">{{ $invoice->total() }}</span>
                                @if ($stripeInvoice->hosted_invoice_url)
                                    <a href="{{ $stripeInvoice->hosted_invoice_url }}" class="a-link-btn" rel="noopener noreferrer" target="_blank">{{ __('plans.billing.view') }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </aside>
    </div>
</x-layouts.app>
