{{--
    Header action of a sandbox guest (App\Guest): "Leave" instead of "Sign out", with a confirmation that
    the work stays until it expires. "Keep my work" is in the guest banner only (with the deadline).
    <x-guest-actions />
--}}
@php($guest = auth()->user())
<form method="POST" action="{{ route('logout') }}" x-data="confirmSubmit" @submit="check"
    data-confirm="{{ __('guest.leave_confirm', ['date' => \App\Guest\Sandbox::expiryLabel($guest)]) }}">
    @csrf
    <button type="submit" class="a-btn a-btn-ghost a-btn-sm" title="{{ __('guest.leave') }}"><x-lucide-log-out aria-hidden="true" /><span class="max-sm:hidden">{{ __('guest.leave') }}</span></button>
</form>
