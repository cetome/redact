{{-- A download or import a sandbox guest cannot use yet: a lock and the way to an account. --}}
<a href="{{ route('guest.claim') }}" class="a-btn a-btn-outline a-btn-sm" title="{{ $tooltip }}">
    <x-lucide-lock aria-hidden="true" />{{ $label }}</a>
