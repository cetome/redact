{{-- The version's status action, in the header of the Overview and of the Review page: owners and
     organisation admins (canManage; never reviewers, never the demo). Draft: submit for review (the
     refusals come back as a flash on the page it was sent from); in review: withdraw; approved: reopen.
     @include('versions._status-actions', ['version', 'access']) --}}
@if ($access->canManage())
    @if ($version->status === \App\Enums\VersionStatus::Draft)
        <form method="POST" action="{{ route('versions.submit', $version) }}">
            @csrf
            <button class="a-btn a-btn-outline" type="submit"><x-lucide-send aria-hidden="true" />{{ __('versions.submit') }}</button>
        </form>
    @elseif ($version->status === \App\Enums\VersionStatus::InReview)
        <form method="POST" action="{{ route('versions.withdraw', $version) }}">
            @csrf
            <button class="a-btn a-btn-outline" type="submit"><x-lucide-undo-2 aria-hidden="true" />{{ __('versions.withdraw') }}</button>
        </form>
    @elseif ($version->status === \App\Enums\VersionStatus::Approved)
        <form method="POST" action="{{ route('versions.reopen', $version) }}">
            @csrf
            <button class="a-btn a-btn-outline" type="submit"><x-lucide-pencil aria-hidden="true" />{{ __('versions.reopen') }}</button>
        </form>
    @endif
@endif
