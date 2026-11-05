{{--
    Subscribe (FAST's /subscribe, BillingController::subscribe): the payment period, the number of
    products, the summary, then Stripe Checkout (billing.checkout). Reached from Premium on the pricing
    page (after sign-up), Upgrade in the app bar, and New product at the limit.
    Every line that changes with the choices is computed here for each period and number, in $table;
    modules/billing-subscribe.js only swaps them in (no prices or wording in the script).
--}}
@php
    $intervals = array_keys($options);
    $chosenOffer = (string) old('offer', $definition->key.':'.(in_array('month', $intervals, true) ? 'month' : $intervals[0]));
    $chosenInterval = in_array(\Illuminate\Support\Str::after($chosenOffer, ':'), $intervals, true) ? \Illuminate\Support\Str::after($chosenOffer, ':') : $intervals[0];
    $limited = $included !== null;
    // The counter is the additional products (as on the billing page): from what the products in use
    // need beyond the included ones, up to the most sold online.
    $minExtra = $limited ? max(0, min($used, $max) - (int) $included) : 0;
    $maxExtra = $limited ? $max - (int) $included : 0;
    $chosenExtra = $limited ? max($minExtra, min((int) old('extra', $minExtra), $maxExtra)) : 0;
    $label = fn (?int $cents) => $cents === null ? null : \App\Billing\StripeSettings::label($cents, $currency);
    $name = __('public.pricing.plans.paid.name');

    // The lines for one period and one number of products.
    $lines = function (string $interval, int $extra) use ($options, $included, $limited, $label, $name) {
        $o = $options[$interval];
        $total = (int) $included + $extra;
        $sum = $o['planCents'] !== null && $o['extraCents'] !== null ? $o['planCents'] + $extra * $o['extraCents'] : null;
        $extraCents = $o['extraCents'] !== null ? $extra * $o['extraCents'] : null;

        return [
            'breakdown' => ! $limited ? '' : ($extra === 0
                ? __('plans.subscribe.breakdown_included', ['count' => $total, 'plan' => $name])
                : trans_choice('plans.subscribe.breakdown_extra.'.$interval, $extra, ['included' => (int) $included, 'extra' => $extra, 'price' => $o['extra'], 'amount' => $label($extraCents) ?? '—'])),
            'plan' => __('plans.subscribe.plan_line.'.$interval, ['plan' => $name]),
            'products' => ! $limited ? '' : ($extra === 0
                ? __('plans.subscribe.products_included', ['count' => $total])
                : trans_choice('plans.subscribe.products_line', $extra, ['count' => $total, 'included' => (int) $included, 'extra' => $extra])),
            'productsPrice' => $extra === 0 ? __('plans.subscribe.included') : '+'.($label($extraCents) ?? '—'),
            'totalLabel' => __('plans.subscribe.total.'.$interval),
            'total' => $label($sum) ?? '',
            'first' => $sum === null ? '' : __('plans.subscribe.first.'.$interval, ['amount' => $label($sum)]),
            'planPrice' => $o['plan'],
            'button' => $sum === null ? __('plans.add.checkout') : __('plans.subscribe.pay', ['amount' => $label($sum), 'per' => __('plans.per.'.$interval)]),
        ];
    };
    $table = [];
    foreach ($intervals as $interval) {
        foreach ($limited ? range($minExtra, $maxExtra) : [0] as $n) {
            $table[$interval][$n] = $lines($interval, $n);
        }
    }
    $now = $table[$chosenInterval][$chosenExtra];
@endphp
<x-layouts.app :title="__('plans.subscribe.title', ['plan' => $name])">
    <x-ui.page-header :title="__('plans.subscribe.title', ['plan' => $name])" :subtitle="__('plans.subscribe.lead')">
        <x-slot:eyebrow>{{ $organisation->name }}</x-slot:eyebrow>
    </x-ui.page-header>

    <form method="POST" action="{{ route('billing.checkout', $organisation) }}" class="a-sub" data-subscribe data-table="{{ json_encode($table) }}">
        @csrf
        <div class="a-stack min-w-0">
            {{-- Payment period --}}
            <section class="a-panel" aria-labelledby="sub-interval">
                <div class="a-panel-head"><h2 class="a-h3" id="sub-interval">{{ __('plans.subscribe.interval') }}</h2></div>
                <div class="a-panel-body">
                    <div class="a-sub-cards">
                        @foreach ($options as $interval => $o)
                            <label class="a-sub-card">
                                <input type="radio" name="offer" value="{{ $definition->key }}:{{ $interval }}" data-interval="{{ $interval }}" @checked($interval === $chosenInterval) required>
                                <span class="a-sub-check" aria-hidden="true"><x-lucide-check /></span>
                                <span class="a-sub-card-title">{{ __('plans.interval.'.$interval) }}</span>
                                <span class="a-sub-card-price">{{ $o['plan'] }}<small>{{ __('plans.per.'.$interval) }}</small></span>
                                <span class="a-sub-card-text">
                                    @if ($limited)
                                        {{ trans_choice('plans.add.option_includes', (int) $included, ['count' => (int) $included, 'extra' => $o['extra'], 'per' => __('plans.per.'.$interval)]) }}
                                    @else
                                        {{ __('plans.add.option_unlimited') }}
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('offer')<p class="a-error mt-2">{{ $message }}</p>@enderror
                </div>
            </section>

            {{-- Products: how many, and what the additional ones add --}}
            @if ($limited)
                <section class="a-panel" aria-labelledby="sub-products">
                    <div class="a-panel-head"><h2 class="a-h3" id="sub-products">{{ __('plans.subscribe.products') }}</h2></div>
                    <div class="a-panel-body a-stack gap-2!">
                        <p class="a-plan-hint"><x-lucide-package-check aria-hidden="true" /><span>{{ __('plans.subscribe.included_line', ['count' => (int) $included, 'plan' => $name]) }}</span></p>
                        <div class="a-field">
                            <label for="extra" class="a-label">{{ __('plans.subscribe.extra_label') }}</label>
                            <div class="a-stepper">
                                <button type="button" class="a-btn a-btn-outline a-btn-icon" data-step="-1" aria-label="{{ __('plans.billing.one_fewer') }}"><x-lucide-minus aria-hidden="true" /></button>
                                <input id="extra" name="extra" type="number" class="a-input" min="{{ $minExtra }}" max="{{ $maxExtra }}" value="{{ $chosenExtra }}" inputmode="numeric" data-key aria-describedby="sub-breakdown">
                                <button type="button" class="a-btn a-btn-outline a-btn-icon" data-step="1" aria-label="{{ __('plans.billing.one_more') }}"><x-lucide-plus aria-hidden="true" /></button>
                            </div>
                        </div>
                        <p class="a-sub-breakdown" id="sub-breakdown" aria-live="polite" data-line="breakdown">{{ $now['breakdown'] }}</p>
                        @if ($used > 0)
                            <p class="a-help">{{ trans_choice('plans.subscribe.existing', $used, ['count' => $used]) }}</p>
                        @endif
                        <p class="a-help">{{ __('plans.subscribe.products_help', ['max' => $max]) }}</p>
                        @error('extra')<p class="a-error">{{ $message }}</p>@enderror
                        @error('products')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                </section>
            @endif

            @include('plans._line', ['plan' => $plan])
        </div>

        {{-- Your subscription: the lines, the total, the consent, then Stripe --}}
        <aside class="a-panel self-start" aria-labelledby="sub-summary">
            <div class="a-panel-head"><h2 class="a-h3" id="sub-summary">{{ __('plans.subscribe.summary') }}</h2></div>
            <div class="a-panel-body a-stack gap-3!">
                <div class="a-sub-summary" aria-live="polite">
                    <dl class="a-sub-lines">
                        <div>
                            <dt data-line="plan">{{ $now['plan'] }}</dt>
                            <dd data-line="planPrice">{{ $now['planPrice'] }}</dd>
                        </div>
                        @if ($limited)
                            <div>
                                <dt data-line="products">{{ $now['products'] }}</dt>
                                <dd data-line="productsPrice">{{ $now['productsPrice'] }}</dd>
                            </div>
                        @endif
                        @if ($now['total'] !== '')
                            <div class="a-sub-total">
                                <dt data-line="totalLabel">{{ $now['totalLabel'] }}</dt>
                                <dd data-line="total">{{ $now['total'] }}</dd>
                            </div>
                        @endif
                    </dl>
                    @if ($now['first'] !== '')
                        <p class="a-sub-first" data-line="first">{{ $now['first'] }}</p>
                    @endif
                </div>
                <p class="a-help">{{ __('plans.subscribe.vat') }}</p>
                @include('billing._business')
                <button type="submit" class="a-btn a-btn-primary w-full"><x-lucide-credit-card aria-hidden="true" /><span data-line="button">{{ $now['button'] }}</span></button>
                <ul class="a-plan-features a-help">
                    <li><x-lucide-check aria-hidden="true" />{{ __('plans.subscribe.reassure.stripe') }}</li>
                    <li><x-lucide-check aria-hidden="true" />{{ __('plans.subscribe.reassure.cancel') }}</li>
                </ul>
            </div>
        </aside>
    </form>
</x-layouts.app>
