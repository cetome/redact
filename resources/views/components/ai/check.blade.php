{{--
    "Check this evaluation" for one evaluation: the button (when the person may use the AI) and
    the latest AI check as an "AI Generated" panel. The check never changes the evaluation.
    <x-ai.check :version="$version" :evaluation="$evaluation" />
    Only people who can edit the version see it (a reviewer is never shown AI output, as in CRAted).
--}}
@props(['version', 'evaluation'])
@php
    $user = auth()->user();
    $canEdit = $user && Gate::forUser($user)->allows('edit', $version);
    $canUse = $user && \App\Ai\Availability::allows($user, $version);
    $check = $canEdit ? \App\Ai\Suggestions::latestFor($evaluation, \App\Ai\Feature::Check) : null;
    $stale = $check && ($check->payload['fingerprint'] ?? null) !== $evaluation->fingerprint();
    $severity = ['problem' => 'a-badge-danger', 'warning' => 'a-badge-warning', 'info' => 'a-badge-neutral'];
@endphp
@if ($canEdit)
    <div {{ $attributes->class(['space-y-3']) }}>
        @if ($canUse)
            <form method="POST" action="{{ route('ai.check', [$version, $evaluation]) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1 rounded-md border border-brand-blue/30 bg-brand-blue/5 px-2 py-1 text-[11px] font-bold text-brand-blue hover:bg-brand-blue/10 cursor-pointer dark:border-brand-blue-night/40 dark:text-brand-blue-night"
                        title="{{ __('ai.check.tooltip') }} {{ \App\Ai\Feature::costHint(\App\Ai\Feature::Check->units()) }}">
                    <x-lucide-sparkles class="size-3.5" aria-hidden="true" /> {{ $check ? __('ai.check.again') : __('ai.check.button') }}
                </button>
            </form>
        @endif

        @if ($check)
            <section class="rounded-xl border border-brand-blue/25 bg-brand-blue/5 p-3 space-y-2 dark:border-brand-blue-night/30 dark:bg-brand-blue-night/10" aria-label="{{ __('ai.check.title') }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="inline-flex items-center gap-1.5 text-[10px] font-black uppercase tracking-wider text-brand-blue dark:text-brand-blue-night">
                        <x-lucide-sparkles class="size-3.5" aria-hidden="true" /> {{ __('ai.check.title') }}
                        <x-ai.badge origin="ai_generated" />
                    </p>
                    <form method="POST" action="{{ route('ai.suggestions.reject', [$version, $check]) }}">
                        @csrf
                        <button type="submit" class="a-btn a-btn-ghost a-btn-sm" title="{{ __('ai.check.dismiss_tooltip') }}"><x-lucide-x aria-hidden="true" />{{ __('ai.check.dismiss') }}</button>
                    </form>
                </div>
                @if ($stale)
                    <p class="a-help">{{ __('ai.check.stale') }}</p>
                @endif
                @if (! empty($check->payload['summary']))
                    <p class="text-sm">{{ $check->payload['summary'] }}</p>
                @endif
                @if (empty($check->payload['findings']))
                    <p class="text-sm text-ink-muted">{{ __('ai.check.no_findings') }}</p>
                @else
                    <ul class="space-y-1.5">
                        @foreach ($check->payload['findings'] as $finding)
                            <li class="flex items-start gap-2 text-sm">
                                <span class="a-badge {{ $severity[$finding['severity']] ?? 'a-badge-neutral' }} shrink-0">{{ __('ai.check.severity.'.$finding['severity']) }}</span>
                                <span>{{ $finding['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="text-xs text-ink-muted">{{ __('ai.check.human_decides') }}</p>
            </section>
        @endif
    </div>
@endif
