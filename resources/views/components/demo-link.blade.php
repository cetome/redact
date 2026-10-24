{{--
    "See a demo" (owner, 2026-10-23: anyone, from the home hero and /about): the content pack's demo
    product, landing on its Overview.
    - Visitor: like x-try-link, a link that sends the hidden form#sandbox-open (guest/_open-form) with the
      invisible ALTCHA, to /try/demo instead of /try (data-sandbox-action, sandbox-start.js). Without
      script: /try.
    - Guest with an empty sandbox: a button sending form#demo-open (guest/_open-form), no proof needed.
    - Account: its organisation's demo once loaded (a link), otherwise a button posting to /demo
      (DemoController::account, the dashboard's "Load the demo").
    - Anything else (no demo in the pack, sandbox off for visitors, a sandbox that holds a product, an
      account without organisation): nothing.
    <x-demo-link class="a-btn a-btn-outline">See a demo</x-demo-link>
--}}
@php
    $user = auth()->user();
    $demo = app(\App\Guest\DemoProduct::class);
    $organisation = $user !== null && ! $user->is_guest && $demo->hasData() ? \App\Guest\DemoProduct::organisationOf($user) : null;
    $loaded = $organisation ? \App\Guest\DemoProduct::of($organisation) : null;
    $show = $organisation !== null
        || ($demo->available() && ($user === null || ($user->is_guest && ! \App\Guest\Sandbox::productLimitReached($user))));
@endphp
@if ($show)
    @if ($loaded)
        <a href="{{ $loaded->homeUrl() }}" {{ $attributes }}>{{ $slot }}</a>
    @elseif ($organisation)
        <form method="POST" action="{{ route('demo.load') }}" class="contents">
            @csrf
            <button type="submit" {{ $attributes }}>{{ $slot }}</button>
        </form>
    @elseif ($user === null)
        <a href="{{ route('guest.start') }}" {{ $attributes }} data-sandbox-open data-sandbox-action="{{ route('guest.demo') }}">{{ $slot }}</a>
    @else
        <button type="submit" form="demo-open" {{ $attributes }}>{{ $slot }}</button>
    @endif
@endif
