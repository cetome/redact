{{--
    Risk assessment page, under the register: the risks against the essential requirements the
    previous step (Essential requirements, route versions.scoping) put in scope (ArticlesCheck):
    per article, the risks that concern it and the mismatches (risks on an article not in scope,
    an article in scope that no risk concerns); per EN 18031 part in scope, the requirements the
    risks name (directly or through their family) and those no risk names; the mechanisms named in
    no part in scope. Informative, never blocking.
    @include('risks._coverage', ['articles' => [...], 'coverage' => [...], 'outside' => [...], 'editable' => bool])
--}}
@php
    $issues = count(array_filter($articles, fn ($a) => $a['issue'] !== null));
    $riskUrl = fn (string $id) => route('versions.risks.edit', [$version, $id]);
    $scopingUrl = route('versions.scoping', $version);
    $library = route('versions.risks.library.show', $version);
    $unanswered = collect($articles)->every(fn ($a) => $a['in_scope'] === null);
@endphp
<section class="a-panel a-risk-cover" id="risk-coverage" aria-labelledby="risk-coverage-title">
    <div class="a-panel-head">
        <h2 class="a-h3 flex items-center gap-2" id="risk-coverage-title">{{ __('articles.check_title') }} <x-ui.tip :text="__('articles.check_help')" /></h2>
        <span @class(['a-badge', 'a-badge-warning' => $issues > 0, 'a-badge-success' => $issues === 0])>
            @if ($issues > 0)<x-lucide-triangle-alert aria-hidden="true" />@else<x-lucide-check aria-hidden="true" />@endif
            {{ trans_choice('articles.issues', $issues, ['count' => $issues]) }}
        </span>
    </div>
    @if ($unanswered)
        <p class="a-art-norisk">
            <x-lucide-target aria-hidden="true" />
            <span>{{ __('articles.ok_unanswered') }}</span>
            <a href="{{ $scopingUrl }}" class="a-btn a-btn-outline a-btn-sm">{{ __('articles.go_scoping') }}<x-lucide-arrow-right aria-hidden="true" /></a>
        </p>
    @endif
    <table class="a-table a-table-compact a-art-table">
        <thead>
            <tr>
                <th scope="col">{{ __('articles.col_article') }}</th>
                <th scope="col" class="is-shrink">{{ __('articles.col_scope') }}</th>
                <th scope="col">{{ __('articles.col_risks') }}</th>
                <th scope="col">{{ __('articles.col_check') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($articles as $article)
                <tr id="article-{{ \Illuminate\Support\Str::slug($article['code']) }}" @class(['a-art-row', 'has-issue' => $article['issue'] !== null])>
                    <th scope="row" class="a-art-name">{{ $article['name'] }}</th>
                    <td class="is-shrink" data-label="{{ __('articles.col_scope') }}">
                        @if ($article['in_scope'] === true)
                            <span class="a-badge a-badge-success"><x-lucide-circle-check aria-hidden="true" />{{ __('articles.in_scope') }}</span>
                        @elseif ($article['in_scope'] === false)
                            <span class="a-badge a-badge-outline">{{ __('articles.out_of_scope') }}</span>
                        @else
                            <span class="a-badge a-badge-neutral">{{ __('articles.not_answered') }}</span>
                        @endif
                    </td>
                    <td data-label="{{ __('articles.col_risks') }}">
                        @if ($article['risks'] === [])
                            <span class="a-muted">0</span>
                        @else
                            <span class="a-art-risks">
                                <b class="a-art-count">{{ count($article['risks']) }}</b>
                                @foreach (array_slice($article['risks'], 0, 6) as $risk)
                                    <a href="{{ $riskUrl($risk['id']) }}" class="a-chip a-chip-sm a-art-risk" data-dialog-fetch="risk-edit" title="{{ $risk['threat'] }}"
                                       aria-label="{{ __('articles.risk_link', ['n' => $risk['n'], 'threat' => $risk['threat']]) }}">#{{ $risk['n'] }}</a>
                                @endforeach
                                @if (count($article['risks']) > 6)
                                    <span class="a-chip a-chip-sm" title="{{ implode(', ', array_map(fn ($r) => '#'.$r['n'], array_slice($article['risks'], 6))) }}">+{{ count($article['risks']) - 6 }}</span>
                                @endif
                            </span>
                        @endif
                    </td>
                    <td data-label="{{ __('articles.col_check') }}">
                        @if ($article['issue'] === 'risks_out_of_scope')
                            <p class="a-art-issue">
                                <x-lucide-triangle-alert aria-hidden="true" />
                                <span>
                                    {{ trans_choice('articles.risks_out_of_scope', count($article['risks']), ['count' => count($article['risks']), 'article' => $article['name']]) }}
                                    @if ($article['questions'] !== [])
                                        <span class="a-art-cards">{{ __('articles.check_cards') }}
                                            @foreach ($article['questions'] as $question)
                                                <a href="{{ $scopingUrl }}#scope-q-{{ $question['key'] }}" class="a-art-card-link">{{ $question['label'] }}</a>@if (! $loop->last)<span aria-hidden="true">,</span>@endif
                                            @endforeach
                                        </span>
                                    @endif
                                </span>
                            </p>
                        @elseif ($article['issue'] === 'no_risk')
                            <p class="a-art-issue">
                                <x-lucide-triangle-alert aria-hidden="true" />
                                <span>
                                    {{ __('articles.no_risk', ['article' => $article['name']]) }}
                                    @if ($editable)
                                        <a href="{{ $library }}" class="a-art-card-link" data-dialog-open="risk-library">{{ __('articles.add_from_library') }}</a>
                                    @endif
                                </span>
                            </p>
                        @elseif ($article['in_scope'] === null)
                            <span class="a-muted">—</span>
                        @else
                            <span class="a-art-ok"><x-lucide-check aria-hidden="true" />{{ $article['in_scope'] ? __('articles.ok_in') : __('articles.ok_out') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Per part in scope: the requirements the risks name (informative, the decision trees decide). --}}
    @foreach ($coverage as $part)
        <section class="a-art-part" aria-labelledby="part-{{ $part['code'] }}">
            <div class="a-art-part-head">
                <h3 class="a-art-part-name" id="part-{{ $part['code'] }}">{{ $part['name'] }}</h3>
                <span class="a-art-part-count">{{ __('articles.covered_count', ['covered' => count($part['covered']), 'total' => $part['total']]) }}</span>
                <x-ui.tip :text="__('articles.coverage_help')" />
                <x-ui.progress :value="count($part['covered'])" :max="$part['total']" />
            </div>
            @if ($part['uncovered'] !== [])
                <div class="a-art-uncovered">
                    <span class="a-micro">{{ __('articles.uncovered') }} · {{ count($part['uncovered']) }}</span>
                    <span class="a-chips">
                        @foreach ($part['uncovered'] as $requirement)
                            <span class="a-chip a-chip-sm a-chip-code a-art-free" title="{{ $requirement['title'] }}">{{ $requirement['code'] }}</span>
                        @endforeach
                    </span>
                </div>
            @endif
            @if ($part['covered'] === [])
                <p class="a-help px-4 pb-2">{{ __('articles.none_covered') }}</p>
            @else
                <details class="a-art-named">
                    <summary>{{ __('articles.named') }} <span class="a-seg-count">{{ count($part['covered']) }}</span></summary>
                    <table class="a-table a-table-compact a-art-cover">
                        <caption class="a-sr-only">{{ $part['name'] }} · {{ __('articles.col_covered_by') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('articles.col_requirement') }}</th>
                                <th scope="col">{{ __('articles.col_covered_by') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($part['covered'] as $requirement)
                                <tr>
                                    <th scope="row" class="a-art-req"><span class="a-code">{{ $requirement['code'] }}</span> <span class="a-muted">{{ $requirement['title'] }}</span></th>
                                    <td>
                                        <span class="a-chips">
                                            @foreach (array_slice($requirement['risks'], 0, 8) as $risk)
                                                <a href="{{ $riskUrl($risk['id']) }}" @class(['a-chip a-chip-sm a-art-risk', 'is-via' => $risk['via']]) data-dialog-fetch="risk-edit"
                                                   title="{{ $risk['threat'] }}@if ($risk['via']) · {{ __('articles.via_family', ['family' => $risk['via']]) }}@endif"
                                                   aria-label="{{ __('articles.risk_link', ['n' => $risk['n'], 'threat' => $risk['threat']]) }}@if ($risk['via']), {{ __('articles.via_family', ['family' => $risk['via']]) }}@endif">#{{ $risk['n'] }}</a>
                                            @endforeach
                                            @if (count($requirement['risks']) > 8)<span class="a-chip a-chip-sm">+{{ count($requirement['risks']) - 8 }}</span>@endif
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @endif
        </section>
    @endforeach
    @if ($coverage === [] && ! $unanswered)
        <p class="a-help px-4 py-2">{{ __('articles.no_part') }}</p>
    @endif
    @if ($outside !== [])
        <div class="a-art-uncovered a-art-outside">
            <span class="a-micro">{{ __('articles.outside_scope') }} <x-ui.tip :text="__('articles.outside_scope_help')" /></span>
            <span class="a-chips">
                @foreach ($outside as $code)
                    <span class="a-chip a-chip-sm a-chip-code a-art-free">{{ $code }}</span>
                @endforeach
            </span>
        </div>
    @endif
</section>
