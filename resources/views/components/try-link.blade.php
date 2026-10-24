{{--
    "Try REDact" anywhere on the public pages (owner, 2026-10-23: every Try opens the dashboard).
    A visitor opens a sandbox straight from the page (data-sandbox-open, guest/_open-form,
    sandbox-start.js) and lands on the sandbox dashboard; without script it is a plain link to /try.
    Signed in (an account or a sandbox guest): their dashboard. Sandbox switched off: sign in.
    <x-try-link class="c-btn c-btn-on-dark c-btn-lg">Try REDact</x-try-link>
--}}
<a href="{{ auth()->check() ? route('dashboard') : (\App\Guest\Sandbox::enabled() ? route('guest.start') : route('login')) }}" {{ $attributes }} @if (auth()->guest() && \App\Guest\Sandbox::enabled()) data-sandbox-open @endif>{{ $slot }}</a>
