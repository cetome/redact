{{--
    Home: the cetome hero (title, lead, "Try REDact" → the sandbox dashboard (x-try-link), "See a demo" (x-demo-link), "Free templates"; no other link), the
    RED scoping tool at #start (public/_tool), then "What happens next": the workflow in its real
    order (PublicController::TOUR) as the /about slider (public/_slider). Copy: the essential only.
--}}
<x-layouts.guest :bare="true" :index="true" :page-title="__('public.meta.title')" :json-ld="$jsonLd">
    <section class="c-hero r-home-hero">
        <div class="c-hero-grid">
            <div class="c-hero-main">
                <p class="c-hero-kicker">{{ __('public.kicker') }}</p>
                <h1 class="c-hero-title">{{ __('public.title_start') }} <span class="c-dot-end">{{ __('public.title_end') }}</span><span class="c-hero-title-tail">{{ __('public.title_tail') }}</span></h1>
                <p class="c-hero-lead">{{ __('public.lead') }}</p>
                <div class="c-btn-row mt-8">
                    <x-try-link class="c-btn c-btn-on-dark c-btn-lg">{{ __('public.try_button') }}</x-try-link>
                    <x-demo-link class="c-btn c-btn-outline-on-dark c-btn-lg">{{ __('guest.demo.button') }}</x-demo-link>
                    @if (Route::has('templates'))
                        <a href="{{ route('templates') }}" class="c-btn c-btn-outline-on-dark c-btn-lg">{{ __('public.templates') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section class="r-start" id="start" aria-labelledby="start-title">
        <div class="c-container">
            @include('public._tool')
        </div>
    </section>

    {{-- What happens next: the workflow as the /about slider, one real screen per step (owner, 2026-10-23). --}}
    <section class="c-section" id="tour" aria-labelledby="next-title">
        <div class="c-container">
            <h2 class="c-h2" id="next-title">{{ __('public.next.title') }}</h2>
            @include('public._slider', ['steps' => $steps])
        </div>
    </section>

    {{-- Pricing: the four offers of /pricing (public/_plans, one source); the comparison stays there. --}}
    <section class="c-section-band" id="pricing" aria-labelledby="pricing-title">
        <div class="c-container">
            <h2 class="c-h2" id="pricing-title">{{ __('public.pricing.title') }}</h2>
            <div class="mt-8">
                @include('public._plans')
            </div>
            <a href="{{ route('pricing') }}" class="c-btn c-btn-outline mt-8">{{ __('public.pricing.compare_link') }}</a>
        </div>
    </section>
</x-layouts.guest>
