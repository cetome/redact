{{--
    Before Stripe Checkout: the plan and how often to pay (App\Billing\Catalogue::offered(), each plan
    on the intervals it has a price for). One option is preselected; a single option is shown as text.
--}}
@php
    $catalogue = app(\App\Billing\Catalogue::class);
    $free = (int) config('redact.plans.free.products', 1);
    $options = [];
    foreach ($catalogue->offered() as $offer) {
        foreach ($catalogue->intervals($offer->key) as $interval) {
            $options[] = [$offer, $interval];
        }
    }
    $selected = old('offer', isset($options[0]) ? $options[0][0]->key.':'.$options[0][1] : '');
@endphp
@if (count($options) > 1)
    <fieldset class="a-stack gap-2!">
        <legend class="a-label mb-1">{{ __('plans.add.choose') }}</legend>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ($options as [$offer, $interval])
                @php $value = $offer->key.':'.$interval; @endphp
                <label class="a-toggle-card">
                    <input type="radio" name="offer" value="{{ $value }}" @checked($selected === $value) required>
                    <span class="min-w-0">
                        <span class="block font-semibold">{{ $offer->name() }} · {{ __('plans.interval.'.$interval) }}</span>
                        <span class="block">{{ $catalogue->label($offer->key, 'plan', $interval) }} {{ __('plans.per.'.$interval) }}</span>
                        <span class="a-toggle-card-text">
                            @if ($offer->unlimited())
                                {{ __('plans.add.option_unlimited') }}
                            @else
                                {{ trans_choice('plans.add.option_includes', (int) $offer->products, ['count' => (int) $offer->products, 'extra' => $catalogue->label($offer->key, 'extra_product', $interval), 'per' => __('plans.per.'.$interval)]) }}
                            @endif
                        </span>
                    </span>
                </label>
            @endforeach
        </div>
        @error('offer')<p class="a-error">{{ $message }}</p>@enderror
    </fieldset>
@elseif (count($options) === 1)
    @php [$offer, $interval] = $options[0]; @endphp
    <input type="hidden" name="offer" value="{{ $offer->key }}:{{ $interval }}">
    <p><strong>{{ $offer->name() }}</strong>: {{ $catalogue->label($offer->key, 'plan', $interval) }} {{ __('plans.per.'.$interval) }}.
        @unless ($offer->unlimited())
            {{ trans_choice('plans.add.option_includes', (int) $offer->products, ['count' => (int) $offer->products, 'extra' => $catalogue->label($offer->key, 'extra_product', $interval), 'per' => __('plans.per.'.$interval)]) }}
        @endunless
    </p>
@endif
