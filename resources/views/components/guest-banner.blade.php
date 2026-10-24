{{--
    Guest sandbox banner: shown on every signed-in page of a sandbox guest (App\Guest),
    with the deletion date in the person's time zone and the way to keep the work.
    <x-guest-banner />
--}}
@php($guest = auth()->user())
@if ($guest?->is_guest)
    <aside class="a-guest-bar" aria-label="{{ __('guest.banner_label') }}" data-guest-banner>
        <div class="a-guest-banner">
            <p class="a-guest-banner-text">
                <x-lucide-hourglass aria-hidden="true" />
                <span>{{ __('guest.banner', ['date' => \App\Guest\Sandbox::expiryLabel($guest)]) }}</span>
            </p>
            @unless (request()->routeIs('guest.claim', 'guest.claim.other'))
                <a href="{{ route('guest.claim') }}" class="a-btn a-btn-primary a-btn-sm"><x-lucide-user-plus aria-hidden="true" />{{ __('guest.keep') }}</a>
            @endunless
        </div>
    </aside>
@endif
