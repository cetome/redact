{{--
    The home page tool (section #start, below the hero): the RED scoping (the New product cards and
    live result, reused as they are), read 1 → 2 → 3: 1 name (optional) and 2 tick on the left, the
    live "In scope" result beside them as feedback, 3 Start as a full-width action row at the bottom
    (HomeStartController). "See a demo" is in the hero.

    - Visitor: an invisible ALTCHA is solved on page load (guest._altcha-auto, sandbox-start.js). Start
      sends the form as soon as the proof is ready (straight away, or after "Preparing your sandbox…").
    - Signed in: no proof needed, the product is created in the account (see HomeStartController).
    - Sandbox switched off: the cards still work, the action is "Create an account".
    Without script the cards still tick (CSS :has) and the live result is hidden (it needs Alpine).
--}}
@php
    $visitor = auth()->guest();
    $canStart = ! $visitor || \App\Guest\Sandbox::enabled();
    $answers = old('answers') ? array_map(fn ($v) => $v === '1', (array) old('answers')) : [];
@endphp
<form method="POST" action="{{ route('home.start') }}" class="r-tool" id="tool" x-data="scopingLive" :data-live="loaded"
      data-scoping="{{ json_encode($liveMap) }}" @change="refresh" @if ($canStart) data-sandbox-start @endif>
    @csrf
    <h2 class="a-sr-only" id="start-title">{{ __('public.tool.title') }}</h2>

    <div class="a-field r-tool-name">
        <label class="a-label r-step" for="tool-name"><span class="r-step-no" aria-hidden="true">1</span>{{ __('public.tool.step_name') }} <span class="a-label-hint">{{ __('public.tool.optional') }}</span></label>
        <input id="tool-name" name="name" class="a-input" value="{{ old('name') }}" maxlength="160"
               autocomplete="off" placeholder="{{ __('scoping.name_placeholder') }}" @error('name') aria-invalid="true" aria-describedby="tool-name-error" @enderror>
        @error('name')<p class="a-error" id="tool-name-error">{{ $message }}</p>@enderror
    </div>

    <div class="r-tool-tick">
        <p class="r-step" id="tool-tick"><span class="r-step-no" aria-hidden="true">2</span>{{ __('public.tool.step_tick') }}</p>
        @include('scoping._toggles', ['pack' => $pack, 'answers' => $answers])
    </div>

    {{-- Live feedback while ticking (scoping._result: head and body of a panel). --}}
    <section class="a-panel r-tool-result">
        @include('scoping._result', ['pack' => $pack])
    </section>

    <div class="r-tool-action">
        <span class="r-step-no" aria-hidden="true">3</span>
        @if ($canStart)
            <button type="submit" class="a-btn a-btn-primary r-start-btn" data-start-button>
                <x-lucide-arrow-right class="r-start-icon" aria-hidden="true" />
                <x-lucide-loader-circle class="r-start-spinner" aria-hidden="true" />
                <span data-start-label data-busy-label="{{ __('guest.start.check.preparing') }}">{{ __('public.tool.start') }}</span>
            </button>
            @if ($visitor)
                <div class="r-tool-notes">
                    <p class="r-tool-reassure">{{ __('public.tool.reassure', ['hours' => $hours]) }}</p>
                    @include('guest._altcha-auto')
                </div>
            @endif
        @else
            <a href="{{ route(config('redact.auth.signup') ? 'register' : 'login') }}" class="a-btn a-btn-primary r-start-btn">
                <x-lucide-user-plus aria-hidden="true" />{{ config('redact.auth.signup') ? __('public.tool.signup') : __('nav.sign_in') }}
            </a>
        @endif
    </div>
</form>
