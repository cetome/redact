{{--
    An evaluation or E.Info entry put back to re-check by a significant change (SignificantChange):
    what to do, and "Still valid" when it stays as it is (a change of its content clears it too).
    @include('changes._recheck', ['subject' => Evaluation|EinfoEntry, 'action' => url, 'editable' => bool])
--}}
<div class="a-alert a-alert-warning a-recheck-alert" role="note">
    <x-lucide-refresh-ccw aria-hidden="true" />
    <span class="min-w-0">
        <b>{{ __('evaluations.status.recheck') }}.</b>
        @if ($version->previousVersion)
            {{ __('changes.recheck_note', ['label' => $version->previousVersion->label]) }}
            <a href="{{ route('versions.changes', $version) }}" class="a-link-btn">{{ __('changes.see_changes') }}</a>
        @else
            {{-- The earlier version is gone: no "Changes" step to link to. --}}
            {{ __('changes.recheck_note_alone') }}
        @endif
    </span>
    @if ($editable)
        <form method="POST" action="{{ $action }}" class="a-recheck-action">
            @csrf
            <button type="submit" class="a-btn a-btn-outline a-btn-sm" title="{{ __('changes.still_valid_help') }}"><x-lucide-check aria-hidden="true" />{{ __('changes.still_valid') }}</button>
        </form>
    @endif
</div>
