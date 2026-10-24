{{-- Team page of a sandbox guest: no invitations, a way to keep the work. --}}
<aside class="a-panel">
    <div class="a-panel-head"><h2 class="a-h3">{{ __('team.invite') }}</h2></div>
    <div class="a-panel-body a-stack gap-3!">
        <p class="a-help">{{ __('guest.team') }}</p>
        <a href="{{ route('guest.claim') }}" class="a-btn a-btn-primary a-btn-block"><x-lucide-user-plus aria-hidden="true" />{{ __('guest.keep') }}</a>
    </div>
</aside>
