{{--
    A verdict that follows from another walk (PackRules::followsFrom): "FAIL · follows from SCM-1"
    (dashed, not walked), or "SCM-1 PASS · link a mechanism" (unlinked: still to link in the inventory).
    <x-follows-badge code="SCM-1" />  <x-follows-badge code="SCM-1" state="unlinked" short />
--}}
@props(['code', 'state' => 'fail', 'short' => false])
@if ($state === 'fail')
    <span {{ $attributes->class(['a-verdict a-verdict-fail a-verdict-follows']) }} title="{{ __('evaluations.follows.help', ['code' => $code]) }}">
        <x-lucide-corner-down-right aria-hidden="true" />{{ $short ? __('evaluations.follows.badge_short', ['code' => $code]) : __('evaluations.follows.badge', ['code' => $code]) }}
    </span>
@else
    <span {{ $attributes->class(['a-verdict a-verdict-none a-verdict-follows is-unlinked']) }} title="{{ __('evaluations.follows.unlinked_help', ['code' => $code]) }}">
        <x-lucide-link-2-off aria-hidden="true" />{{ $short ? __('evaluations.follows.unlinked') : __('evaluations.follows.unlinked_badge', ['code' => $code]) }}
    </span>
@endif
