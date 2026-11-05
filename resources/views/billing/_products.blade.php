{{--
    Billing page, an active subscription: its products (CRAted's add-on card, FAST's billing
    "Products"), BillingController::setProducts. What is used and what it costs now, then add some
    (a - / + counter, the price shown as it changes, the consent). Never fewer from here (owner
    2026-11-05): cancelling is in Stripe's portal. The lines for each number are written here, in
    data-table; modules/billing-subscribe.js only swaps them in.
--}}
@php
    $catalogue = app(\App\Billing\Catalogue::class);
    $interval = $plan->interval ?? 'month';
    $per = __('plans.per.'.$interval);
    $currency = strtoupper((string) config('cashier.currency', 'eur'));
    $money = fn (int $cents) => \App\Billing\StripeSettings::label($cents, $currency);
    $planCents = (int) ($catalogue->amount($plan->key(), \App\Billing\Catalogue::ITEM_PLAN, $interval) ?? 0);
    $extraCents = (int) ($catalogue->amount($plan->key(), \App\Billing\Catalogue::ITEM_EXTRA, $interval) ?? 0);
    $current = $plan->freeSlots + $plan->paidSlots;
    $max = max(\App\Http\Controllers\BillingController::MAX_PRODUCTS, $current);
    $used = $plan->freeUsed + $plan->paidUsed;
    $included = (int) ($plan->definition->products ?? 1);
    $recurring = $planCents + max(0, $current - $included) * $extraCents;
    $room = $max - $current;
    $priced = $extraCents > 0;

    $add = [];
    for ($n = 1; $n <= $room; $n++) {
        $add[$n] = [
            'adding' => trans_choice('plans.billing.adding', $n, ['count' => $n, 'amount' => $money($n * $extraCents), 'per' => $per]),
            'after' => __('plans.billing.after', ['count' => $current + $n, 'amount' => $money($recurring + $n * $extraCents), 'per' => $per]),
            'button' => trans_choice('plans.billing.add_button', $n, ['count' => $n, 'amount' => $money($n * $extraCents), 'per' => $per]),
        ];
    }
@endphp
<section class="a-panel" aria-labelledby="products-panel-title">
    <div class="a-panel-head"><h2 class="a-h3" id="products-panel-title">{{ __('plans.billing.products_title') }}</h2></div>
    <div class="a-panel-body a-stack gap-4!">
        {{-- Now: used, covered, what it costs --}}
        <div class="a-sub-summary">
            <dl class="a-sub-lines">
                <div><dt>{{ __('plans.billing.products_used') }}</dt><dd>{{ __('plans.billing.products_used_value', ['used' => $used, 'count' => $current]) }}</dd></div>
                <div><dt>{{ __('plans.billing.products_included', ['plan' => $plan->label()]) }}</dt><dd>{{ $included }}</dd></div>
                @if ($priced)
                    <div class="a-sub-total"><dt>{{ __('plans.subscribe.total.'.$interval) }}</dt><dd>{{ $money($recurring) }}</dd></div>
                @endif
            </dl>
        </div>

        @if ($room > 0)
            {{-- Add products: the counter, the price as it changes, the consent --}}
            <form method="POST" action="{{ route('billing.products.set', $organisation) }}" class="a-stack gap-3!" data-lines data-table="{{ json_encode($add) }}">
                @csrf
                @method('PUT')
                <div class="a-field">
                    <label for="products-add" class="a-label">{{ __('plans.billing.add_label') }}</label>
                    <div class="a-stepper">
                        <button type="button" class="a-btn a-btn-outline a-btn-icon" data-step="-1" aria-label="{{ __('plans.billing.one_fewer') }}"><x-lucide-minus aria-hidden="true" /></button>
                        <input id="products-add" name="add" type="number" class="a-input" min="1" max="{{ $room }}" value="{{ (int) old('add', 1) }}" inputmode="numeric" data-key>
                        <button type="button" class="a-btn a-btn-outline a-btn-icon" data-step="1" aria-label="{{ __('plans.billing.one_more') }}"><x-lucide-plus aria-hidden="true" /></button>
                    </div>
                    @error('add')<p class="a-error">{{ $message }}</p>@enderror
                </div>
                @if ($priced)
                    <div aria-live="polite">
                        <p class="a-sub-breakdown" data-line="adding">{{ $add[1]['adding'] }}</p>
                        <p class="a-help" data-line="after">{{ $add[1]['after'] }}</p>
                    </div>
                @endif
                <p class="a-help">{{ __('plans.billing.add_help', ['max' => \App\Http\Controllers\BillingController::MAX_PRODUCTS]) }}</p>
                @include('billing._business')
                <div>
                    <button type="submit" class="a-btn a-btn-primary"><x-lucide-plus aria-hidden="true" /><span data-line="button">{{ $priced ? $add[1]['button'] : __('plans.billing.add_plain') }}</span></button>
                </div>
            </form>
        @else
            <p class="a-plan-hint"><x-lucide-package aria-hidden="true" /><span>{{ __('plans.add.maximum', ['max' => \App\Http\Controllers\BillingController::MAX_PRODUCTS]) }}
                <a href="{{ \App\Billing\Plan::contactUrl('pricing') }}" class="a-link-btn" rel="noopener">{{ __('plans.talk') }}</a></span></p>
        @endif

    </div>
</section>
