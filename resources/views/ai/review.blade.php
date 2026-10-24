<x-layouts.app :title="__('ai.review.title')">
    <x-slot:subnav><x-version-nav :version="$version" active="ai" /></x-slot:subnav>

    <x-ui.page-header :title="__('ai.review.title')" :subtitle="__('ai.review.intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
    </x-ui.page-header>

    @if ($unavailable && $canEdit)
        <div class="a-alert a-alert-warning" role="status"><x-lucide-triangle-alert aria-hidden="true" /><span>{{ $unavailable }} <x-ai.plan-link :scope="$version" /></span></div>
    @endif

    <div class="a-grid-main max-[80rem]:grid-cols-1">
        <div class="a-stack min-w-0">
            <div class="a-section-head mb-0!">
                <h2 class="a-h2">{{ __('ai.review.pending') }}</h2>
                @if (count($groups))
                    <span class="a-toolbar-count">{{ trans_choice('ai.review.group_count', count($groups), ['count' => count($groups)]) }}</span>
                @endif
            </div>

            @forelse ($groups as $group)
                @php($acceptable = collect($group['items'])->where('acceptable', true)->count())
                <section class="a-panel" aria-label="{{ $group['label'] }}">
                    <div class="a-panel-head">
                        <h3 class="a-h3 flex items-center gap-2"><x-lucide-sparkles class="size-4 text-brand-blue dark:text-brand-blue-night" aria-hidden="true" />{{ $group['label'] }}</h3>
                        @if ($canEdit && $acceptable > 1)
                            <form method="POST" action="{{ route('ai.suggestions.accept_group', $version) }}">
                                @csrf
                                <input type="hidden" name="target_type" value="{{ $group['type'] }}">
                                <input type="hidden" name="target_key" value="{{ $group['key'] }}">
                                <button type="submit" class="a-btn a-btn-outline a-btn-sm" title="{{ __('ai.review.accept_all_tooltip') }}"><x-lucide-check-check aria-hidden="true" />{{ __('ai.review.accept_all') }}</button>
                            </form>
                        @endif
                    </div>

                    <ul class="divide-y divide-line">
                        @foreach ($group['items'] as $item)
                            @php($s = $item['suggestion'])
                            <li class="space-y-2 px-4 py-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ai.badge :origin="$item['origin']" />
                                    @if ($item['field'])
                                        <span class="a-micro">{{ $item['field'] }}</span>
                                    @endif
                                    <span class="text-label text-ink-subtle">{{ $s->feature === 'improve_text' ? __('ai.features.improve_text') : __('ai.features.draft_from_documents') }} · {{ $s->created_at->isoFormat('D MMMM YYYY') }}</span>
                                </div>

                                @if ($group['kind'] === 'new_item')
                                    <p class="font-semibold">{{ $item['name'] }}</p>
                                    @if ($item['description'])
                                        <p class="text-sm whitespace-pre-wrap">{{ $item['description'] }}</p>
                                    @endif
                                    @if ($item['attributes'])
                                        <dl class="a-dl text-label!">
                                            @foreach ($item['attributes'] as $attribute)
                                                <dt>{{ $attribute['label'] }}</dt>
                                                <dd>{{ $attribute['value'] }}</dd>
                                            @endforeach
                                        </dl>
                                    @endif
                                @elseif ($group['kind'] === 'new_risk')
                                    <p class="flex flex-wrap items-center gap-2 font-semibold">{{ $item['threat'] }}
                                        <span class="a-letters">
                                            @foreach ($item['stride'] as $letter)
                                                <span class="a-letter is-on">{{ $letter }}</span>
                                            @endforeach
                                        </span>
                                    </p>
                                    @if ($item['description'])
                                        <p class="text-sm whitespace-pre-wrap">{{ $item['description'] }}</p>
                                    @endif
                                @else
                                    <p class="rounded-lg border border-brand-blue/25 bg-brand-blue/5 p-2.5 text-sm whitespace-pre-wrap dark:border-brand-blue-night/30 dark:bg-brand-blue-night/10">{{ $item['value'] }}</p>
                                    @if (filled($item['current']))
                                        <details class="text-sm">
                                            <summary class="cursor-pointer text-label font-semibold text-ink-muted">{{ __('ai.review.current') }}</summary>
                                            <p class="mt-1 whitespace-pre-wrap text-ink-muted">{{ $item['current'] }}</p>
                                        </details>
                                    @endif
                                @endif

                                <x-ai.sources :sources="$item['sources']" />

                                @if ($canEdit)
                                    <div class="flex flex-wrap gap-2">
                                        @if ($item['acceptable'])
                                            <form method="POST" action="{{ route('ai.suggestions.accept', [$version, $s]) }}">
                                                @csrf
                                                <button type="submit" class="a-btn a-btn-primary a-btn-sm"
                                                        title="{{ filled($item['current']) ? __('ai.review.accept_replace_tooltip') : __('ai.review.accept_tooltip') }}"><x-lucide-check aria-hidden="true" />{{ __('ai.review.accept') }}</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('ai.suggestions.reject', [$version, $s]) }}">
                                            @csrf
                                            <button type="submit" class="a-btn a-btn-ghost a-btn-sm" title="{{ __('ai.review.reject_tooltip') }}"><x-lucide-x aria-hidden="true" />{{ __('ai.review.reject') }}</button>
                                        </form>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <x-ui.empty icon="sparkles" :title="__('ai.review.nothing')" :text="__('ai.review.nothing_help')" />
            @endforelse

            @if ($decided)
                <section aria-labelledby="decided-title">
                    <div class="a-section-head"><h2 class="a-h2" id="decided-title">{{ __('ai.review.decided') }}</h2></div>
                    <div class="a-table-wrap">
                        <table class="a-table a-table-compact">
                            <tbody>
                                @foreach ($decided as $row)
                                    <tr>
                                        <td class="is-shrink">@if ($row['origin'])<x-ai.badge :origin="$row['origin']" />@endif</td>
                                        <th scope="row">{{ $row['label'] }}@if ($row['field'])<span class="a-row-sub">{{ $row['field'] }}</span>@endif</th>
                                        <td class="is-shrink"><span class="a-badge {{ $row['status'] === 'accepted' ? 'a-badge-success' : 'a-badge-neutral' }}">{{ __('ai.review.status.'.$row['status']) }}</span></td>
                                        <td class="text-label a-muted">{{ __('ai.review.decided_by', ['name' => $row['by'] ?? '—', 'date' => $row['at']?->isoFormat('D MMMM YYYY') ?? '']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        <aside class="a-stack self-start">
            <section class="a-panel">
                <div class="a-panel-head">
                    <h2 class="a-h3 flex items-center gap-2"><x-lucide-sparkles class="size-4 text-brand-blue dark:text-brand-blue-night" aria-hidden="true" /> {{ __('ai.draft.title') }}</h2>
                </div>
                <div class="a-panel-body a-stack gap-3!">
                    <p class="a-help">{{ __('ai.draft.help') }}</p>
                    <p class="text-sm">{{ trans_choice('ai.documents.count', $documents, ['count' => $documents, 'max' => $maxDocuments]) }}
                        · <a class="a-link-btn" href="{{ route('versions.documents', $version) }}">{{ __('ai.documents.manage') }}</a></p>

                    <div x-data="aiDraftStatus" data-url="{{ route('ai.status', $version) }}" data-running="{{ $draftRunning ? '1' : '0' }}">
                        @if ($draftRunning)
                            <p class="a-alert mb-0!" role="status"><x-lucide-loader class="animate-spin" aria-hidden="true" /><span>{{ __('ai.draft.running') }}</span></p>
                        @elseif (($draftStatus['state'] ?? null) === 'failed')
                            <p class="a-alert a-alert-danger mb-0!" role="status"><x-lucide-circle-x aria-hidden="true" /><span>{{ __('ai.draft.failed') }}</span></p>
                        @endif
                    </div>

                    @if ($canEdit && ! $unavailable)
                        <form method="POST" action="{{ route('ai.draft', $version) }}">
                            @csrf
                            <button type="submit" class="a-btn a-btn-primary" @disabled($documents === 0 || $draftRunning)
                                    title="{{ __('ai.draft.tooltip') }} {{ \App\Ai\Feature::costHint($draftUnits) }}">
                                <x-lucide-sparkles aria-hidden="true" /> {{ __('ai.draft.button') }}
                            </button>
                        </form>
                    @endif
                </div>
            </section>

            <section class="a-panel">
                <div class="a-panel-head"><h2 class="a-h3">{{ __('ai.allowance.title') }}</h2></div>
                <div class="a-panel-body a-stack gap-2! text-sm">
                    <p>{{ __('ai.allowance.day', ['used' => $allowance['used_day'], 'limit' => $allowance['per_day']]) }}</p>
                    <x-ui.progress :value="$allowance['used_day']" :max="$allowance['per_day']" />
                    <p>{{ __('ai.allowance.burst', ['used' => $allowance['used_burst'], 'limit' => $allowance['per_burst']]) }}</p>
                    @if ($allowance['plan'] && $allowance['plan']['units'] === 0)
                        <p>{{ __('plans.ai.not_included') }}</p>
                    @elseif ($allowance['plan'])
                        <p>{{ __($allowance['plan']['trial'] ? 'plans.ai.trial_left' : 'plans.ai.period_left', ['left' => $allowance['plan']['left'], 'units' => $allowance['plan']['units'], 'period' => __('plans.periods.'.$allowance['plan']['period'])]) }}</p>
                        <x-ui.progress :value="$allowance['plan']['used']" :max="max(1, $allowance['plan']['units'])" />
                    @endif
                    <p class="a-help">{{ __('ai.allowance.model', ['model' => __('ai.provider_label')]) }}</p>
                    <p class="a-help">{{ __('ai.review.rules') }}</p>
                </div>
            </section>
        </aside>
    </div>
</x-layouts.app>
