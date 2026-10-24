{{-- State badge of one self-assessment card. $state: true (met), false (not met), 'na', null (to answer); $pending: trees still to walk. --}}
@if ($state === 'na')
    <span class="a-badge a-badge-neutral">{{ __('conformity.state_na') }}</span>
@elseif ($state === true)
    <span class="a-badge a-badge-success"><x-lucide-check aria-hidden="true" />{{ __('scoping.restriction_ok') }}</span>
@elseif ($state === false)
    <span class="a-badge a-badge-danger"><x-lucide-x aria-hidden="true" />{{ __('scoping.restriction_ko') }}</span>
@else
    <span class="a-badge a-badge-outline">{{ $pending ? __('conformity.restriction_pending') : __('conformity.state_open') }}</span>
@endif
