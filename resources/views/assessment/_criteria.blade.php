{{--
    The standard's "Assessment criteria" of one requirement (content pack assessments/<std>/<REQ>.json)
    in a collapsible panel: objective, implementation categories, required information, justification
    rules and, with $withCases, the assessment cases. Texts in the page's language when the pack has
    it (cetome's translation, the English text prevails), else in English marked lang="en".

    @include('assessment._criteria', ['criteria' => $criteria, 'standardName' => '…', 'withCases' => true, 'open' => false])
--}}
@php
    $html = fn ($value) => \App\Assessment\CriteriaText::html($value);
    $of = fn ($value) => \App\Assessment\CriteriaText::of($value);
    $clause = (string) ($criteria['clause'] ?? '');
    $withCases = $withCases ?? true;
@endphp
<details class="a-collapse a-criteria" @if ($open ?? false) open @endif>
    <summary>
        <x-lucide-book-open-check aria-hidden="true" />
        <span class="min-w-0 flex-1">{{ $clause !== '' ? __('assessment.criteria', ['standard' => $standardName, 'clause' => $clause]) : __('assessment.criteria_no_clause', ['standard' => $standardName]) }}</span>
    </summary>
    <div class="a-criteria-body">
        <p class="a-help">{{ __('assessment.criteria_help') }}</p>
        <dl class="a-criteria-dl">
            @if (! empty($criteria['objective']))
                <div><dt>{{ __('assessment.objective') }}</dt><dd>{{ $html($criteria['objective']) }}</dd></div>
            @endif
            @if (! empty($criteria['implementation_categories']))
                <div><dt>{{ __('assessment.implementation_categories') }}</dt><dd>{{ $html($criteria['implementation_categories']) }}</dd></div>
            @endif
            @if (! empty($criteria['required_information']))
                <div>
                    <dt>{{ __('assessment.required_information') }}</dt>
                    <dd>
                        <ul class="a-criteria-info">
                            @foreach ($criteria['required_information'] as $info)
                                @php $t = $of($info['text'] ?? ''); $c = $of($info['condition'] ?? null); @endphp
                                <li @class(['is-child' => ! empty($info['parent'])])>
                                    <span class="a-code">{{ $info['id'] ?? '' }}</span>
                                    {{ $html($info['text'] ?? '', 'a-criteria-text a-criteria-info-text') }}
                                    @if ($c['text'] !== '')
                                        <span class="a-criteria-condition"><b>{{ __('assessment.condition') }}</b> <span @if ($c['lang']) lang="{{ $c['lang'] }}" @endif>{{ $c['text'] }}</span></span>
                                    @endif
                                    @php $n = $of($info['note'] ?? null); @endphp
                                    @if ($n['text'] !== '')
                                        <span class="a-criteria-note"><b>{{ __('assessment.note') }}</b> <span @if ($n['lang']) lang="{{ $n['lang'] }}" @endif>{{ $n['text'] }}</span></span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </dd>
                </div>
            @endif
            @if (! empty($criteria['justification']))
                <div>
                    <dt>{{ __('assessment.justification_rules') }}</dt>
                    <dd>
                        <ul class="a-criteria-info">
                            @foreach ($criteria['justification'] as $rule)
                                @php $t = $of($rule['text'] ?? ''); @endphp
                                <li>
                                    <span class="a-chip a-chip-sm a-chip-code">{{ $rule['node'] ?? '' }}@if (array_key_exists('tree_node', $rule) && $rule['tree_node'] !== ($rule['node'] ?? null)) ({{ $rule['tree_node'] ? __('assessment.tree_node', ['node' => $rule['tree_node']]) : __('assessment.informative_rule') }})@endif @if (! empty($rule['outcome'])) → {{ $rule['outcome'] }}@endif</span>
                                    {{ $html($rule['text'] ?? '', 'a-criteria-text a-criteria-info-text') }}
                                </li>
                            @endforeach
                        </ul>
                    </dd>
                </div>
            @endif
        </dl>
        @if ($withCases && ! empty($criteria['cases']))
            <p class="a-micro mt-3">{{ __('assessment.cases_title') }}</p>
            @foreach ($criteria['cases'] as $case)
                <section class="a-criteria-case">
                    <h3 class="a-criteria-case-title">{{ \App\Assessment\AssessmentCases::label((string) ($case['key'] ?? '')) }}
                        @if (! empty($case['clause']))<span class="a-chip a-chip-sm a-chip-code">{{ $case['clause'] }}</span>@endif</h3>
                    @include('assessment._case-texts', ['case' => ['text' => $case['text'] ?? null, 'purpose' => $case['purpose'] ?? null, 'preconditions' => $case['preconditions'] ?? null, 'units' => $case['units'] ?? null, 'rules' => (array) ($case['verdict'] ?? [])]])
                </section>
            @endforeach
        @endif
    </div>
</details>
