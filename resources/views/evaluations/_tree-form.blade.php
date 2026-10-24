{{--
    The evaluation of one requirement for one item: the clickable decision tree (it IS the evaluation:
    tree-walk.js, x-data="treeWalk" on the form), the expert entry, the shared-parts note and the
    justification, posted to versions.evaluate.update. Used by the evaluation page and by the E.Info
    page's "Edit the decision tree" modal (several on one page: every id takes $prefix).

    @include('evaluations._tree-form', [
        'version', 'standard', 'code', 'pack', 'tree', 'evaluation', 'targetKey', 'canEdit',
        'shared' => SharedWalks state or null, 'check' => Justification check or null,
        'prefix' => '',                  id prefix ('' on the evaluation page)
        'formId' => 'evaluation-form',
        'modal' => false,                true: no footer here (the modal's footer posts with form=),
                                         no untie/retie, the errors of the refused save inside
        'return' => null,                E.Info page to come back to after saving (modal)
    ])
--}}
@php
    $prefix ??= '';
    $formId ??= 'evaluation-form';
    $modal ??= false;
    $return ??= null;
    $path = collect($evaluation?->path ?? []);
    $expert = $evaluation && $evaluation->decision_node && ! $evaluation->path;
    $onPath = $path->pluck('node')->all();
    if ($evaluation?->decision_node && ! in_array($evaluation->decision_node, $onPath, true)) {
        $onPath[] = $evaluation->decision_node;
    }
    // The question asked now (a new evaluation starts at the start node) and the end of a finished one.
    $focus = $canEdit && $tree && ! $expert && $path->isEmpty() ? $tree->start() : null;
    $outcome = $evaluation?->verdict && $path->isNotEmpty() ? $path->last()['node'] : null;
    // Shared trees: links to the same entry in the other parts ("EN 18031-2 and EN 18031-3").
    $partLinks = function (array $parts) use ($version, $code, $pack, $targetKey): string {
        $links = array_map(fn (string $part) => '<a href="'.e(\App\Support\Links::walk($version, $part, $code, $targetKey)).'">'.e($pack->standardName($part)).'</a>', $parts);
        $last = array_pop($links);

        return $links === [] ? (string) $last : __('evaluations.shared.and', ['first' => implode(', ', $links), 'last' => $last]);
    };
    $sharedParts = $shared ? ($shared['source'] ? [$shared['source'], ...array_values(array_diff($shared['parts'], [$shared['source']]))] : $shared['parts']) : [];
@endphp
<form method="POST" action="{{ route('versions.evaluate.update', [$version, $standard, $code]) }}" id="{{ $formId }}"
      @if ($tree) x-data="treeWalk" data-tree="{{ json_encode(['start' => $tree->start(), 'nodes' => array_values($tree->nodes())], JSON_UNESCAPED_UNICODE) }}"
      data-answers="{{ json_encode($path->pluck('answer')->all()) }}" data-requirement="{{ $code }}" data-expert="{{ $expert ? '1' : '0' }}"
      data-interactive="{{ $canEdit ? '1' : '0' }}" data-answer-label="{{ __('evaluations.keys.answer', ['answer' => ':answer']) }}" @endif>
    @csrf
    @method('PUT')
    <input type="hidden" name="target" value="{{ $targetKey }}">
    @if ($return)
        <input type="hidden" name="return" value="{{ $return }}">
        <input type="hidden" name="tree" value="{{ $code }}">
    @endif
    @if ($modal && $errors->any() && old('tree') === $code)
        <div class="a-alert a-alert-danger mb-3" role="alert">
            <x-lucide-triangle-alert aria-hidden="true" />
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <fieldset @disabled(! $canEdit) @class(['a-fieldset a-stack', 'a-panel-body' => ! $modal])>
        @if ($tree)
            <div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="a-label">{{ __('evaluations.walk') }}</p>
                    <button type="button" class="a-btn a-btn-ghost a-btn-sm" @click="toggleExpert"><x-lucide-list-checks aria-hidden="true" />{{ __('evaluations.expert') }}</button>
                </div>

                {{-- The decision tree itself is the evaluation (owner, 2026-10-23: no second copy of the
                     tree as a list of questions): click the answers on the tree, the path and the outcome follow. --}}
                <div x-show="! expert" class="a-eval-walk mt-2">
                    @if ($canEdit)
                        <p class="a-eval-tree-hint a-only-js"><x-lucide-mouse-pointer-click aria-hidden="true" />{{ __('evaluations.tree_hint') }}</p>
                    @endif
                    @include('trees._diagram', ['tree' => $tree, 'order' => $tree->decisionNodes(), 'idPrefix' => $prefix.'diagram', 'current' => $onPath,
                        'answers' => $path->mapWithKeys(fn ($s) => [$s['node'] => $s['answer']])->all(),
                        'focus' => $focus, 'outcome' => $outcome, 'interactive' => $canEdit])
                    <div class="mt-2 flex gap-1" x-show="hasSteps">
                        @if ($canEdit)
                            <button type="button" class="a-btn a-btn-ghost a-btn-sm" @click="back" data-shortcut="b" data-shortcut-doc data-shortcut-label="{{ __('evaluations.keys.back') }}" aria-keyshortcuts="B Backspace"><x-lucide-undo-2 aria-hidden="true" />{{ __('common.back') }}<kbd class="a-kbd a-kbd-hint" aria-hidden="true">b</kbd></button>
                            <button type="button" class="a-btn a-btn-ghost a-btn-sm" @click="restart" data-shortcut="r" data-shortcut-doc data-shortcut-label="{{ __('evaluations.keys.restart') }}" aria-keyshortcuts="R"><x-lucide-rotate-ccw aria-hidden="true" />{{ __('evaluations.restart') }}<kbd class="a-kbd a-kbd-hint" aria-hidden="true">r</kbd></button>
                        @else
                            <button type="button" class="a-btn a-btn-ghost a-btn-sm" @click="back"><x-lucide-undo-2 aria-hidden="true" />{{ __('common.back') }}</button>
                            <button type="button" class="a-btn a-btn-ghost a-btn-sm" @click="restart"><x-lucide-rotate-ccw aria-hidden="true" />{{ __('evaluations.restart') }}</button>
                        @endif
                    </div>
                    <template x-if="submitPartial">
                        <div>
                            <template x-for="step in steps" :key="step.node">
                                <input type="hidden" name="partial[]" :value="step.answer">
                            </template>
                        </div>
                    </template>
                    <template x-if="submitAnswers">
                        <div>
                            <template x-for="step in steps" :key="step.node">
                                <input type="hidden" name="answers[]" :value="step.answer">
                            </template>
                        </div>
                    </template>
                </div>

                {{-- Expert entry --}}
                <div x-show="expert" class="mt-2 a-form-grid">
                    <div class="a-field">
                        <label class="a-label" for="{{ $prefix }}dn">{{ __('evaluations.decision_node') }}</label>
                        <select id="{{ $prefix }}dn" name="decision_node" class="a-select" @change="paint">
                            <option value=""></option>
                            @foreach ($tree->decisionNodes() as $dn)
                                <option value="{{ $dn }}" @selected($evaluation?->decision_node === $dn)>DT.{{ $code }}.{{ $dn }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="a-field">
                        <label class="a-label" for="{{ $prefix }}verdict">{{ __('evaluations.verdict') }}</label>
                        <select id="{{ $prefix }}verdict" name="verdict" class="a-select">
                            <option value=""></option>
                            @foreach (\App\Enums\Verdict::standard() as $v)
                                <option value="{{ $v->value }}" @selected($evaluation?->verdict === $v)>{{ $v->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        @else
            <div class="a-field">
                <label class="a-label" for="{{ $prefix }}verdict">{{ __('evaluations.verdict') }}</label>
                <select id="{{ $prefix }}verdict" name="verdict" class="a-select max-w-xs">
                    <option value=""></option>
                    @foreach (\App\Enums\Verdict::standard() as $v)
                        <option value="{{ $v->value }}" @selected($evaluation?->verdict === $v)>{{ $v->label() }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        {{-- Shared trees: the same tree in other parts of EN 18031 takes the same answer. --}}
        @if ($shared && ($sharedParts !== [] || $shared['apart'] !== []))
            <div @class(['a-shared', 'is-apart' => $shared['untied']]) role="note">
                @if ($shared['untied'])
                    <x-lucide-unlink aria-hidden="true" />
                    <p class="a-shared-text">{!! __('evaluations.shared.apart', ['parts' => $partLinks($shared['apart']), 'have' => count($shared['apart']) > 1 ? __('evaluations.shared.have') : __('evaluations.shared.has')]) !!}</p>
                    @if ($canEdit && $evaluation && ! $modal)
                        <button type="submit" form="shared-retie" class="a-btn a-btn-ghost a-btn-sm" title="{{ __('evaluations.shared.retie_help') }}"><x-lucide-link aria-hidden="true" />{{ __('evaluations.shared.retie') }}</button>
                    @endif
                @else
                    <x-lucide-link aria-hidden="true" />
                    <p class="a-shared-text">
                        @if ($shared['source'])
                            {!! __('evaluations.shared.with', ['parts' => $partLinks($sharedParts)]) !!}
                        @elseif ($evaluation)
                            {!! __('evaluations.shared.also', ['parts' => $partLinks($sharedParts)]) !!}
                        @else
                            {!! __('evaluations.shared.will', ['parts' => $partLinks($sharedParts)]) !!}
                        @endif
                    </p>
                    @if ($canEdit && $evaluation && ! $modal)
                        <button type="submit" form="shared-untie" class="a-btn a-btn-ghost a-btn-sm" title="{{ __('evaluations.shared.untie_help') }}"><x-lucide-unlink aria-hidden="true" />{{ __('evaluations.shared.untie') }}</button>
                    @endif
                @endif
            </div>
        @endif

        <div class="a-field">
            <label class="a-label" for="{{ $prefix }}justification">{{ __('evaluations.justification') }} <x-ui.tip :text="__('evaluations.justification_help')" /></label>
            <textarea id="{{ $prefix }}justification" name="justification" class="a-textarea" rows="{{ $modal ? 4 : 5 }}" maxlength="20000">{{ $modal && old('tree') === $code ? old('justification') : $evaluation?->justification }}</textarea>
            @error('justification')<p class="a-error">{{ $message }}</p>@enderror
            @if ($check?->walked || $check?->inconclusive)
                @include('assessment._justification', ['check' => $check])
            @endif
            @if ($evaluation)
                <div class="mt-1 flex flex-wrap gap-2">
                    <x-ai.origin type="evaluation" :key="$evaluation->id" field="justification" :value="$evaluation->justification" />
                    @if ($canEdit)
                        <x-ai.assist :version="$version" type="evaluation" :key="$evaluation->id" field="justification" />
                    @endif
                </div>
            @endif
        </div>
    </fieldset>
    @if ($canEdit && ! $modal)
        <div class="a-panel-foot a-inconclusive-foot">
            {{-- Not a verdict of EN 18031: kept with the path so far, needs a justification. --}}
            <span class="a-inconclusive-action">
                <button type="submit" name="inconclusive" value="1" class="a-btn a-btn-ghost a-btn-sm" aria-describedby="{{ $prefix }}inconclusive-note"><x-lucide-circle-help aria-hidden="true" />{{ __('assessment.inconclusive_action') }}</button>
                <span class="a-help a-inconclusive-note" id="{{ $prefix }}inconclusive-note">{{ __('assessment.inconclusive_note') }}</span>
            </span>
            <span class="a-spacer"></span>
            <button type="submit" class="a-btn a-btn-outline" data-shortcut="mod+s" data-shortcut-label="{{ __('evaluations.keys.save') }}"><x-lucide-check aria-hidden="true" />{{ __('evaluations.save') }}</button>
            <button type="submit" name="next" value="1" class="a-btn a-btn-primary"><x-lucide-check-check aria-hidden="true" />{{ __('evaluations.save_next') }}</button>
        </div>
    @endif
</form>
