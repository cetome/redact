{{--
    Opens a sandbox from the page the visitor is on (owner, 2026-10-23: no /try page in between).
    Links marked data-sandbox-open (Dashboard in the header and footer, "Try REDact" in the hero) send
    this form: sandbox-start.js solves the invisible ALTCHA on hover, focus or click, then posts, and
    the visitor lands on the sandbox dashboard. Without script the links are plain links to /try.
    "See a demo" (x-demo-link) sends the same form to /try/demo (its data-sandbox-action).
    A signed-in guest whose sandbox is empty gets form#demo-open instead (no proof needed).
    Rendered once per page by the public layout.
--}}
@if (auth()->guest() && \App\Guest\Sandbox::enabled())
    <form method="POST" action="{{ route('guest.create') }}" id="sandbox-open" data-sandbox-start data-on-demand hidden
          data-opening="{{ __('guest.start.opening') }}">
        @csrf
        <button type="submit" data-start-button hidden></button>
        <altcha-widget challenge="{{ route('guest.altcha') }}" name="altcha" display="invisible"
            language="{{ \App\Support\Locales::detail('altcha') }}"></altcha-widget>
    </form>
@elseif (auth()->user()?->is_guest && ! \App\Guest\Sandbox::productLimitReached(auth()->user()) && app(\App\Guest\DemoProduct::class)->available())
    <form method="POST" action="{{ route('guest.demo') }}" id="demo-open" hidden>@csrf</form>
@endif
