{{--
    "Suggest a path" for one evaluation: the button and the latest suggestion, one answer per
    tree question with its reason and source. Suggestions only: the person clicks each answer
    in the tree themselves. <x-ai.path :version="$version" :evaluation="$evaluation" />
    The raw answers for the tree UI: App\Ai\Suggestions::latestFor($evaluation, App\Ai\Feature::SuggestPath)
    ->payload['answers'] = [{node, question, answer, rationale, source}, ...] (tree order).
--}}
@props(['version', 'evaluation'])
@php
    $user = auth()->user();
    $canEdit = $user && Gate::forUser($user)->allows('edit', $version);
    $canUse = $user && \App\Ai\Availability::allows($user, $version);
    $suggestion = $canEdit ? \App\Ai\Suggestions::latestFor($evaluation, \App\Ai\Feature::SuggestPath) : null;
    $recorded = collect($evaluation->path ?? [])->pluck('answer', 'node');
@endphp
@if ($canEdit)
    <div {{ $attributes->class(['space-y-3']) }}>
        @if ($canUse)
            <form method="POST" action="{{ route('ai.suggest_path', [$version, $evaluation]) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1 rounded-md border border-brand-blue/30 bg-brand-blue/5 px-2 py-1 text-[11px] font-bold text-brand-blue hover:bg-brand-blue/10 cursor-pointer dark:border-brand-blue-night/40 dark:text-brand-blue-night"
                        title="{{ __('ai.path.tooltip') }} {{ \App\Ai\Feature::costHint(\App\Ai\Feature::SuggestPath->units()) }}">
                    <x-lucide-sparkles class="size-3.5" aria-hidden="true" /> {{ $suggestion ? __('ai.path.again') : __('ai.path.button') }}
                </button>
            </form>
        @endif

        @if ($suggestion)
            <section class="rounded-xl border border-brand-blue/25 bg-brand-blue/5 p-3 space-y-2 dark:border-brand-blue-night/30 dark:bg-brand-blue-night/10" aria-label="{{ __('ai.path.title') }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="inline-flex items-center gap-1.5 text-[10px] font-black uppercase tracking-wider text-brand-blue dark:text-brand-blue-night">
                        <x-lucide-sparkles class="size-3.5" aria-hidden="true" /> {{ __('ai.path.title') }}
                        <x-ai.badge origin="ai_generated" />
                    </p>
                    <form method="POST" action="{{ route('ai.suggestions.reject', [$version, $suggestion]) }}">
                        @csrf
                        <button type="submit" class="a-btn a-btn-ghost a-btn-sm" title="{{ __('ai.check.dismiss_tooltip') }}"><x-lucide-x aria-hidden="true" />{{ __('ai.check.dismiss') }}</button>
                    </form>
                </div>
                <p class="text-xs text-ink-muted">{{ __('ai.path.human_decides') }}</p>
                @if (empty($suggestion->payload['answers']))
                    <p class="text-sm text-ink-muted">{{ __('ai.path.none') }}</p>
                @else
                    <ol class="space-y-2">
                        @foreach ($suggestion->payload['answers'] as $answer)
                            <li class="text-sm">
                                <p>
                                    <span class="font-bold">{{ $answer['node'] }}</span> {{ $answer['question'] }}
                                    <span class="a-badge ml-1">{{ __('ai.path.suggested', ['answer' => $answer['answer']]) }}</span>
                                    @if (isset($recorded[$answer['node']]))
                                        <span class="a-badge a-badge-neutral ml-1">{{ __('ai.path.yours', ['answer' => $recorded[$answer['node']]]) }}</span>
                                    @endif
                                </p>
                                @if ($answer['rationale'] !== '')
                                    <p class="text-ink-muted">{{ $answer['rationale'] }}</p>
                                @endif
                                @if ($answer['source'] !== '')
                                    <p class="text-[11px] text-ink-subtle">{{ __('ai.path.source', ['source' => $answer['source']]) }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        @endif
    </div>
@endif
