{{--
    Review = view and validate, never edit. Two views: the evaluations (each opens a read-only evidence
    panel, #evidence, with the Agree / Disagree / Question form) and the assessment units the
    manufacturer provided (units._cases in 'review' mode). Nothing here links to an editor.
--}}
<x-layouts.app :title="__('review.title')" :wide="true">
    <x-slot:subnav><x-version-nav :version="$version" active="review" /></x-slot:subnav>
    @php
        $pack = $assessment->pack;
        $all = $evaluations->flatten(1);
        $reviews = $all->flatMap(fn ($e) => $e->reviews);
        $reviewed = $all->filter(fn ($e) => $e->reviews->isNotEmpty())->count();
        $disagree = $reviews->where('verdict', 'disagree')->count();
        $timezone = $me->timezone ?: config('app.timezone');
        $date = fn ($value) => $value?->copy()->timezone($timezone)->isoFormat('D MMMM YYYY');
        $isStale = fn ($r) => isset($changes[$r->id]);

        // Per evaluation: my review, the stale reviews and the facets it belongs to.
        $meta = $all->mapWithKeys(function ($e) use ($changes, $canReview, $me, $assessment) {
            $mine = $e->reviews->firstWhere('reviewer_id', $me->id);
            $staleReviews = $e->reviews->filter(fn ($r) => isset($changes[$r->id]))->values();

            return [$e->id => [
                'mine' => $mine,
                'mineChanged' => $mine && isset($changes[$mine->id]),
                'stale' => $staleReviews,
                'facets' => array_keys(array_filter([
                    'changed' => $staleReviews->isNotEmpty(),
                    'todo' => $canReview ? (! $mine || isset($changes[$mine->id])) : $e->reviews->isEmpty(),
                    'disagree' => $e->reviews->contains('verdict', 'disagree'),
                    'question' => $e->reviews->contains('verdict', 'question'),
                    'inconclusive' => $e->verdict === \App\Enums\Verdict::Inconclusive,
                    'tocheck' => $assessment->justification()->check($e)->toCheck() > 0,
                ])),
            ]];
        });
        $facetCount = fn (string $facet) => $meta->filter(fn ($m) => in_array($facet, $m['facets'], true))->count();
        $stale = $facetCount('changed');

        // The units view: how far the manufacturer's results are validated.
        $results = collect($cases['standards'])->flatMap(fn ($s) => $s['requirements'])->flatMap(fn ($r) => $r['cases'])
            ->filter(fn ($c) => ! $c['informative'] && $c['result'])->map(fn ($c) => $c['result']);
        $unitReviews = $results->flatMap(fn ($r) => $r->reviews);
        $unitStale = $results->filter(fn ($r) => $r->reviews->contains(fn ($review) => $review->fingerprint !== $r->fingerprint()))->count();
        $selfDecisions = $decisions->where('self_review', true);
    @endphp
    <x-read-only-notice :access="$access" />

    <x-ui.page-header :title="__('review.title')" :subtitle="__('review.intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
        {{-- Submit, withdraw or reopen, as on the Overview (owners and organisation admins only). --}}
        @if ($access->canManage())
            <x-slot:actions>@include('versions._status-actions', ['version' => $version, 'access' => $access])</x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($rights->selfMode)
        {{-- No reviewer on the product: its owner and the organisation's admins review it themselves. --}}
        <div class="a-alert a-alert-warning" role="status">
            <x-lucide-user-round-x aria-hidden="true" />
            <span>{{ __('review.self_warning') }}
                @if ($access->canManage())
                    <a href="{{ route('team.reviewer', $version->product) }}" class="a-link-btn">{{ __('review.invite_reviewer') }}</a>
                @endif
            </span>
        </div>
    @endif

    @if ($version->status !== \App\Enums\VersionStatus::InReview)
        <div class="a-alert">
            <x-lucide-info aria-hidden="true" />
            <span>{{ $version->status === \App\Enums\VersionStatus::Approved ? $version->status->label() : __('review.not_submitted') }}
                @if ($version->status === \App\Enums\VersionStatus::Approved)
                    <a href="{{ route('versions.declaration', $version) }}" class="a-link-btn">{{ __('declaration.review_next') }}</a>
                @endif
            </span>
        </div>
    @endif

    {{-- Two views of the step, linkable: the evaluations, the assessment units. --}}
    <nav class="a-tabs a-review-tabs" aria-label="{{ __('assessment.views') }}">
        <a href="{{ route('versions.review', $version) }}" class="a-tab" @if ($view === 'evaluations') aria-current="page" @endif>
            <x-lucide-list-checks aria-hidden="true" />{{ __('assessment.view_evaluations') }} <span class="a-tab-count">{{ $all->count() }}</span>
        </a>
        <a href="{{ route('versions.review.units', $version) }}" class="a-tab" @if ($view === 'units') aria-current="page" @endif>
            <x-lucide-flask-conical aria-hidden="true" />{{ __('assessment.view_cases') }}@unless ($version->units_external) <span class="a-tab-count">{{ $cases['counts']['recorded'] }}/{{ $cases['counts']['total'] }}</span>@endunless
        </a>
    </nav>

    @php $unitsNone = ! $version->units_external && $cases['counts']['total'] > 0 && $cases['counts']['recorded'] === 0; @endphp
    @if ($view === 'units' && ($version->units_external || $unitsNone))
        {{-- Performed outside REDact, or none provided yet: one line below, no figures. --}}
    @elseif ($view === 'units')
        <div class="a-stats">
            <x-ui.stat :label="__('assessment.stat_recorded')" :value="$cases['counts']['recorded']" :total="$cases['counts']['total']" bar />
            <x-ui.stat :label="__('review.stat_reviewed')" :value="$results->filter(fn ($r) => $r->reviews->isNotEmpty())->count()" :total="$results->count()" bar />
            <x-ui.stat :label="__('review.verdicts.disagree')" :value="$unitReviews->where('verdict', 'disagree')->count()" :tone="$unitReviews->where('verdict', 'disagree')->count() ? 'bad' : null" />
            <x-ui.stat :label="__('review.verdicts.question')" :value="$unitReviews->where('verdict', 'question')->count()" />
            <x-ui.stat :label="__('review.stale')" :value="$unitStale" :tone="$unitStale ? 'bad' : null" />
        </div>
    @else
    <div class="a-stats">
        <x-ui.stat :label="__('review.stat_evaluations')" :value="$all->count()" />
        <x-ui.stat :label="__('review.stat_reviewed')" :value="$reviewed" :total="$all->count()" bar />
        <x-ui.stat :label="__('review.verdicts.disagree')" :value="$disagree" :tone="$disagree ? 'bad' : null" />
        <x-ui.stat :label="__('review.verdicts.question')" :value="$reviews->where('verdict', 'question')->count()" />
        <x-ui.stat :label="__('review.stale')" :value="$stale" :tone="$stale ? 'bad' : null" />
    </div>
    @endif

    <div class="a-grid-main max-[80rem]:grid-cols-1">
        @if ($view === 'units' && $version->units_external)
            <div class="min-w-0"><x-ui.alert icon="flask-conical">{{ __('assessment.external_line') }}</x-ui.alert></div>
        @elseif ($view === 'units' && $unitsNone)
            {{-- Reviewers validate the evaluations meanwhile; the units appear here once provided. --}}
            <div class="min-w-0"><x-ui.alert icon="flask-conical">{{ trans_choice('assessment.none_yet', $cases['counts']['total'], ['count' => $cases['counts']['total']]) }}</x-ui.alert></div>
        @elseif ($view === 'units')
            @include('units._cases', ['cases' => $cases, 'mode' => 'review', 'canReview' => $canReview, 'counts' => $counts, 'version' => $version, 'me' => $me])
        @else
        <div class="min-w-0" data-filter-scope data-review>
            @if ($all->isEmpty())
                <x-ui.empty icon="clipboard-check" :title="__('review.no_evaluation')" :text="__('review.no_evaluation_help')" />
            @else
                <div class="a-toolbar">
                    <x-ui.search target="#review-rows" :placeholder="__('review.search')" />
                    <div class="a-seg" role="group" aria-label="{{ __('review.show_only') }}" data-facet-target="#review-rows">
                        <button type="button" class="a-seg-btn" data-facet="" aria-pressed="true">{{ __('review.facet_all') }} <span class="a-seg-count">{{ $all->count() }}</span></button>
                        <button type="button" class="a-seg-btn" data-facet="todo" aria-pressed="false">{{ $canReview ? __('review.facet_todo') : __('review.facet_unreviewed') }} <span class="a-seg-count">{{ $facetCount('todo') }}</span></button>
                        <button type="button" class="a-seg-btn" data-facet="changed" aria-pressed="false" aria-keyshortcuts="c"
                            data-shortcut="c" data-shortcut-doc data-shortcut-label="{{ __('review.key_changed') }}">{{ __('review.stale') }} <span @class(['a-seg-count', 'is-warning' => $stale])>{{ $stale }}</span></button>
                        <button type="button" class="a-seg-btn" data-facet="disagree" aria-pressed="false">{{ __('review.verdicts.disagree') }} <span class="a-seg-count">{{ $facetCount('disagree') }}</span></button>
                        <button type="button" class="a-seg-btn" data-facet="question" aria-pressed="false">{{ __('review.verdicts.question') }} <span class="a-seg-count">{{ $facetCount('question') }}</span></button>
                        <button type="button" class="a-seg-btn" data-facet="tocheck" aria-pressed="false" title="{{ __('assessment.to_check_help') }}">{{ __('assessment.to_check') }} <span @class(['a-seg-count', 'is-warning' => $facetCount('tocheck')])>{{ $facetCount('tocheck') }}</span></button>
                        <button type="button" class="a-seg-btn" data-facet="inconclusive" aria-pressed="false">{{ __('verdicts.INCONCLUSIVE') }} <span @class(['a-seg-count', 'is-warning' => $facetCount('inconclusive')])>{{ $facetCount('inconclusive') }}</span></button>
                    </div>
                    <span class="a-toolbar-count" data-filter-count data-template="{{ __('ui.shown') }}"></span>
                    {{-- Keys handled by review.js, listed in the ? help. --}}
                    <span hidden data-shortcut="j" data-shortcut-doc data-shortcut-label="{{ __('review.key_next') }}"></span>
                    <span hidden data-shortcut="k" data-shortcut-doc data-shortcut-label="{{ __('review.key_previous') }}"></span>
                    <span hidden data-shortcut="o" data-shortcut-doc data-shortcut-label="{{ __('review.key_open') }}"></span>
                    @if ($canReview)
                        <span hidden data-shortcut="a" data-shortcut-doc data-shortcut-label="{{ __('review.key_agree') }}"></span>
                        <span hidden data-shortcut="d" data-shortcut-doc data-shortcut-label="{{ __('review.key_disagree') }}"></span>
                        <span hidden data-shortcut="q" data-shortcut-doc data-shortcut-label="{{ __('review.key_question') }}"></span>
                    @endif
                </div>
                <div class="a-table-wrap a-table-scroll" id="review-rows">
                    <table class="a-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('evaluations.requirement') }} · {{ __('review.object') }}</th>
                                <th scope="col">{{ __('evaluations.verdict') }}</th>
                                <th scope="col" class="w-[36%]">{{ __('evaluations.justification') }}</th>
                                <th scope="col">{{ __('review.reviews') }}</th>
                                <th scope="col"><span class="a-sr-only">{{ __('review.evidence.title') }}</span></th>
                            </tr>
                        </thead>
                        @foreach ($evaluations as $standard => $rows)
                            <tbody data-filter-group>
                                <tr class="a-table-group"><th colspan="5" scope="colgroup">{{ $pack->standardName($standard) }} · {{ $rows->count() }}</th></tr>
                                @foreach ($rows as $evaluation)
                                    @php $row = $meta[$evaluation->id]; @endphp
                                    <tr id="evaluation-{{ $evaluation->id }}" @class(['align-top', 'is-changed' => $row['stale']->isNotEmpty()]) data-filter-item data-review-row
                                        data-search="{{ $evaluation->requirement }} {{ $evaluation->item?->label() }} {{ $evaluation->verdict?->label() }}"
                                        data-facet-value="{{ implode(' ', $row['facets']) }}">
                                        <th scope="row" class="align-top! min-w-36 max-w-56">
                                            <span class="a-code">{{ $evaluation->requirement }}</span>
                                            <span class="a-review-object">{{ $evaluation->item?->label() ?? __('evaluations.product') }}</span>
                                            @if ($evaluation->nodeReference())
                                                <span class="a-row-sub a-mono">{{ $evaluation->nodeReference() }}</span>
                                            @endif
                                            @if ($row['stale']->isNotEmpty())
                                                <span class="a-badge a-badge-warning a-changed-badge"><x-lucide-history aria-hidden="true" />{{ __('review.changed') }}</span>
                                            @endif
                                        </th>
                                        <td class="align-top!"><x-verdict :verdict="$evaluation->verdict" />
                                            @if ($assessment->status($evaluation, $pack->tree($standard, $evaluation->requirement)) === 'unjustified')
                                                <x-eval-status status="unjustified" class="mt-1" />
                                            @endif
                                            @if (in_array('tocheck', $row['facets'], true))
                                                <span class="a-badge a-badge-warning mt-1" title="{{ __('assessment.to_check_help') }}"><x-lucide-eye aria-hidden="true" />{{ __('assessment.to_check') }}</span>
                                            @endif
                                        </td>
                                        <td class="align-top! text-label">
                                            @if ($evaluation->justification)
                                                <p class="line-clamp-3 whitespace-pre-line">{{ $evaluation->justification }}</p>
                                            @else
                                                <span class="a-badge a-badge-warning">{{ __('review.no_justification') }}</span>
                                            @endif
                                        </td>
                                        <td class="align-top! text-label">
                                            @if ($evaluation->reviews->isEmpty())
                                                {{-- A reviewer has the Review button; the others read that nobody reviewed it yet. --}}
                                                @unless ($canReview)<span class="a-badge a-badge-outline">{{ __('review.not_reviewed') }}</span>@endunless
                                            @else
                                                @include('review._opinions', ['reviews' => $evaluation->reviews, 'stale' => $isStale, 'counts' => $counts])
                                            @endif
                                        </td>
                                        <td class="align-top! text-right">
                                            {{-- The read-only evidence panel, loaded in place (the whole row opens it). --}}
                                            @php $label = ($canReview ? __('review.evidence.review') : __('review.evidence.open')).': '.$evaluation->requirement.' · '.($evaluation->item?->label() ?? __('evaluations.product')); @endphp
                                            <a href="{{ route('versions.review.evidence', [$version, $evaluation]) }}" data-row-link data-dialog-fetch="evidence"
                                               @class(['a-btn a-btn-sm a-btn-icon', 'a-btn-primary' => $canReview && in_array('todo', $row['facets'], true), 'a-btn-outline' => ! ($canReview && in_array('todo', $row['facets'], true))])
                                               aria-label="{{ $label }}" title="{{ $canReview ? __('review.evidence.review') : __('review.evidence.open') }}">
                                                <x-lucide-panel-right-open aria-hidden="true" />
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                    <p class="a-help px-4 py-3" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</p>
                </div>
            @endif
        </div>
        @endif

        <aside class="a-stack self-start">
            @if ($reviewers->isNotEmpty() || $selfDecisions->isNotEmpty())
                <section class="a-panel">
                    <div class="a-panel-head"><h2 class="a-h3">{{ __('review.reviewers') }}</h2></div>
                    <ul class="a-checklist">
                        @foreach ($reviewers as $member)
                            @php $decision = $decisions->firstWhere('reviewer_id', $member->user_id); @endphp
                            <li>
                                <span class="flex flex-wrap items-center gap-2 px-4 py-2 text-sm">
                                    <span class="min-w-0 flex-1">{{ $member->user->name }}
                                        <span class="a-row-sub">{{ $member->external ? __('roles.external') : __('roles.internal') }}</span></span>
                                    @if ($decision && ! $decision->self_review)
                                        <span @class(['a-badge', 'a-badge-success' => $decision->verdict === 'approved', 'a-badge-warning' => $decision->verdict !== 'approved'])>{{ __('review.decisions.'.$decision->verdict) }}</span>
                                    @else
                                        <span class="a-badge a-badge-outline">{{ __('review.pending') }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                        {{-- Self-reviews: shown as such; once a reviewer exists they no longer count. --}}
                        @foreach ($selfDecisions as $decision)
                            <li>
                                <span class="flex flex-wrap items-center gap-2 px-4 py-2 text-sm">
                                    <span class="min-w-0 flex-1">{{ $decision->reviewer->name }}
                                        <span class="a-row-sub">{{ $rights->selfMode ? __('review.self_reviewed') : __('review.self_not_counted') }}</span></span>
                                    <span @class(['a-badge', 'a-badge-success' => $decision->verdict === 'approved', 'a-badge-warning' => $decision->verdict !== 'approved'])>{{ __('review.decisions.'.$decision->verdict) }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($canReview)
                <form method="POST" action="{{ route('versions.review.decide', $version) }}" class="a-panel">
                    @csrf
                    <div class="a-panel-head"><h2 class="a-h3">{{ __('review.decision') }}</h2>@if ($rights->isSelf())<span class="a-badge a-badge-warning"><x-lucide-user-round-x aria-hidden="true" />{{ __('review.self_review') }}</span>@endif</div>
                    <div class="a-panel-body a-stack gap-3!">
                        <fieldset class="a-fieldset a-stack gap-2!">
                            <legend class="a-sr-only">{{ __('review.decision') }}</legend>
                            @foreach (\App\Models\VersionReview::VERDICTS as $verdict)
                                <label class="a-toggle-card"><input type="radio" name="verdict" value="{{ $verdict }}" @checked($loop->first)> <strong>{{ __('review.decisions.'.$verdict) }}</strong></label>
                            @endforeach
                        </fieldset>
                        <div class="a-field">
                            <label class="a-label" for="comment">{{ __('review.comment') }}</label>
                            <textarea id="comment" name="comment" class="a-textarea" rows="3" maxlength="5000"></textarea>
                            @error('comment')<p class="a-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="a-panel-foot">
                        <button class="a-btn a-btn-primary" type="submit"><x-lucide-check aria-hidden="true" />{{ __('common.save') }}</button>
                    </div>
                </form>
            @endif
        </aside>
    </div>

    {{-- One evaluation, read-only: loaded in place by data-dialog-fetch, or /review/evaluations/{id} without script. --}}
    <x-ui.drawer id="evidence" wide :open="$open !== null" :close-url="route('versions.review', $version)">
        @if ($open)
            <x-slot:eyebrow>{{ $pack->standardName($open->standard) }}</x-slot:eyebrow>
            <x-slot:heading>{{ $open->requirement }} · {{ $open->item?->label() ?? __('evaluations.product') }}</x-slot:heading>
            @include('review._evidence', ['evaluation' => $open, 'evidence' => $evidence, 'row' => $meta[$open->id], 'pack' => $pack, 'version' => $version, 'date' => $date, 'isStale' => $isStale, 'counts' => $counts, 'changes' => $changes])
            @if ($canReview)
                <x-slot:footer>
                    <div class="a-evidence-review">
                        <p class="a-micro">{{ __('review.your_review') }}@if ($rights->isSelf()) · {{ __('review.self_review') }}@endif</p>
                        @include('review._opinion-form', ['action' => route('versions.review.evaluation', [$version, $open]), 'mine' => $meta[$open->id]['mine'], 'changed' => $meta[$open->id]['mineChanged']])
                    </div>
                </x-slot:footer>
            @endif
        @endif
    </x-ui.drawer>
</x-layouts.app>
