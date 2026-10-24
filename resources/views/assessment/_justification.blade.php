{{--
    Under the justification of a walked evaluation: is it justified the way the standard asks?
    $check: App\Assessment\JustificationCheck of the saved walk. Each E.Info the rule names shows
    documented, missing (a link to that field on the E.Info page) or not required for this object.
--}}
@php
    $min = \App\Assessment\Justification::MIN_LENGTH;
    $of = fn ($value) => \App\Assessment\CriteriaText::of($value);
@endphp
<div @class(['a-just', 'is-ok' => $check->justified, 'is-bad' => ! $check->justified]) data-justification>
    <p class="a-just-head">
        @if ($check->toCheck() > 0)<span class="a-badge a-badge-warning" title="{{ __('assessment.to_check_help') }}"><x-lucide-eye aria-hidden="true" />{{ trans_choice('assessment.to_check_count', $check->toCheck(), ['count' => $check->toCheck()]) }}</span>@endif
        @if ($check->inconclusive)
            <x-eval-status status="inconclusive" />
        @elseif ($check->justified)
            <span class="a-badge a-badge-success"><x-lucide-scale aria-hidden="true" />{{ __('assessment.justified') }}</span>
        @else
            <x-eval-status status="unjustified" />
        @endif
        <span @class(['a-just-length', 'is-short' => ! $check->textLongEnough])>{{ __('assessment.length', ['min' => $min, 'count' => $check->textLength]) }}</span>
    </p>

    @if ($check->rules !== [])
        <p class="a-just-title">{{ __('assessment.based_on') }}</p>
        <ul class="a-just-rules">
            @foreach ($check->rules as $rule)
                @php $t = $of($rule['text']); @endphp
                @php $printed = $rule['node'].($rule['tree_node'] && $rule['tree_node'] !== $rule['node'] ? ' ('.__('assessment.tree_node', ['node' => $rule['tree_node']]).')' : ''); @endphp
                <li @class(['a-just-rule', 'is-met' => $rule['met'], 'is-informative' => $rule['informative'], 'is-check' => $rule['check']])>
                    <span class="a-just-when">
                        @if ($rule['informative'])<x-lucide-info class="a-just-icon" aria-hidden="true" />@elseif ($rule['check'])<x-lucide-eye class="a-just-icon is-bad" aria-hidden="true" />@elseif ($rule['met'])<x-lucide-circle-check class="a-just-icon is-ok" aria-hidden="true" />@else<x-lucide-circle-alert class="a-just-icon is-bad" aria-hidden="true" />@endif
                        {{ $rule['outcome'] ? __('assessment.rule_at_outcome', ['node' => $printed, 'outcome' => $rule['outcome']]) : __('assessment.rule_at', ['node' => $printed]) }}@if (count($rule['ids']) > 1) · {{ __('assessment.match_'.$rule['match']) }}@endif
                        @if ($rule['informative'])<span class="a-badge a-badge-neutral" title="{{ __('assessment.informative_rule_help') }}">{{ __('assessment.informative_rule') }}</span>@endif
                        @if ($rule['check'])<span class="a-badge a-badge-warning" title="{{ __('assessment.to_check_help') }}">{{ __('assessment.to_check') }}</span>@endif
                    </span>
                    <span class="a-just-ids">
                        @foreach ($rule['ids'] as $id)
                            @php
                                $what = str_starts_with($id['id'], 'E.Info.DT.') ? __('assessment.path_id') : (str_starts_with($id['id'], 'E.Just.DT.') ? __('assessment.just_id') : null);
                            @endphp
                            @if ($id['state'] === 'missing' && $id['href'])
                                <a class="a-just-id is-missing" href="{{ $id['href'] }}" title="{{ __('assessment.write_einfo', ['id' => $id['id']]) }}">
                                    <span class="a-code">{{ $id['id'] }}</span>@if ($id['mapped'])<span class="a-just-what">→ {{ $id['mapped'] }}</span>@endif<span class="a-just-state">{{ __('assessment.state.missing') }}</span><x-lucide-arrow-up-right aria-hidden="true" />
                                </a>
                            @else
                                <span @class(['a-just-id', 'is-'.str_replace('_', '-', $id['state'])])>
                                    <span class="a-code">{{ $id['id'] }}</span>@if ($what)<span class="a-just-what">{{ $what }}</span>@endif @if ($id['mapped'])<span class="a-just-what">→ {{ $id['mapped'] }}</span>@endif
                                    <span class="a-just-state">@if ($id['state'] === 'documented')<x-lucide-check aria-hidden="true" />@endif{{ __('assessment.state.'.$id['state']) }}</span>
                                </span>
                            @endif
                        @endforeach
                    </span>
                    @if ($t['text'] !== '')
                        <details class="a-just-text">
                            <summary>{{ __('assessment.rule_text') }}</summary>
                            <p @if ($t['lang']) lang="{{ $t['lang'] }}" @endif>{{ $t['text'] }}</p>
                        </details>
                    @endif
                </li>
            @endforeach
        </ul>
    @elseif ($check->hasCriteria)
        <p class="a-help">{{ __('assessment.no_rules', ['min' => $min]) }}</p>
    @else
        <p class="a-help">{{ __('assessment.no_criteria', ['min' => $min]) }}</p>
    @endif
    <p class="a-just-note">{{ __('assessment.saved_walk') }}</p>
</div>
