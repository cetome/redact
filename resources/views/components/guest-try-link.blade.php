{{-- Discreet "Try without an account" link for the sign-in and sign-up pages. <x-guest-try-link /> --}}
@if (config('redact.guest.enabled') && Route::has('guest.start'))
    <p class="c-meta mt-3"><x-try-link class="c-link">{{ __('guest.try_link') }}</x-try-link></p>
@endif
