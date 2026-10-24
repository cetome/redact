<x-layouts.app :title="$version->displayName()">
    <x-slot:subnav><x-version-nav :version="$version" active="overview" /></x-slot:subnav>
    @php
        $summary = \App\Support\WorkflowSummary::for($version);
        $next = $summary->next();
        $totals = ['pass' => 0, 'fail' => 0, 'na' => 0, 'unjustified' => 0, 'inconclusive' => 0];
        $rows = [];
        foreach ($assessment->workingStandards() as $standard) {
            $p = $assessment->evaluationProgress($standard);
            $rows[] = ['code' => $standard, 'name' => $assessment->pack->standardName($standard), 'trees' => $p,
                'inScope' => $assessment->hasStandard($standard)];
            foreach (array_keys($totals) as $k) {
                $totals[$k] += $p[$k];
            }
        }
    @endphp
    <x-read-only-notice :access="$access" :version="$version" />

    <x-ui.page-header :title="$version->product->name" :muted="__('versions.version').' '.$version->label">
        <x-slot:eyebrow>{{ $version->product->organisation->name }}@if ($version->product->reference) · {{ $version->product->reference }}@endif</x-slot:eyebrow>
        <p class="a-help mt-1 flex flex-wrap items-center gap-2"><x-version-status :status="$version->status" />@if (\App\Assessment\ReviewRights::selfApproved($version))<span class="a-badge a-badge-warning" title="{{ __('review.self_reviewed_help') }}"><x-lucide-user-round-x aria-hidden="true" />{{ __('review.self_reviewed') }}</span>@endif {{ __('workflow.content_pack', ['pack' => $version->content_pack]) }}</p>
        @if ($access->canManage() || ($access->isReadOnlyProduct() && $access->canDelete()))
            <x-slot:actions>
                {{-- Without a reviewer the owner reviews it (self-review): the Review page warns and offers to invite one. --}}
                @include('versions._status-actions', ['version' => $version, 'access' => $access])
                {{-- The Overview is the product page: its settings (edit, delete) one click away. --}}
                <a href="{{ route('products.edit', $version->product) }}" class="a-btn a-btn-ghost"><x-lucide-settings aria-hidden="true" />{{ __('products.settings') }}</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($next && $access->canEdit($version))
        <a href="{{ $next['href'] }}" class="a-panel a-next mb-4">
            <span class="a-empty-icon"><x-lucide-arrow-right aria-hidden="true" /></span>
            <span class="min-w-0 flex-1">
                <span class="a-micro block">{{ __('versions.next_step') }}</span>
                <span class="block font-bold">{{ $next['label'] }}@if ($next['left']) <span class="a-muted font-semibold">· {{ __('ui.left', ['count' => $next['left']]) }}</span>@endif</span>
            </span>
            <span class="a-btn a-btn-primary">{{ __('ui.continue') }}</span>
        </a>
    @endif

    {{-- Only what the sidebar does not show (owner, 2026-10-23: no information duplication): the
         steps, their progress and the risk count are in the sidebar; here, the verdicts. --}}
    <div class="a-stats">
        <x-ui.stat :label="__('verdicts.PASS')" :value="$totals['pass']" tone="ok" />
        <x-ui.stat :label="__('verdicts.FAIL')" :value="$totals['fail']" :tone="$totals['fail'] ? 'bad' : null" />
        <x-ui.stat :label="__('verdicts.NOT_APPLICABLE')" :value="$totals['na']" />
        @if ($totals['inconclusive'])
            <x-ui.stat :label="__('verdicts.INCONCLUSIVE')" :value="$totals['inconclusive']" tone="bad" />
        @endif
        @if ($totals['unjustified'])
            <x-ui.stat :label="__('evaluations.status.unjustified')" :value="$totals['unjustified']" tone="bad" />
        @endif
    </div>

    <section class="a-table-wrap" aria-labelledby="standards-title">
        <table class="a-table">
            <caption class="a-sr-only" id="standards-title">{{ __('workflow.standards_in_scope') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('versions.standard') }}</th>
                    <th scope="col"><span class="a-sr-only">{{ __('versions.attention') }}</span></th>
                    <th scope="col" class="is-num">{{ __('verdicts.PASS') }}</th>
                    <th scope="col" class="is-num">{{ __('verdicts.FAIL') }}</th>
                    <th scope="col" class="is-num">{{ __('versions.na_short') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <th scope="row" class="is-nowrap">
                            <a class="a-row-link" data-row-link href="{{ route('versions.evaluate', [$version, $row['code']]) }}">{{ $row['name'] }}</a>
                            @unless ($row['inScope'])
                                <span class="a-badge a-badge-outline ml-1">{{ __('versions.not_in_scope') }}</span>
                            @endunless
                        </th>
                        <td>@if ($row['trees']['inconclusive'])<x-eval-status status="inconclusive" />@endif @if ($row['trees']['unjustified'])<x-eval-status status="unjustified" />@endif</td>
                        <td class="is-num">{{ $row['trees']['pass'] }}</td>
                        <td class="is-num">@if ($row['trees']['fail'])<span class="font-bold text-red-700 dark:text-red-300">{{ $row['trees']['fail'] }}</span>@else 0 @endif</td>
                        <td class="is-num">{{ $row['trees']['na'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="a-muted">{{ __('workflow.no_standard') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- The product (was the product page): the card, its versions, its team. --}}
    @include('versions._product', ['version' => $version, 'access' => $access])
</x-layouts.app>
