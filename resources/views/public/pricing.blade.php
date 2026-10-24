{{--
    Pricing: open source (the engine), web-based Free and Paid, self-hosted with cetome rules. Every
    number comes from config('redact.plans') through PublicController::plans(); the four offers are
    public/_plans (shared with the home page). The FAQ is also the page's FAQPage JSON-LD.
--}}
@php
    $members = (int) ($free['team_members'] ?? 1);
    $freeAi = (int) ($free['ai_units'] ?? 0);
    $paidAi = (int) ($paid['ai_units'] ?? 0);
    $yes = '<svg class="r-yes" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><span class="a-sr-only">'.e(__('public.pricing.cells.yes')).'</span>';
    $no = '<span class="r-no" aria-hidden="true">—</span><span class="a-sr-only">'.e(__('public.pricing.cells.no')).'</span>';
    $c = fn (string $key, array $replace = []) => e(__('public.pricing.cells.'.$key, $replace));
    $rows = [
        'hosting' => [$c('your_servers'), $c('cetome'), $c('cetome'), $c('your_servers')],
        'rules' => [$no, $yes, $yes, $yes],
        'products' => [$c('unlimited'), e((string) ($free['products'] ?? 1)), $c('included', ['count' => $included, 'price' => $price]), $c('unlimited')],
        'team' => [$c('unlimited'), e(trans_choice('public.pricing.cells.owner_plus', $members, ['members' => $members])), $c('on_paid'), $c('unlimited')],
        'ai' => [$c('own_key'), $freeAi > 0 ? $c('trial', ['units' => $freeAi]) : $no, $c('units_or_own', ['units' => $paidAi]), $c('on_request')],
        'templates' => [$no, $yes, $yes, $yes],
        'updates' => [$c('engine'), $c('automatic'), $c('automatic'), $c('contract')],
        'support' => [$c('community'), $no, $yes, $yes],
        'price' => [$c('free'), $c('free'), $c('per_month', ['price' => $price, 'count' => $included]), $c('on_request')],
    ];
@endphp
<x-layouts.guest :bare="true" :index="true" :page-title="__('public.pricing.meta.title')"
    :description="__('public.pricing.meta.description', ['price' => $price, 'count' => $included])" :json-ld="$jsonLd">
    <section class="c-hero r-hero-short">
        <div class="c-hero-grid">
            <div class="c-hero-main">
                <p class="c-hero-kicker">{{ __('public.kicker') }}</p>
                <h1 class="c-hero-title">{{ __('public.pricing.title') }}</h1>
                <p class="c-hero-lead">{{ __('public.pricing.lead') }}</p>
            </div>
        </div>
    </section>

    <section class="c-section">
        <div class="c-container">
            @include('public._plans')
        </div>
    </section>

    <section class="c-section-band" id="compare">
        <div class="c-container">
            <h2 class="c-h2">{{ __('public.pricing.compare_title') }}</h2>
            <div class="c-table-wrap mt-6">
                <table class="c-table r-compare">
                    <thead>
                        <tr>
                            <th scope="col"><span class="a-sr-only">{{ __('public.pricing.compare_title') }}</span></th>
                            @foreach (['open', 'free', 'paid', 'self'] as $plan)
                                <th scope="col">{{ __('public.pricing.plans.'.$plan.'.name') }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row => $cells)
                            <tr>
                                <th scope="row">{{ __('public.pricing.rows.'.$row) }}</th>
                                @foreach ($cells as $cell)
                                    <td>{!! $cell !!}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="c-section">
        <div class="c-container">
            <h2 class="c-h2">{{ __('public.pricing.faq_title') }}</h2>
            <div class="mt-6 grid gap-3">
                @foreach ((array) __('public.pricing.faq') as $item)
                    <details class="c-faq">
                        <summary>{{ $item['q'] }}</summary>
                        <p class="c-text">{{ $item['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>
</x-layouts.guest>
