{{--
    "Try REDact": opens itself. The invisible ALTCHA is solved on load and the form is sent as soon as
    the proof is ready (data-auto-submit, sandbox-start.js), while this explanation stays on screen.
    The button stays as the fallback: no script, a failed check, or an error from the server (then
    nothing is sent by itself, so a refusal never loops).
--}}
<x-layouts.guest :title="__('guest.start.title')" :index="true">
    <h1 class="c-h1">{{ __('guest.start.heading') }}</h1>
    <p class="c-lead mt-4">{{ __('guest.start.lead') }}</p>

    <div class="c-card mt-8">
        <div class="c-card-body space-y-6">
            <h2 class="c-h3">{{ __('guest.start.included') }}</h2>
            <ul class="c-rule-list">
                <li class="flex items-start gap-2 py-2"><x-lucide-check class="size-4 mt-1 shrink-0 text-brand-blue" aria-hidden="true" /><span>{{ __('guest.start.full') }}</span></li>
                <li class="flex items-start gap-2 py-2"><x-lucide-check class="size-4 mt-1 shrink-0 text-brand-blue" aria-hidden="true" /><span>{{ __('guest.start.reports') }}</span></li>
                <li class="flex items-start gap-2 py-2"><x-lucide-sparkles class="size-4 mt-1 shrink-0" aria-hidden="true" /><span>{{ __('guest.start.no_ai') }}</span></li>
                <li class="flex items-start gap-2 py-2"><x-lucide-file-lock class="size-4 mt-1 shrink-0" aria-hidden="true" /><span>{{ __('guest.start.files') }}</span></li>
                <li class="flex items-start gap-2 py-2"><x-lucide-hourglass class="size-4 mt-1 shrink-0" aria-hidden="true" /><span>{{ __('guest.start.kept', ['hours' => $hours]) }} {{ __('guest.start.keep') }}</span></li>
                <li class="flex items-start gap-2 py-2"><x-lucide-monitor class="size-4 mt-1 shrink-0" aria-hidden="true" /><span>{{ __('guest.start.browser') }}</span></li>
            </ul>

            <form method="POST" action="{{ route('guest.create') }}" class="r-try-form" data-sandbox-start
                  @if (! $errors->any()) data-auto-submit @endif>
                @csrf
                <button type="submit" class="c-btn c-btn-primary c-btn-block r-start-btn" data-start-button>
                    <x-lucide-loader-circle class="r-start-spinner" aria-hidden="true" />
                    <span data-start-label data-busy-label="{{ __('guest.start.check.preparing') }}">{{ __('guest.start.submit') }}</span>
                </button>
                @include('guest._altcha-auto')
            </form>
        </div>
    </div>

    <p class="c-text mt-8">
        {{ __('guest.start.have_account') }} <a href="{{ route('login') }}" class="c-link">{{ __('guest.start.sign_in') }}</a>
        @if (config('redact.auth.signup'))
            · <a href="{{ route('register') }}" class="c-link">{{ __('nav.sign_up') }}</a>
        @endif
    </p>
</x-layouts.guest>
