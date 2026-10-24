{{--
    "Essential requirements" (route versions.scoping), the step after the product: the scoping
    cards (scoping._toggles) that set the essential requirements (articles 3.3 d/e/f) and the parts
    of EN 18031, and in the sticky aside the live result and the confirmation (scoping._confirm).
    The risk assessment, the next step, checks the risks against them (risks._coverage).
--}}
<x-layouts.app :title="__('articles.title')">
    <x-slot:subnav><x-version-nav :version="$version" active="scoping" /></x-slot:subnav>
    <x-read-only-notice :access="$access" :version="$version" />
    @php
        $editable = $access->canEdit($version);
        $aiReason = $editable ? \App\Ai\Availability::reason(auth()->user(), $version) : null;
    @endphp

    <x-ui.page-header :title="__('articles.title')" :subtitle="__('articles.lead')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
    </x-ui.page-header>

    <form method="POST" action="{{ route('versions.scoping.update', $version) }}" class="a-grid-main" x-data="scopingLive"
          data-scoping="{{ json_encode(app(\App\Assessment\ScopingForm::class)->liveMap()) }}"
          data-ai-recorded="{{ json_encode((object) $aiRecorded) }}" @change="refresh">
        @csrf
        @method('PUT')
        <div class="a-stack min-w-0">
            <section class="a-art-questions" aria-labelledby="questions-title">
                <h2 class="a-micro" id="questions-title">{{ __('articles.questions_title') }}</h2>
                @if ($editable)
                    <div class="a-scope-ai-panel">
                        @include('scoping._ai', ['mode' => 'version', 'url' => route('ai.scoping', $version), 'reason' => $aiReason])
                    </div>
                @endif
                @include('scoping._toggles', ['pack' => $pack, 'answers' => $answers, 'disabled' => ! $editable])
            </section>
        </div>

        <aside class="a-aside-sticky">
            <section class="a-panel">
                @include('scoping._result', ['pack' => $pack])
                @include('scoping._confirm', ['state' => $state, 'confirmedBy' => $confirmedBy, 'editable' => $editable])
            </section>
        </aside>
    </form>
</x-layouts.app>
