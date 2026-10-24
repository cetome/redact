<x-layouts.app :title="__('declaration.title').' · '.$version->displayName()">
    <x-slot:subnav><x-version-nav :version="$version" active="declaration" /></x-slot:subnav>
    @php
        $approved = $version->status === \App\Enums\VersionStatus::Approved;
        $counts = $file->counts();
        $headings = count($file->checklist());
    @endphp
    @if (! $canEdit)
        <x-read-only-notice :access="$access" :version="$version" />
    @elseif ($approved)
        <div class="a-alert"><x-lucide-lock aria-hidden="true" /><span>{{ __('declaration.approved_notice') }}</span></div>
    @endif

    <x-ui.page-header :title="__('declaration.title')" :subtitle="__('declaration.lead')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
        <x-slot:actions>
            {{-- The declaration downloads (language, all languages) are in the Declaration tab's aside. --}}
            <a href="{{ route('versions.technical-file.download', $version) }}" class="a-btn a-btn-outline" download
               title="{{ __('declaration.file.download_tip') }}"><x-lucide-folder-archive aria-hidden="true" />{{ __('declaration.file.download') }}</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($isGuest)
        <div class="a-alert"><x-lucide-user-plus aria-hidden="true" /><span>{{ __('declaration.guest') }} <a href="{{ route('guest.claim') }}" class="a-link-btn">{{ __('guest.keep') }}</a></span></div>
    @endif

    <nav class="a-tabs a-decl-tabs" aria-label="{{ __('declaration.views') }}">
        <a href="{{ route('versions.declaration', $version) }}" class="a-tab" @if ($view === 'declaration') aria-current="page" @endif>
            <x-lucide-file-signature aria-hidden="true" />{{ __('declaration.tab_declaration') }}
        </a>
        <a href="{{ route('versions.declaration-file', $version) }}" class="a-tab" @if ($view === 'file') aria-current="page" @endif>
            <x-lucide-folder-tree aria-hidden="true" />{{ __('declaration.tab_file') }}
            <span @class(['a-tab-count', 'is-warning' => $counts['missing'] > 0])>{{ $counts['present'] + $counts['na'] }}/{{ $headings }}</span>
        </a>
    </nav>

    @if ($view === 'file')
        @include('declaration._file')
    @else
        @include('declaration._declaration')
    @endif
</x-layouts.app>
