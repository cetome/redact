{{--
    About REDact: what used to be the home page (how it works, features, what is included, FAQ,
    "Need an expert?"), plus the ways to use REDact. The FAQ is also the page's FAQPage JSON-LD.
--}}
<x-layouts.guest :bare="true" :index="true" :page-title="__('public.about.meta.title')" :description="__('public.about.meta.description')" :json-ld="$jsonLd">
    <section class="c-hero">
        <div class="c-hero-grid">
            <div class="c-hero-main">
                <p class="c-hero-kicker">{{ __('public.kicker') }}</p>
                <h1 class="c-hero-title">{{ __('public.about.title') }} <span class="c-dot-end">{{ __('public.about.title_end') }}</span></h1>
                <p class="c-hero-lead">{{ __('public.about.lead') }}</p>
                <div class="c-btn-row mt-10">
                    <x-try-link class="c-btn c-btn-on-dark c-btn-lg">{{ __('public.try_button') }}</x-try-link>
                    @guest
                        @if (config('redact.auth.signup'))
                            <a href="{{ route('register') }}" class="c-btn c-btn-outline-on-dark c-btn-lg">{{ __('nav.sign_up') }}</a>
                        @endif
                    @endguest
                </div>
            </div>
            <div class="c-hero-aside">
                <x-try-link class="c-hero-card">
                    <span>
                        {{-- Signed in (account or sandbox): the same link opens the dashboard, so it says so. --}}
                        <span class="c-hero-card-title">{{ auth()->check() ? __('public.go_dashboard') : __('public.cards.try.title') }}</span>
                        <span class="c-hero-card-text">{{ auth()->check() ? __('public.cards.dashboard_text') : __('public.cards.try.text') }}</span>
                    </span>
                    <x-lucide-arrow-right class="size-5 text-brand-blue" aria-hidden="true" />
                </x-try-link>
                {{-- A finished assessment in a sandbox (the pack's demo product); nothing without a demo. --}}
                <x-demo-link class="c-hero-card">
                    <span>
                        <span class="c-hero-card-title">{{ __('guest.demo.button') }}</span>
                        <span class="c-hero-card-text">{{ __('guest.demo.card') }}</span>
                    </span>
                    <x-lucide-arrow-right class="size-5 text-brand-blue" aria-hidden="true" />
                </x-demo-link>
                <a href="{{ route('trees.index') }}" class="c-hero-card">
                    <span>
                        <span class="c-hero-card-title">{{ __('public.cards.trees.title') }}</span>
                        <span class="c-hero-card-text">{{ __('public.cards.trees.text') }}</span>
                    </span>
                    <x-lucide-arrow-right class="size-5 text-brand-blue" aria-hidden="true" />
                </a>
                <a href="{{ route('contact.topic', 'expert') }}" class="c-hero-card">
                    <span>
                        <span class="c-hero-card-title">{{ __('public.cards.expert.title') }}</span>
                        <span class="c-hero-card-text">{{ __('public.cards.expert.text') }}</span>
                    </span>
                    <x-lucide-arrow-right class="size-5 text-brand-blue" aria-hidden="true" />
                </a>
            </div>
        </div>
    </section>

    <section class="c-section">
        <div class="c-container">
            <p class="c-kicker">{{ __('public.how_kicker') }}</p>
            <h2 class="c-h2">{{ __('public.how_title') }}</h2>
            <ol class="mt-8 grid gap-6 md:grid-cols-2 xl:grid-cols-4">
                @foreach (['scoping', 'risks', 'trees', 'report'] as $step)
                    <li class="c-card">
                        <div class="c-card-body">
                            <p class="c-stat-value">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</p>
                            <h3 class="c-h3 mt-2">{{ __('public.steps.'.$step.'.title') }}</h3>
                            <p class="c-text mt-2">{{ __('public.steps.'.$step.'.text') }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="c-section-band">
        <div class="c-container grid gap-10 md:grid-cols-3">
            @foreach (['ai', 'team', 'trees'] as $feature)
                <div>
                    <h3 class="c-h3">{{ __('public.features.'.$feature.'.title') }}</h3>
                    <p class="c-text mt-2">{{ __('public.features.'.$feature.'.text') }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Everything in one place: the workflow, one screen per step (public/_slider, slider.js). The home
         page steps link here without script: /about#<step>. --}}
    <section class="c-section" id="tour">
        <div class="c-container">
            <p class="c-kicker">{{ __('public.includes_kicker') }}</p>
            <h2 class="c-h2">{{ __('public.includes_title') }}</h2>
            @include('public._slider', ['steps' => $steps])
        </div>
    </section>

    {{-- Ways to use REDact: web-based (an account) or self-hosted (talk to us); prices on /pricing. --}}
    <section class="c-section-band" id="ways">
        <div class="c-container">
            <p class="c-kicker">{{ __('public.about.ways_kicker') }}</p>
            <h2 class="c-h2">{{ __('public.about.ways_title') }}</h2>
            <ul class="mt-8 grid gap-6 md:grid-cols-2">
                <li class="c-card">
                    <div class="c-card-body r-way">
                        <x-lucide-globe class="r-way-icon" aria-hidden="true" />
                        <div>
                            <h3 class="c-h3">{{ __('public.about.ways.hosted.title') }}</h3>
                            <x-account-link class="c-link r-way-link">{{ __('nav.sign_up') }}</x-account-link>
                        </div>
                    </div>
                </li>
                <li class="c-card">
                    <div class="c-card-body r-way">
                        <x-lucide-server class="r-way-icon" aria-hidden="true" />
                        <div>
                            <h3 class="c-h3">{{ __('public.about.ways.self.title') }}</h3>
                            <p class="c-text mt-2">{{ __('public.about.ways.self.text') }}</p>
                            <a href="{{ \App\Billing\Plan::contactUrl('self-hosted') }}" class="c-link r-way-link">{{ __('public.about.ways_contact') }}<x-lucide-arrow-right aria-hidden="true" /></a>
                        </div>
                    </div>
                </li>
            </ul>
            <a href="{{ route('pricing') }}" class="c-btn c-btn-outline mt-8">{{ __('public.about.ways_pricing') }}</a>
        </div>
    </section>

    <section class="c-section">
        <div class="c-container">
            <h2 class="c-h2">{{ __('public.faq_title') }}</h2>
            <div class="mt-6 grid gap-3">
                @foreach (__('public.faq') as $item)
                    <details class="c-faq">
                        <summary>{{ $item['q'] }}</summary>
                        <p class="c-text">{{ $item['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    <section class="c-section-band">
        <div class="c-container text-center">
            <h2 class="c-h2 c-no-dot">{{ __('public.cta_title') }}</h2>
            <p class="c-lead mx-auto mt-4">{{ __('public.cta_text') }}</p>
            <a href="{{ route('contact.topic', 'expert') }}" class="c-btn c-btn-primary c-btn-lg mt-8">{{ __('public.cta_button') }}</a>
        </div>
    </section>
</x-layouts.guest>
