{{--
    AI assistance buttons for one text field: "Improve text with AI".
    <x-ai.assist :version="$version" type="evaluation" :key="$evaluation->id" field="justification" />
    Optional `for` = id of the textarea; without it, the nearest textarea around the buttons is used.
    Renders nothing unless the person may use the AI on this version (policy `useAi`); when the
    organisation or the version rules the AI out, a quiet note says why, once per page (`explain` = false hides it).

    The proposal opens in a dialog with a word diff ("Keep my words" / "Accept"). On Accept the
    server copies the stored proposal into the field (never a value sent by the browser), marks it
    "AI Enhanced", and the textarea is updated; a bubbling `ai-accepted` event is dispatched on it
    with detail {type, key, field, value, origin}.
--}}
@props(['version', 'type', 'key', 'field', 'for' => null, 'explain' => true])
@php
    $user = auth()->user();
    $reason = $user ? \App\Ai\Availability::reason($user, $version) : null;
    $allowed = $user && $reason === null;
    // The reason is said once per page, at the first field (owner, 2026-10-23: no information duplication).
    $explainHere = ! $allowed && $explain && $reason && $user && ! request()->attributes->get('ai.explained')
        && Gate::forUser($user)->allows('edit', $version);
    if ($explainHere) {
        request()->attributes->set('ai.explained', true);
    }
@endphp
@if ($allowed)
    <div x-data="aiAssist" {{ $attributes->class(['inline-flex flex-wrap items-center gap-2']) }}
         data-url="{{ route('ai.improve', $version) }}"
         data-type="{{ $type }}" data-key="{{ $key }}" data-field="{{ $field }}" data-for="{{ $for }}"
         data-error="{{ __('ai.errors.unavailable') }}" data-empty="{{ __('ai.improve.empty') }}"
         data-label-idle="{{ __('ai.improve.button') }}" data-label-busy="{{ __('ai.improve.working') }}">
        <button type="button" class="inline-flex items-center gap-1 rounded-md border border-brand-blue/30 bg-brand-blue/5 px-2 py-1 text-[11px] font-bold text-brand-blue hover:bg-brand-blue/10 disabled:opacity-50 cursor-pointer dark:border-brand-blue-night/40 dark:text-brand-blue-night"
                @click="improve" :disabled="busy" :aria-busy="busy"
                title="{{ __('ai.improve.tooltip') }} {{ \App\Ai\Feature::costHint(\App\Ai\Feature::ImproveText->units()) }}">
            <x-lucide-sparkles class="size-3.5" aria-hidden="true" />
            <span x-text="buttonLabel">{{ __('ai.improve.button') }}</span>
        </button>
        <template x-if="enhanced"><span><x-ai.badge origin="ai_enhanced" /></span></template>
        <template x-if="error"><span class="a-error" role="alert" x-text="error"></span></template>

        <dialog x-ref="dialog" @close="closed" @cancel="cancelled" aria-labelledby="ai-dialog-title-{{ $field }}-{{ $key }}"
                class="m-auto w-[min(42rem,calc(100vw-2rem))] rounded-2xl border border-line bg-surface p-0 text-ink shadow-panel backdrop:bg-slate-950/50">
            <div class="border-b border-line px-5 py-4">
                <h2 id="ai-dialog-title-{{ $field }}-{{ $key }}" class="flex flex-wrap items-center gap-2 text-sm font-black uppercase tracking-wider">
                    <x-lucide-sparkles class="size-4 text-brand-blue dark:text-brand-blue-night" aria-hidden="true" />
                    {{ __('ai.improve.dialog_title') }}
                    <x-ai.badge origin="ai_enhanced" />
                </h2>
                <p class="mt-1 text-xs text-ink-muted">{{ __('ai.improve.dialog_intro') }}</p>
            </div>
            <div class="max-h-[60vh] space-y-4 overflow-y-auto px-5 py-4">
                <div>
                    <p class="mb-1.5 text-[10px] font-black uppercase tracking-widest text-ink-muted">{{ __('ai.improve.proposed') }}</p>
                    <p class="whitespace-pre-wrap rounded-xl border border-brand-blue/20 bg-brand-blue/5 p-3 text-sm leading-relaxed dark:border-brand-blue-night/30 dark:bg-brand-blue-night/10" x-text="proposal.improved"></p>
                </div>
                <template x-if="proposal.unchanged">
                    <p class="text-xs text-ink-muted">{{ __('ai.improve.nothing') }}</p>
                </template>
                <template x-if="showDiff">
                    <div>
                        <p class="mb-1.5 text-[10px] font-black uppercase tracking-widest text-ink-muted">{{ __('ai.improve.what_changed') }}</p>
                        <p class="whitespace-pre-wrap rounded-xl border border-line bg-canvas p-3 text-sm leading-relaxed"><template x-for="(part, index) in parts" :key="index"><span><template x-if="part.same"><span x-text="part.text"></span></template><template x-if="part.added"><ins class="rounded bg-emerald-100 px-0.5 text-emerald-900 no-underline dark:bg-emerald-900/40 dark:text-emerald-100" x-text="part.text"></ins></template><template x-if="part.removed"><del class="rounded bg-rose-100 px-0.5 text-rose-900 dark:bg-rose-900/40 dark:text-rose-100" x-text="part.text"></del></template></span></template></p>
                        <ul class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-ink-muted">
                            <template x-for="(change, index) in proposal.changes" :key="index"><li x-text="change"></li></template>
                        </ul>
                    </div>
                </template>
                <template x-if="dialogError"><p class="a-error" role="alert" x-text="dialogError"></p></template>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" class="a-btn a-btn-ghost a-btn-sm" @click="keep" :disabled="saving">
                    <x-lucide-x class="size-3.5" aria-hidden="true" /> {{ __('ai.improve.keep') }}
                </button>
                <button type="button" class="a-btn a-btn-primary a-btn-sm" @click="accept" :disabled="cannotAccept">
                    <x-lucide-check class="size-3.5" aria-hidden="true" /> {{ __('ai.improve.accept') }}
                </button>
            </div>
        </dialog>
    </div>
@elseif ($explainHere)
    <span {{ $attributes->class(['inline-flex items-center gap-1 text-[11px] text-ink-subtle']) }} title="{{ $reason }}">
        @if ($reason === __('plans.ai.paid_only'))
            {{-- The free plan has no AI: say so, and show an admin where to turn it on. --}}
            <x-lucide-sparkles class="size-3" aria-hidden="true" /> {{ __('plans.ai.short') }} <x-ai.plan-link :scope="$version" />
        @else
            <x-lucide-sparkles class="size-3" aria-hidden="true" /> {{ __('ai.unavailable.short') }}
        @endif
    </span>
@endif
