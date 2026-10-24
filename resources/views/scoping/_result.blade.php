{{--
    Live result of the scoping: the EN 18031 parts in scope only, updated as cards are ticked (owner,
    2026-10-23: show what is in scope, nothing about the others; the panel title says "In scope").
    Head and body of an .a-panel; the page adds its own footer (actions).
    @include('scoping._result', ['pack' => $pack])
    Must sit inside an element with x-data="scopingLive".
--}}
<div class="a-panel-head"><h2 class="a-h3">{{ __('scoping.in_scope_live') }}</h2></div>
<div class="a-panel-body a-stack gap-3!">
    <div class="a-scope-result" aria-live="polite">
        @foreach ($pack->standards() as $code => $standard)
            <p x-data="scopeChip" data-standard="{{ $code }}" x-show="isIn" :class="rowClass" class="a-scope-std">
                <x-lucide-circle-check aria-hidden="true" />
                <span>{{ $pack->standardName($code) }}</span>
            </p>
        @endforeach
    </div>
    <p class="a-help" x-show="anyInScope">{{ __('scoping.result_help') }}</p>
    <p class="a-alert a-alert-warning mb-0!" x-show="noneInScope"><x-lucide-info aria-hidden="true" /><span>{{ __('scoping.none_live') }}</span></p>
</div>
