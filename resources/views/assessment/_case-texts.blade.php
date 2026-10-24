{{-- The standard's texts of one assessment case: purpose, preconditions, units, assignment of verdict.
     $case: purpose, preconditions, units, rules (tree label => text) as in AssessmentCases. --}}
@php
    $html = fn ($value) => \App\Assessment\CriteriaText::html($value);
@endphp
@if (! empty($case['text']))
    {{ $html($case['text']) }}
@endif
<dl class="a-criteria-dl">
    @foreach (['purpose', 'preconditions', 'units'] as $part)
        @if (! empty($case[$part]))
            <div><dt>{{ __('assessment.'.$part) }}</dt><dd>{{ $html($case[$part]) }}</dd></div>
        @endif
    @endforeach
    @if (! empty($case['rules']))
        <div>
            <dt>{{ __('assessment.verdict_rules') }}</dt>
            <dd>
                <ul class="a-criteria-verdicts">
                    @foreach ($case['rules'] as $label => $rule)
                        @php $v = \App\Enums\Verdict::tryFrom(str_replace(' ', '_', (string) $label)); @endphp
                        <li><x-verdict :verdict="$v" :icon="false" />{{ $html($rule) }}</li>
                    @endforeach
                </ul>
            </dd>
        </div>
    @endif
</dl>
