{{--
    "Suggest with AI" on the scoping: the button, "Apply the AI suggestions", the summary of the
    proposal (articles and EN 18031 parts it would bring in) and the applied token for the form.
    The proposals themselves appear on the cards (scoping._toggles). Must sit inside the form with
    x-data="scopingLive" (resources/js/modules/scoping.js reads the data-* below).
    @include('scoping._ai', ['mode' => 'new'|'version', 'url' => $url, 'reason' => ?string])
    $reason: null when the person may use the AI here, else why not (guests: one line, others: "AI unavailable").
--}}
@php
    $isGuest = auth()->user()?->is_guest;
@endphp
@if ($reason === null)
    <div class="a-scope-ai" data-scope-ai data-mode="{{ $mode }}" data-url="{{ $url }}"
         data-label-idle="{{ __('scoping.ai.button') }}" data-label-again="{{ __('scoping.ai.again') }}"
         data-label-busy="{{ __('scoping.ai.working') }}" data-reading="{{ __('scoping.ai.reading') }}"
         data-applied="{{ __('scoping.ai.applied') }}" data-empty="{{ __('scoping.ai.empty_new') }}"
         data-failed="{{ __('scoping.ai.failed') }}">
        <div class="a-scope-ai-bar">
            <button type="button" class="a-scope-ai-btn" @click="suggestAi" :disabled="aiBusy" :aria-busy="aiBusy"
                    title="{{ __($mode === 'new' ? 'scoping.ai.tooltip_new' : 'scoping.ai.tooltip_version') }} {{ \App\Ai\Feature::costHint(\App\Ai\Feature::SuggestScoping->units()) }}">
                <x-lucide-sparkles class="size-3.5 shrink-0" aria-hidden="true" />
                <span x-text="aiButtonLabel">{{ __('scoping.ai.button') }}</span>
            </button>
            <button type="button" class="a-btn a-btn-primary a-btn-sm" x-show="aiCanApply" x-cloak @click="applyAi"
                    title="{{ __('scoping.ai.apply_tooltip') }}">
                <x-lucide-wand-sparkles class="size-3.5 shrink-0" aria-hidden="true" />{{ __('scoping.ai.apply') }}
            </button>
        </div>
        {{-- Announces "reading", the result and "applied" (the summary below shows the result). --}}
        <p class="sr-only" role="status" aria-live="polite" x-text="aiStatus"></p>
        <p class="a-error" role="alert" x-show="aiError" x-cloak x-text="aiError"></p>

        <div class="a-scope-ai-summary" x-show="aiHasResult" x-cloak>
            <p class="a-scope-ai-summary-head">
                <x-ai.badge origin="ai_generated" />
                <span x-text="aiMessage"></span>
            </p>
            <p class="a-scope-ai-articles" x-show="aiHasArticles">
                <span class="a-micro">{{ __('scoping.ai.articles') }}</span>
                <template x-for="article in aiArticles" :key="article"><span class="a-scope-ai-article" x-text="article"></span></template>
            </p>
            <p class="a-help">{{ __('scoping.ai.human_decides') }}</p>
        </div>

        <input type="hidden" name="ai_scoping" :value="aiAppliedToken">
    </div>
@elseif ($isGuest)
    <p class="a-scope-ai-off"><x-lucide-sparkles class="size-3 shrink-0" aria-hidden="true" />{{ $reason }}</p>
@elseif ($reason === __('plans.ai.paid_only'))
    {{-- The free plan has no AI: say so, and show an admin where to turn it on. --}}
    <p class="a-scope-ai-off" title="{{ $reason }}">
        <x-lucide-sparkles class="size-3 shrink-0" aria-hidden="true" />{{ __('plans.ai.short') }}<span class="sr-only"> · {{ $reason }}</span>
        <x-ai.plan-link :scope="$mode === 'version' ? ($version ?? null) : null" class="ml-1" />
    </p>
@else
    <p class="a-scope-ai-off" title="{{ $reason }}">
        <x-lucide-sparkles class="size-3 shrink-0" aria-hidden="true" />{{ __('ai.unavailable.short') }}<span class="sr-only"> · {{ $reason }}</span>
    </p>
@endif
