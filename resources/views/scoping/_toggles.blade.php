{{--
    The cetome.com RED scoping as toggle cards, one group per article. Used by "New product"
    and by the scoping step, next to the live result (scoping._result).
    @include('scoping._toggles', ['pack' => $pack, 'answers' => $answers, 'disabled' => false])
    Must sit inside an element with x-data="scopingLive" (it reads data-scoping from there).
    Each card also shows the "Suggest with AI" proposal (scoping._ai) and the origin mark of its
    answer: AI Generated while it equals the applied suggestion, Human Edited once changed.
--}}
@php
    $text = fn ($v) => \App\Content\Text::of($v);
    $sections = app(\App\Assessment\ScopingForm::class)->sections(\App\Assessment\ScopingForm::SCOPING);
    $disabled ??= false;
@endphp
<div class="a-scope-groups">
    @foreach ($sections as $section)
        @foreach ($section['groups'] ?? [] as $group)
            @php $article = collect($group['questions'] ?? [])->flatMap(fn ($q) => $q['articles'] ?? [])->first(); @endphp
            <fieldset class="a-scope-group">
                <legend class="a-micro">
                    @if ($article){{ __('trees.article', ['article' => $article]) }} · @endif{{ $text($group['title']) }}
                </legend>
                <div class="a-scope-group-cards">
                    @foreach ($group['questions'] ?? [] as $question)
                        @continue(($question['type'] ?? 'boolean') !== 'boolean')
                        <label class="a-scope-card" id="scope-q-{{ $question['key'] }}" title="{{ isset($question['statement']) ? $text($question['statement']) : '' }}">
                            <input type="hidden" name="answers[{{ $question['key'] }}]" value="0">
                            <input type="checkbox" name="answers[{{ $question['key'] }}]" value="1"
                                   @checked(($answers[$question['key']] ?? false) === true) @disabled($disabled)>
                            <span class="a-scope-card-box" aria-hidden="true"><x-lucide-check /></span>
                            <span>
                                <span class="a-scope-card-label">{{ $text($question['label']) }}</span>
                                @if (! empty($question['example']))
                                    <span class="a-scope-card-example">{{ $text($question['example']) }}</span>
                                @endif
                                {{-- AI proposal and origin mark (scoping.js scopeAi): hidden until there is one. --}}
                                <span class="a-scope-card-ai" x-data="scopeAi" data-key="{{ $question['key'] }}" x-show="isShown" x-cloak>
                                    <span class="a-scope-card-ai-head" x-show="hasSuggestion">
                                        <x-lucide-sparkles class="size-3 shrink-0" aria-hidden="true" />
                                        <span x-show="suggestsYes">{{ __('scoping.ai.suggests_yes') }}</span>
                                        <span x-show="suggestsNo">{{ __('scoping.ai.suggests_no') }}</span>
                                        <span class="a-scope-card-ai-conf" x-text="confidence"></span>
                                    </span>
                                    <span class="a-scope-card-ai-reason" x-show="hasReason" x-text="reason"></span>
                                    <x-ai.badge origin="ai_generated" class="a-scope-card-badge" x-show="isAiGenerated" />
                                    <x-ai.badge origin="human_edited" class="a-scope-card-badge" x-show="isHumanEdited" />
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
    @endforeach
</div>
