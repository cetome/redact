{{--
    Console: Plans and prices. One panel per plan above Free: what it includes (left) and its Stripe
    prices, plan and add-on, per month and per year (under it). Staff read, a super-admin changes.
--}}
@php
    $locales = (array) config('redact.locales', ['en' => 'English']);
    $periods = \App\Billing\Plan::PERIODS;
@endphp
<x-layouts.app :title="__('plan_catalogue.title')" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="__('plan_catalogue.title')" :subtitle="__('plan_catalogue.lead')">
        <x-slot:eyebrow>{{ __('admin.title') }}</x-slot:eyebrow>
    </x-ui.page-header>

    <div @class(['a-alert', 'a-alert-warning' => $mode === null]) role="status">
        <x-dynamic-component :component="$mode === 'live' ? 'lucide-circle-check' : 'lucide-info'" aria-hidden="true" />
        <span>{{ __('plan_catalogue.mode.'.($mode ?? 'none')) }} <a href="{{ route('admin.stripe.edit') }}" class="a-link">{{ __('plan_catalogue.mode.keys') }}</a></span>
    </div>
    @unless ($canEdit)
        <p class="a-help">{{ __('plan_catalogue.read_only') }}</p>
    @endunless

    <div class="a-stack gap-4!">
        @foreach ($plans as $key => $plan)
            @php
                $history = $prices->where('plan', $key)->where('current', false);
            @endphp
            <section class="a-panel" aria-labelledby="plan-{{ $key }}-heading">
                <div class="a-panel-head"><div class="flex flex-wrap items-center gap-2">
                    <h2 id="plan-{{ $key }}-heading" class="a-h3">{{ $plan->name() }}</h2>
                    <span class="font-mono a-muted text-xs">{{ $key }}</span>
                    @if ($plan->builtIn)<span class="a-badge a-badge-neutral">{{ __('plan_catalogue.badges.built_in') }}</span>@endif
                    <span @class(['a-badge', 'a-badge-success' => $plan->public, 'a-badge-dark' => ! $plan->public])>{{ __('plan_catalogue.badges.'.($plan->public ? 'public' : 'on_request')) }}</span>
                    @unless ($plan->active)<span class="a-badge a-badge-warning">{{ __('plan_catalogue.badges.off') }}</span>@endunless
                    @if (($assigned[$key] ?? 0) > 0)
                        <span class="a-badge a-badge-outline">{{ trans_choice('plan_catalogue.badges.assigned', $assigned[$key], ['count' => $assigned[$key]]) }}</span>
                    @endif
                </div></div>
                <div class="a-panel-body a-stack gap-5!">
                    {{-- What the plan includes, then its prices under it (full width: the table has four columns). --}}
                    <form method="POST" action="{{ route('admin.plans.update', $key) }}" class="a-stack gap-3!" aria-label="{{ $plan->name() }}">
                        @csrf
                        @method('PUT')
                        <fieldset class="a-fieldset a-stack gap-3!" @disabled(! $canEdit)>
                            @include('admin.plans._fields', ['plan' => $plan, 'prefix' => 'plan-'.$key])
                        </fieldset>
                        @if ($canEdit)
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-save aria-hidden="true" />{{ __('plan_catalogue.save') }}</button>
                                @unless ($plan->builtIn)
                                    <button type="submit" form="delete-{{ $key }}" class="a-btn a-btn-outline a-btn-sm a-spacer"
                                            x-data="confirmSubmit" data-confirm="{{ __('plan_catalogue.delete_confirm') }}" @click="check"><x-lucide-trash-2 aria-hidden="true" />{{ __('plan_catalogue.delete') }}</button>
                                @endunless
                            </div>
                        @endif
                    </form>
                    @unless ($plan->builtIn)
                        <form id="delete-{{ $key }}" method="POST" action="{{ route('admin.plans.destroy', $key) }}" hidden>@csrf @method('DELETE')</form>
                    @endunless

                    {{-- Its prices --}}
                    <div class="a-stack gap-2!">
                        <h3 class="a-label">{{ __('plan_catalogue.price.title') }}</h3>
                        <div class="a-table-wrap">
                            <table class="a-table a-table-compact">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('plan_catalogue.price.item') }}</th>
                                        <th scope="col">{{ __('plan_catalogue.price.current') }}</th>
                                        <th scope="col" class="is-num">{{ __('plan_catalogue.price.subscriptions') }}</th>
                                        @if ($canEdit && $mode !== null)<th scope="col">{{ __('plan_catalogue.price.new') }}</th>@endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (\App\Billing\Catalogue::ITEMS as $item)
                                        @foreach (\App\Billing\Catalogue::INTERVALS as $interval)
                                            @php
                                                $current = $prices->first(fn ($r) => $r['plan'] === $key && $r['item'] === $item && $r['interval'] === $interval && $r['mode'] === $mode && $r['current']);
                                                $bag = 'price_'.$key.'_'.$item.'_'.$interval;
                                                $error = $errors->getBag($bag)->first('amount');
                                                $hasPlanPrice = $prices->contains(fn ($r) => $r['plan'] === $key && $r['item'] === 'plan' && $r['interval'] === $interval && $r['mode'] === $mode && $r['current']);
                                            @endphp
                                            <tr>
                                                <th scope="row">{{ __('plan_catalogue.items.'.$item) }} <span class="a-row-sub">{{ __('plan_catalogue.per.'.$interval) }}</span></th>
                                                <td>
                                                    @if ($current)
                                                        {{ \App\Billing\StripeSettings::label((int) $current['amount'], (string) $current['currency']) }}
                                                        <span class="a-row-sub font-mono">{{ $current['id'] }}</span>
                                                    @elseif ($item === 'extra_product' && $hasPlanPrice)
                                                        <span class="a-muted">{{ __('plan_catalogue.price.as_plan') }}</span>
                                                    @else
                                                        <span class="a-muted">{{ __('plan_catalogue.price.none') }}</span>
                                                        @if ($plan->public)
                                                            <span class="a-row-sub">{{ __('plan_catalogue.price.shown', ['label' => $catalogue->label($key, $item, $interval)]) }}</span>
                                                        @endif
                                                    @endif
                                                </td>
                                                <td class="is-num">{{ $current ? ($counts[$current['id']] ?? 0) : '—' }}</td>
                                                @if ($canEdit && $mode !== null)
                                                    <td>
                                                        <form method="POST" action="{{ route('admin.plans.price', $key) }}" class="flex items-center gap-2">
                                                            @csrf
                                                            <input type="hidden" name="item" value="{{ $item }}">
                                                            <input type="hidden" name="interval" value="{{ $interval }}">
                                                            <label for="{{ $bag }}" class="sr-only">{{ __('plan_catalogue.price.amount', ['currency' => $currency]) }}</label>
                                                            <input id="{{ $bag }}" name="amount" type="number" min="1" max="1000000" step="1" required inputmode="numeric" class="a-input a-input-sm w-24"
                                                                   placeholder="{{ $currency }}" value="{{ $error ? old('amount') : '' }}" title="{{ __('plan_catalogue.price.amount', ['currency' => $currency]) }}"
                                                                   @if ($error) aria-invalid="true" aria-describedby="{{ $bag }}-error" @endif>
                                                            <button type="submit" class="a-btn a-btn-outline a-btn-sm" x-data="confirmSubmit" data-confirm="{{ __('plan_catalogue.price.create_confirm') }}" @click="check">{{ __('plan_catalogue.price.create') }}</button>
                                                        </form>
                                                        @if ($error)<p id="{{ $bag }}-error" class="a-error">{{ $error }}</p>@endif
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="a-help">{{ __('plan_catalogue.price.help') }}</p>
                        @if ($history->isNotEmpty())
                            <details>
                                <summary class="a-link-btn">{{ __('plan_catalogue.price.history') }} ({{ $history->count() }})</summary>
                                <ul class="a-help mt-1">
                                    @foreach ($history as $row)
                                        <li>{{ __('plan_catalogue.price.history_row', [
                                            'item' => __('plan_catalogue.items.'.$row['item']), 'interval' => __('plan_catalogue.per.'.$row['interval']),
                                            'amount' => \App\Billing\StripeSettings::label((int) $row['amount'], (string) $row['currency']), 'mode' => __('plan_catalogue.price.modes.'.$row['mode']),
                                            'date' => \Illuminate\Support\Carbon::parse($row['created_at'])->isoFormat('D MMMM YYYY'),
                                        ]) }} <span class="font-mono">{{ $row['id'] }}</span> · {{ trans_choice('plans.billing.subscriptions_count', $counts[$row['id']] ?? 0, ['count' => $counts[$row['id']] ?? 0]) }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>
                </div>
            </section>
        @endforeach

        @if ($canEdit)
            {{-- A new plan (Business...) --}}
            <form method="POST" action="{{ route('admin.plans.store') }}" class="a-panel" aria-labelledby="plan-new-heading">
                @csrf
                <div class="a-panel-head"><h2 id="plan-new-heading" class="a-h3">{{ __('plan_catalogue.new.title') }}</h2></div>
                <div class="a-panel-body a-stack gap-3!">
                    <p class="a-help">{{ __('plan_catalogue.new.help') }}</p>
                    <div class="a-field md:w-1/2">
                        <label for="plan-new-key" class="a-label">{{ __('plan_catalogue.fields.key') }}</label>
                        <input id="plan-new-key" name="key" type="text" class="a-input font-mono" maxlength="31" required pattern="[a-z][a-z0-9_]{1,30}" autocomplete="off" spellcheck="false"
                               value="{{ old('key') }}" placeholder="business" aria-describedby="plan-new-key-help" @error('key') aria-invalid="true" @enderror>
                        <p id="plan-new-key-help" class="a-help">{{ __('plan_catalogue.fields.key_help') }}</p>
                        @error('key')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                    @include('admin.plans._fields', ['plan' => null, 'prefix' => 'plan-new'])
                </div>
                <div class="a-panel-foot">
                    <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-circle-plus aria-hidden="true" />{{ __('plan_catalogue.new.save') }}</button>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
