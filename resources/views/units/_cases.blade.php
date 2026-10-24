{{--
    The assessment units of the standard per standard and requirement (conceptual, functional
    completeness, functional sufficiency), with their texts and the verdict proposed for the
    conceptual case. Two modes:
    - 'provide' ("Assessment units" step): the manufacturer records each result with its evidence
      ($canRecord); keys in assessment-cases.js: j / k current unit, 1 2 3 4 verdict, mod+s record.
    - 'review' (Review step, "Assessment units" view): the results read-only, each with its reviews and
      the Agree / Disagree / Question form ($canReview); keys: j / k, a d q. Nothing links to an editor.
    @include('units._cases', ['cases', 'mode', 'canRecord', 'canUpload', 'documents', 'version', 'me'] + review mode: ['canReview', 'counts' => ReviewRights::counts()])
    'external' => true: performed outside REDact, the results recorded before only (no "who records" line).
--}}
@php
    $mode ??= 'provide';
    $review = $mode === 'review';
    $canRecord = ! $review && ($canRecord ?? false);
    $canReview = $review && ($canReview ?? false);
    $documents ??= collect();
    $timezone = $me->timezone ?: config('app.timezone');
    $date = fn ($value) => $value?->copy()->timezone($timezone)->isoFormat('D MMMM YYYY');
    $keys = ['PASS' => '1', 'FAIL' => '2', 'NOT_APPLICABLE' => '3', 'INCONCLUSIVE' => '4'];
    $figures = $cases['counts'];
    // Review mode: a review is stale once the result changed after it.
    $isStale = fn ($r) => $r->fingerprint !== $r->result->fingerprint();
@endphp
<div class="min-w-0" data-filter-scope data-cases @if ($review) data-cases-review @endif>
    {{-- Who records, for people who see the draft without recording it (reviewers); the demo, a locked
         product, support access and a submitted version already say why at the top of the page. --}}
    @if (! $canRecord && ! $review && ! ($external ?? false) && $version->isEditable() && ! $access->isReadOnlyProduct() && ! $access->isSupportAccess())
        <p class="a-help mb-2 flex items-center gap-1.5"><x-lucide-info class="size-3.5 shrink-0" aria-hidden="true" />{{ __('assessment.read_only') }}</p>
    @endif

    @if ($cases['standards'] === [] || $figures['total'] === 0)
        <x-ui.empty icon="flask-conical" :title="__('assessment.empty')" :text="__('assessment.empty_help')" />
    @else
        @php
            // The facets of every unit with a verdict to give, for the filter buttons and the rows.
            $facetsOf = [];
            foreach ($cases['standards'] as $standard) {
                foreach ($standard['requirements'] as $requirement) {
                    foreach ($requirement['cases'] as $case) {
                        if ($case['informative']) {
                            continue;
                        }
                        $result = $case['result'];
                        $mine = $result?->reviews->firstWhere('reviewer_id', $me->id);
                        $facetsOf[$standard['code'].'|'.$requirement['code'].'|'.$case['key']] = $review
                            ? array_keys(array_filter([
                                'todo' => $result && ($canReview ? (! $mine || $isStale($mine)) : $result->reviews->isEmpty()),
                                'missing' => ! $result,
                                'disagree' => $result && $result->reviews->contains('verdict', 'disagree'),
                                'question' => $result && $result->reviews->contains('verdict', 'question'),
                                'changed' => $result && $result->reviews->contains(fn ($r) => $isStale($r)),
                            ]))
                            : array_keys(array_filter([
                                'todo' => ! $result,
                                'fail' => $result?->verdict === \App\Enums\Verdict::Fail,
                                'inconclusive' => $result?->verdict === \App\Enums\Verdict::Inconclusive,
                                'stale' => $case['stale'],
                            ]));
                    }
                }
            }
            $facetCount = fn (string $facet) => collect($facetsOf)->filter(fn ($f) => in_array($facet, $f, true))->count();
        @endphp
        <div class="a-toolbar">
            <x-ui.search target="#case-rows" :placeholder="__('assessment.search')" />
            {{-- Outside REDact the results recorded before only: nothing to filter by state. --}}
            @unless ($external ?? false)
            <div class="a-seg" role="group" aria-label="{{ __('assessment.show_only') }}" data-facet-target="#case-rows">
                <button type="button" class="a-seg-btn" data-facet="" aria-pressed="true">{{ __('assessment.facet_all') }} <span class="a-seg-count">{{ $figures['total'] }}</span></button>
                @if ($review)
                    <button type="button" class="a-seg-btn" data-facet="todo" aria-pressed="false">{{ $canReview ? __('review.facet_todo') : __('review.facet_unreviewed') }} <span class="a-seg-count">{{ $facetCount('todo') }}</span></button>
                    <button type="button" class="a-seg-btn" data-facet="missing" aria-pressed="false">{{ __('assessment.not_recorded') }} <span @class(['a-seg-count', 'is-warning' => $facetCount('missing')])>{{ $facetCount('missing') }}</span></button>
                    <button type="button" class="a-seg-btn" data-facet="changed" aria-pressed="false">{{ __('review.stale') }} <span @class(['a-seg-count', 'is-warning' => $facetCount('changed')])>{{ $facetCount('changed') }}</span></button>
                    <button type="button" class="a-seg-btn" data-facet="disagree" aria-pressed="false">{{ __('review.verdicts.disagree') }} <span class="a-seg-count">{{ $facetCount('disagree') }}</span></button>
                    <button type="button" class="a-seg-btn" data-facet="question" aria-pressed="false">{{ __('review.verdicts.question') }} <span class="a-seg-count">{{ $facetCount('question') }}</span></button>
                @else
                    <button type="button" class="a-seg-btn" data-facet="todo" aria-pressed="false">{{ __('assessment.facet_todo') }} <span class="a-seg-count">{{ $figures['total'] - $figures['recorded'] }}</span></button>
                    <button type="button" class="a-seg-btn" data-facet="fail" aria-pressed="false">{{ __('assessment.facet_fail') }} <span class="a-seg-count">{{ $figures['fail'] }}</span></button>
                    <button type="button" class="a-seg-btn" data-facet="inconclusive" aria-pressed="false">{{ __('verdicts.INCONCLUSIVE') }} <span @class(['a-seg-count', 'is-warning' => $figures['inconclusive']])>{{ $figures['inconclusive'] }}</span></button>
                    <button type="button" class="a-seg-btn" data-facet="stale" aria-pressed="false">{{ __('assessment.facet_stale') }} <span @class(['a-seg-count', 'is-warning' => $figures['stale']])>{{ $figures['stale'] }}</span></button>
                @endif
            </div>
            @endunless
            <span class="a-toolbar-count" data-filter-count data-template="{{ __('ui.shown') }}"></span>
            {{-- Keys handled by assessment-cases.js, listed in the ? help. --}}
            <span hidden data-shortcut="j" data-shortcut-doc data-shortcut-label="{{ __('assessment.keys.next') }}"></span>
            <span hidden data-shortcut="k" data-shortcut-doc data-shortcut-label="{{ __('assessment.keys.previous') }}"></span>
            @if ($canRecord)
                <span hidden data-shortcut="1" data-shortcut-doc data-shortcut-label="{{ __('assessment.keys.pass') }}"></span>
                <span hidden data-shortcut="2" data-shortcut-doc data-shortcut-label="{{ __('assessment.keys.fail') }}"></span>
                <span hidden data-shortcut="3" data-shortcut-doc data-shortcut-label="{{ __('assessment.keys.na') }}"></span>
                <span hidden data-shortcut="4" data-shortcut-doc data-shortcut-label="{{ __('assessment.keys.inconclusive') }}"></span>
                <span hidden data-shortcut="mod+s" data-shortcut-doc data-shortcut-label="{{ __('assessment.keys.save') }}"></span>
            @endif
            @if ($canReview)
                <span hidden data-shortcut="a" data-shortcut-doc data-shortcut-label="{{ __('assessment.keys.agree') }}"></span>
                <span hidden data-shortcut="d" data-shortcut-doc data-shortcut-label="{{ __('assessment.keys.disagree') }}"></span>
                <span hidden data-shortcut="q" data-shortcut-doc data-shortcut-label="{{ __('assessment.keys.question') }}"></span>
            @endif
        </div>

        <div id="case-rows" class="a-cases">
            @foreach ($cases['standards'] as $standard)
                <section class="a-cases-standard" data-filter-group aria-labelledby="cases-{{ $standard['code'] }}">
                    <h2 class="a-cases-standard-head" id="cases-{{ $standard['code'] }}">{{ $standard['name'] }} <span class="a-muted">· {{ trans_choice('assessment.requirement_count', count($standard['requirements']), ['count' => count($standard['requirements'])]) }}</span></h2>
                    @foreach ($standard['requirements'] as $requirement)
                        @php $s = $requirement['summary']; @endphp
                        <article class="a-case-req" data-filter-group id="req-{{ $standard['code'] }}-{{ $requirement['code'] }}">
                            <header class="a-case-req-head">
                                <h3 class="a-case-req-title">
                                    @if ($review)
                                        <span class="a-code">{{ $requirement['code'] }}</span>
                                    @else
                                        <a class="a-code" href="{{ route('versions.evaluate.requirement', [$version, $standard['code'], $requirement['code']]) }}" title="{{ __('assessment.open_trees', ['code' => $requirement['code']]) }}">{{ $requirement['code'] }}</a>
                                    @endif
                                    <span class="min-w-0">{{ $requirement['title'] }}</span>
                                    @if ($requirement['clause'] !== '')<span class="a-chip a-chip-sm a-chip-code">{{ $requirement['clause'] }}</span>@endif
                                </h3>
                                <span class="a-case-req-trees" aria-label="{{ __('assessment.trees') }}">
                                    @if ($s['pass'])<x-verdict verdict="PASS" :icon="false" /><b>{{ $s['pass'] }}</b>@endif
                                    @if ($s['fail'])<x-verdict verdict="FAIL" :icon="false" /><b>{{ $s['fail'] }}</b>@endif
                                    @if ($s['na'])<x-verdict verdict="NOT_APPLICABLE" :icon="false" /><b>{{ $s['na'] }}</b>@endif
                                    @if ($s['inconclusive'])<x-verdict verdict="INCONCLUSIVE" :icon="false" /><b>{{ $s['inconclusive'] }}</b>@endif
                                    @if ($s['unjustified'])<x-eval-status status="unjustified" /><b>{{ $s['unjustified'] }}</b>@endif
                                    @if ($s['pending'])<x-eval-status status="missing" /><b>{{ $s['pending'] }}</b>@endif
                                </span>
                            </header>

                            @if ($requirement['criteria'])
                                @include('assessment._criteria', ['criteria' => $requirement['criteria'], 'standardName' => $standard['name'], 'withCases' => false, 'open' => false])
                            @endif

                            @if ($requirement['cases'] === [])
                                <p class="a-help a-case-none">{{ __('assessment.no_cases') }}</p>
                            @endif

                            @foreach ($requirement['cases'] as $case)
                                @if ($case['informative'])
                                    {{-- The standard only states something here ("None.", "covered by…"): nothing to record. --}}
                                    <div class="a-case is-informative">
                                        <div class="a-case-head">
                                            <span class="a-case-name">{{ $case['label'] }}</span>
                                            @if ($case['clause'] !== '')<span class="a-chip a-chip-sm a-chip-code">{{ $case['clause'] }}</span>@endif
                                            <span class="a-spacer"></span>
                                            <span class="a-badge a-badge-neutral" title="{{ __('assessment.informative') }}"><x-lucide-info aria-hidden="true" />{{ __('assessment.informative_short') }}</span>
                                        </div>
                                        {{ \App\Assessment\CriteriaText::html($case['text']) }}
                                    </div>
                                    @continue
                                @endif
                                @php
                                    $result = $case['result'];
                                    $anchor = \App\Assessment\AssessmentCases::anchor($standard['code'], $requirement['code'], $case['key']);
                                    $bag = $errors->getBag($anchor);
                                    $posted = old('standard') === $standard['code'] && old('requirement') === $requirement['code'] && old('case') === $case['key'];
                                    $current = $posted ? old('verdict') : $result?->verdict?->value;
                                    $facets = $facetsOf[$standard['code'].'|'.$requirement['code'].'|'.$case['key']] ?? [];
                                    $suggestion = $case['suggestion'];
                                    $mine = $review ? $result?->reviews->firstWhere('reviewer_id', $me->id) : null;
                                    $staleReviews = $review && $result ? $result->reviews->filter(fn ($r) => $isStale($r)) : collect();
                                @endphp
                                <div id="{{ $anchor }}" @class(['a-case', 'is-recorded' => $result, 'has-errors' => $bag->any(), 'is-changed' => $staleReviews->isNotEmpty()])
                                     data-filter-item data-case-row data-facet-value="{{ implode(' ', $facets) }}"
                                     data-search="{{ $requirement['code'] }} {{ $requirement['title'] }} {{ $case['label'] }} {{ $result?->verdict?->label() }}">
                                    <div class="a-case-head">
                                        <span class="a-case-name">{{ $case['label'] }}</span>
                                        @if ($case['clause'] !== '')<span class="a-chip a-chip-sm a-chip-code">{{ $case['clause'] }}</span>@endif
                                        <span class="a-spacer"></span>
                                        @if ($result)
                                            {{-- While recording, the chosen chip shows the verdict. --}}
                                            @unless ($canRecord)<x-verdict :verdict="$result->verdict" />@endunless
                                            @if ($result->independent)
                                                <span class="a-badge a-badge-success" title="{{ __('assessment.independent_help') }}"><x-lucide-user-round-check aria-hidden="true" />{{ __('assessment.independent') }}</span>
                                            @else
                                                <span class="a-badge a-badge-outline" title="{{ __('assessment.self_help') }}"><x-lucide-user-round aria-hidden="true" />{{ __('assessment.self_short') }}</span>
                                            @endif
                                            @if ($case['stale'])
                                                <span class="a-badge a-badge-warning" title="{{ __('assessment.stale_help') }}"><x-lucide-history aria-hidden="true" />{{ __('assessment.stale') }}</span>
                                            @endif
                                            <span class="a-case-by">{{ __('assessment.recorded_by', ['name' => $result->recorder?->name ?? '—', 'date' => $date($result->updated_at)]) }}</span>
                                        @else
                                            <span @class(['a-badge', 'a-badge-outline' => ! $review, 'a-badge-warning' => $review])>{{ __('assessment.not_recorded') }}</span>
                                        @endif
                                    </div>

                                    <details class="a-case-standard">
                                        <summary>{{ __('assessment.what_standard_asks') }}</summary>
                                        @include('assessment._case-texts', ['case' => $case])
                                    </details>

                                    @if ($suggestion && ! $review)
                                        <p class="a-case-suggest">
                                            <x-lucide-git-fork aria-hidden="true" />
                                            <span class="a-case-suggest-label">{{ __('assessment.suggested') }} <x-ui.tip :text="__('assessment.suggested_help')" /></span>
                                            @if ($suggestion['verdict'])<x-verdict :verdict="$suggestion['verdict']" />@endif
                                            <span class="a-muted">{{ implode(' ', $suggestion['reasons']) }}</span>
                                        </p>
                                    @endif

                                    @if ($canRecord)
                                        <form method="POST" action="{{ route('versions.units.record', $version) }}" class="a-case-form" data-case-form>
                                            @csrf
                                            <input type="hidden" name="standard" value="{{ $standard['code'] }}">
                                            <input type="hidden" name="requirement" value="{{ $requirement['code'] }}">
                                            <input type="hidden" name="case" value="{{ $case['key'] }}">
                                            <fieldset class="a-case-verdicts">
                                                <legend class="a-sr-only">{{ __('assessment.verdict') }}</legend>
                                                @foreach ($case['verdicts'] as $v)
                                                    <label @class(['a-chip-toggle a-case-pick', 'is-'.strtolower(str_replace('_', '', $v->value))])>
                                                        <input type="radio" name="verdict" value="{{ $v->value }}" required @checked($current === $v->value) data-case-verdict="{{ $keys[$v->value] }}">
                                                        <span>{{ $v->label() }}</span><kbd class="a-kbd a-kbd-hint" aria-hidden="true">{{ $keys[$v->value] }}</kbd>
                                                    </label>
                                                @endforeach
                                                @if ($bag->has('verdict'))<p class="a-error">{{ $bag->first('verdict') }}</p>@endif
                                            </fieldset>
                                            <div class="a-field a-case-evidence">
                                                <label class="a-label" for="{{ $anchor }}-evidence">{{ __('assessment.evidence') }} <x-ui.tip :text="__('assessment.evidence_help', ['min' => 20])" /></label>
                                                <textarea id="{{ $anchor }}-evidence" name="evidence" class="a-textarea" rows="2" maxlength="20000" minlength="20" required
                                                          placeholder="{{ __('assessment.evidence_placeholder') }}" data-case-evidence @if ($bag->has('evidence')) aria-invalid="true" @endif>{{ $posted ? old('evidence') : $result?->evidence }}</textarea>
                                                @if ($bag->has('evidence'))<p class="a-error">{{ $bag->first('evidence') }}</p>@endif
                                            </div>
                                            <div class="a-case-foot">
                                                <label class="a-sr-only" for="{{ $anchor }}-document">{{ __('assessment.document') }}</label>
                                                @if ($documents->isNotEmpty())
                                                    <span class="a-case-doc-pick">
                                                    <x-lucide-paperclip class="a-case-clip" aria-hidden="true" />
                                                    <select id="{{ $anchor }}-document" name="document" class="a-select a-input-sm a-case-document" title="{{ __('assessment.document') }}">
                                                        <option value="">{{ __('assessment.document_none') }}</option>
                                                        @foreach ($documents as $document)
                                                            <option value="{{ $document->id }}" @selected(($posted ? old('document') : $result?->document_id) === $document->id)>{{ $document->original_name }}</option>
                                                        @endforeach
                                                    </select>
                                                    </span>
                                                @elseif (($canUpload ?? true) && Route::has('versions.documents'))
                                                    <a class="a-link-btn text-label" href="{{ route('versions.documents', $version) }}"><x-lucide-paperclip aria-hidden="true" />{{ __('assessment.add_document') }}</a>
                                                @endif
                                                <span class="a-spacer"></span>
                                                <button type="submit" @class(['a-btn a-btn-sm', 'a-btn-primary' => ! $result || $case['stale'], 'a-btn-outline' => $result && ! $case['stale']]) data-case-save>
                                                    <x-lucide-check aria-hidden="true" />{{ $result ? __('assessment.record_again') : __('assessment.record') }}
                                                </button>
                                            </div>
                                        </form>
                                    @elseif ($result)
                                        <div class="a-case-evidence-text">
                                            <p class="a-micro">{{ __('assessment.evidence') }}</p>
                                            <p class="whitespace-pre-line">{{ $result->evidence }}</p>
                                            @if ($result->document && Route::has('versions.documents.download'))
                                                <a class="a-link-btn text-label" href="{{ route('versions.documents.download', [$version, $result->document]) }}"><x-lucide-paperclip aria-hidden="true" />{{ $result->document->original_name }}</a>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($review && $result)
                                        @foreach ($staleReviews->groupBy('fingerprint') as $group)
                                            @include('review._unit-changes', ['group' => $group, 'result' => $result, 'date' => $date, 'open' => $group->contains('reviewer_id', $me->id)])
                                        @endforeach
                                        @include('review._opinions', ['reviews' => $result->reviews, 'stale' => $isStale, 'counts' => $counts])
                                        @if ($canReview)
                                            @include('review._opinion-form', ['action' => route('versions.review.unit', [$version, $result]), 'mine' => $mine, 'changed' => $mine && $isStale($mine), 'bag' => $anchor])
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        </article>
                    @endforeach
                </section>
            @endforeach
            <p class="a-help px-1 py-3" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</p>
        </div>
    @endif
</div>
