<x-layouts.app :title="__('conformity.title')">
    <x-slot:subnav><x-version-nav :version="$version" active="conformity" /></x-slot:subnav>
    <x-read-only-notice :access="$access" :version="$version" />
    @php
        $editable = $access->canEdit($version);
        $text = fn ($v) => \App\Content\Text::of($v);
        $pack = $assessment->pack;
        $result = $conformity->result();
        $possible = $conformity->selfAssessmentPossible();
        $route = old('conformity_route', $version->conformity_route?->value);
        $labels = $pack->scoping()['restriction_labels'] ?? [];
        $progress = $conformity->progress();
        // Suggested answers (scoping, inventory, decision trees): the default of a question not
        // saved yet, never of a saved one. Only saved answers decide the route.
        $suggestions = $conformity->suggestions();
        $effective = [];
        foreach ($form->questions(\App\Assessment\ScopingForm::CONFORMITY) as $questionKey => $question) {
            $effective[$questionKey] = array_key_exists($questionKey, $answers)
                ? $answers[$questionKey] === true
                : ($suggestions[$questionKey]['value'] ?? null) === true;
        }
        // Met / not met / to answer per restriction: the same states the reports print.
        $states = $conformity->restrictionStates();
    @endphp

    <x-ui.page-header :title="__('conformity.title')" :subtitle="__('conformity.lead')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
    </x-ui.page-header>

    @if ($conformity->conflict())
        <div class="a-alert a-alert-danger" role="alert"><x-lucide-octagon-alert aria-hidden="true" /><span>{{ __('conformity.conflict') }}</span></div>
    @endif

    <form method="POST" action="{{ route('versions.conformity.update', $version) }}" class="a-grid-main">
        @csrf
        @method('PUT')
        <div class="a-stack min-w-0">
            {{-- Self-assessment check, as the cetome.com RED self-assessment: four numbered cards.
                 1 is computed from the decision trees, 2 to 4 are the pack's RED restriction questions. --}}
            <section class="a-panel" aria-labelledby="restrictions-title">
                <div class="a-panel-head">
                    <h2 class="a-h3 flex items-center gap-2" id="restrictions-title"><x-lucide-shield-check class="size-4 text-brand-blue" aria-hidden="true" />{{ __('conformity.check_title') }}</h2>
                </div>
                <fieldset @disabled(! $editable) class="a-fieldset a-panel-body a-stack gap-3!" data-conditional-scope>
                    <p class="a-help">{{ __('conformity.restrictions_lead') }}</p>

                    {{-- 1. EN 18031 implementation --}}
                    @php $treesState = $states['en18031'] ?? null; @endphp
                    <div class="a-check-card">
                        <span class="a-check-no" aria-hidden="true">1</span>
                        <div class="a-check-body">
                            <div class="a-check-head">
                                <h3 class="a-check-title">{{ $text($labels['en18031'] ?? __('conformity.trees')) }}</h3>
                                @include('conformity._state', ['state' => $treesState, 'pending' => $progress !== [] && $treesState === null])
                            </div>
                            <p class="a-help">{{ __('conformity.trees_computed') }}</p>
                            @if ($progress === [])
                                <p class="a-muted text-sm">{{ __('conformity.trees_none') }}</p>
                            @else
                                <ul class="a-conf-trees">
                                    @foreach ($progress as $standard => $p)
                                        <li>
                                            <a class="a-conf-trees-name" href="{{ route('versions.evaluate', [$version, $standard]) }}">{{ $pack->standardName($standard) }}</a>
                                            <x-ui.progress :value="$p['done']" :max="$p['total']" line />
                                            @if ($p['fail'] > 0)
                                                <span class="inline-flex items-center gap-1.5"><x-verdict verdict="FAIL" /><b>{{ $p['fail'] }}</b></span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="flex items-center gap-2 text-sm">
                                    @if ($conformity->en18031Fully() === true)
                                        <x-lucide-circle-check class="size-4 shrink-0 text-green-600" aria-hidden="true" />{{ __('conformity.trees_ok') }}
                                    @elseif ($conformity->failCount() > 0)
                                        <x-lucide-circle-x class="size-4 shrink-0 text-red-600" aria-hidden="true" />
                                        {{ trans_choice('conformity.trees_fail', $conformity->failCount(), ['count' => $conformity->failCount()]) }}
                                    @else
                                        <x-lucide-circle-dashed class="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                                        {{ trans_choice('conformity.trees_pending', $conformity->remainingCount(), ['count' => $conformity->remainingCount()]) }}
                                    @endif
                                </p>
                            @endif
                        </div>
                    </div>

                    {{-- 2 to 4: one card per restriction group; follow-up questions appear after a Yes. --}}
                    @php
                        $number = 1;
                    @endphp
                    @foreach ($sections as $section)
                        @foreach ($section['groups'] ?? [] as $group)
                            @php
                                $number++;
                                $questions = collect($group['questions'] ?? []);
                                // A card applies when its first question applies (article in scope).
                                $applies = $questions->isNotEmpty() && $form->isVisible($questions->first(), $answers, $result);
                                $key = \App\Assessment\Conformity::RESTRICTION_OF[$group['key']] ?? $group['key'];
                                // A restriction the data settles by itself (e.g. no secure update): no question.
                                $settledGroup = $suggestions[$group['key']]['settled'] ?? false;
                                $state = $applies ? ($states[$key] ?? null) : 'na';
                                // The group's shared reasons show when one of its questions shows its own.
                                $derivedShown = $questions->contains(function ($q) use ($answers, $suggestions) {
                                    $suggestion = $suggestions[$q['key']] ?? null;
                                    if (! array_key_exists($q['key'], $answers)) {
                                        return $suggestion !== null;
                                    }

                                    return ($suggestion['value'] ?? null) !== null && $suggestion['value'] !== ($answers[$q['key']] === true);
                                });
                            @endphp
                            <div @class(['a-check-card', 'is-na' => ! $applies])>
                                <span class="a-check-no" aria-hidden="true">{{ $number }}</span>
                                <div class="a-check-body">
                                    <div class="a-check-head">
                                        <h3 class="a-check-title">{{ $text($group['title']) }}</h3>
                                        @include('conformity._state', ['state' => $state, 'pending' => false])
                                    </div>
                                    @if (! $applies)
                                        <p class="a-help">{{ __('conformity.card_na') }}</p>
                                    @elseif ($settledGroup)
                                        <ul class="a-conf-why is-settled">
                                            @foreach ($suggestions[$group['key']]['because'] as $reason)
                                                <li><x-lucide-circle-check aria-hidden="true" />
                                                    @if ($reason['href'])<a href="{{ $reason['href'] }}">{{ $reason['text'] }}</a>@else<span>{{ $reason['text'] }}</span>@endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        @if (! empty($group['lead']))
                                            <p class="a-help">{{ __('conformity.from_scoping') }} {{ $text($group['lead']) }}</p>
                                        @endif
                                        @if ($derivedShown && ($suggestions[$group['key']]['because'] ?? []) !== [])
                                            @include('conformity._because', ['because' => $suggestions[$group['key']]['because'],
                                                'open' => $questions->every(fn ($q) => ($suggestions[$q['key']]['value'] ?? null) === null)])
                                        @endif
                                        @foreach ($questions as $question)
                                            @php
                                                $questionKey = $question['key'];
                                                $parent = $question['show_if']['question'] ?? null;
                                                $shown = $parent === null || ($effective[$parent] ?? false);
                                                $saved = array_key_exists($questionKey, $answers);
                                                $suggested = $suggestions[$questionKey]['value'] ?? null;
                                                $because = $suggestions[$questionKey]['because'] ?? [];
                                                $prefilled = ! $saved && $suggested !== null;
                                                $differs = $saved && $suggested !== null && $suggested !== ($answers[$questionKey] === true);
                                            @endphp
                                            <div @class(['a-switch-follow' => $parent !== null]) @if ($parent) data-show-if="{{ $parent }}" @endif @if (! $shown) hidden @endif @if ($prefilled) data-derived @endif>
                                                <label @class(['a-switch', 'is-prefilled' => $prefilled]) title="{{ isset($question['statement']) ? $text($question['statement']) : '' }}">
                                                    <span class="a-switch-text">{{ $text($question['label']) }}</span>
                                                    <input type="hidden" name="answers[{{ $questionKey }}]" value="0">
                                                    <input type="checkbox" name="answers[{{ $questionKey }}]" value="1" @checked($effective[$questionKey] ?? false)>
                                                    <span class="a-switch-pill" data-on="{{ __('common.yes') }}" data-off="{{ __('common.no') }}" aria-hidden="true"></span>
                                                </label>
                                                @if ($prefilled)
                                                    <p class="a-conf-derived" data-derived-mark>
                                                        <x-ai.badge :origin="\App\Enums\Origin::FromInventory" :label="__('conformity.derived.label')" :tooltip="__('conformity.derived.tooltip')" />
                                                        <span class="a-einfo-prefill-hint">{{ __('einfo.prefill.unsaved') }}</span>
                                                    </p>
                                                @endif
                                                @if ($differs)
                                                    <p class="a-conf-differs" role="note">
                                                        <x-lucide-triangle-alert aria-hidden="true" />
                                                        {{-- One line: the first reason (what settles it), the others in its tooltip. --}}
                                                        <span>{{ __('conformity.derived.differs', ['answer' => $suggested ? __('common.yes') : __('common.no')]) }}
                                                            @if ($because !== [])
                                                                @if ($because[0]['href'])<a href="{{ $because[0]['href'] }}">{{ $because[0]['text'] }}</a>@else{{ $because[0]['text'] }}@endif
                                                                @if (count($because) > 1)<span title="{{ implode(' · ', array_column(array_slice($because, 1), 'text')) }}">· …</span>@endif
                                                            @endif
                                                        </span>
                                                    </p>
                                                @elseif (! $saved && $because !== [])
                                                    @include('conformity._because', ['because' => $because, 'open' => $suggested === null])
                                                @endif
                                            </div>
                                        @endforeach
                                        @if ($key === 'fraud')
                                            <p class="a-help">{{ __('conformity.fraud_rule') }}</p>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    @endforeach
                </fieldset>
            </section>

            {{-- 3. The route --}}
            <section class="a-panel" aria-labelledby="route-title" x-data="conformityRoute" data-route="{{ $route }}" data-nb-required="{{ $possible === false ? '1' : '0' }}">
                <div class="a-panel-head"><h2 class="a-h3 flex items-center gap-2" id="route-title"><x-lucide-badge-check class="size-4 text-brand-blue" aria-hidden="true" />{{ __('conformity.route') }}</h2></div>
                <fieldset @disabled(! $editable) class="a-fieldset a-panel-body a-stack gap-3!">
                    @error('conformity_route')<p class="a-error">{{ $message }}</p>@enderror
                    <div class="a-route-cards">
                        @foreach (\App\Enums\ConformityRoute::cases() as $case)
                            @php $allowed = $conformity->allows($case); @endphp
                            <label @class(['a-route-card', 'is-disabled' => ! $allowed])>
                                <input type="radio" name="conformity_route" value="{{ $case->value }}"
                                       @checked($route === $case->value) @disabled(! $allowed) @change="pick">
                                <span>
                                    <span class="a-route-card-title">{{ $case->label() }}</span>
                                    <span class="a-help block">{{ $case->help() }}</span>
                                    @if (! $allowed)
                                        <span class="a-route-card-note is-bad"><x-lucide-ban aria-hidden="true" />{{ __('conformity.module_a_unavailable') }}</span>
                                    @elseif ($case === \App\Enums\ConformityRoute::ModuleA && $possible === null)
                                        <span class="a-route-card-note"><x-lucide-info aria-hidden="true" />{{ __('conformity.module_a_tentative') }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    {{-- Notified Body: autocomplete from the NANDO list for RED (notified-body.js). The
                         certificate is recorded on the Declaration step, once issued after the review. --}}
                    @php $nbUnknown = \App\Support\NotifiedBodies::unknown(old('notified_body_number', $version->notified_body_number)); @endphp
                    <div class="a-nb" x-show="needsBody" data-nb-picker data-bodies="{{ json_encode($bodies, JSON_UNESCAPED_UNICODE) }}">
                        <div class="a-nb-fields">
                            <div class="a-field">
                                <label class="a-label" for="notified_body">{{ __('identification.notified_body') }}</label>
                                <input id="notified_body" name="notified_body" class="a-input" maxlength="160" autocomplete="off"
                                       role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="nb-options" data-nb-input="name"
                                       placeholder="{{ __('declaration.nando.placeholder_name') }}"
                                       value="{{ old('notified_body', $version->notified_body) }}" @error('notified_body') aria-invalid="true" @enderror>
                                @error('notified_body')<p class="a-error">{{ $message }}</p>@enderror
                            </div>
                            <div class="a-field">
                                <label class="a-label" for="notified_body_number">{{ __('identification.notified_body_number') }}</label>
                                <input id="notified_body_number" name="notified_body_number" class="a-input" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off"
                                       role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="nb-options" data-nb-input="number"
                                       placeholder="0123" aria-describedby="nb-number-help"
                                       value="{{ old('notified_body_number', $version->notified_body_number) }}" @error('notified_body_number') aria-invalid="true" @enderror>
                                @error('notified_body_number')<p class="a-error">{{ $message }}</p>@enderror
                            </div>
                            <ul id="nb-options" class="a-combo-list" role="listbox" aria-label="{{ __('declaration.nando.list_label') }}" data-nb-list hidden></ul>
                        </div>
                        <p class="a-help a-nb-help" id="nb-number-help">
                            <x-lucide-search aria-hidden="true" />
                            <span>{{ __('declaration.nando.help', ['date' => \App\Support\NotifiedBodies::retrieved()]) }}
                                <a href="{{ \App\Support\NotifiedBodies::url() }}" class="a-link-btn" target="_blank" rel="noopener noreferrer">{{ __('declaration.nando.check') }}<x-lucide-external-link class="a-nb-ext" aria-hidden="true" /></a></span>
                        </p>
                        <p class="a-nb-unknown" data-nb-unknown @if (! $nbUnknown) hidden @endif role="status">
                            <x-lucide-triangle-alert aria-hidden="true" />{{ __('declaration.nando.not_listed', ['date' => \App\Support\NotifiedBodies::retrieved()]) }}
                        </p>
                        <p class="a-help a-nb-help"><x-lucide-file-badge aria-hidden="true" /><span>{{ __('declaration.certificate.later') }}</span></p>
                    </div>
                </fieldset>
            </section>
        </div>

        {{-- Outcome, with the save button always in view. Each restriction's state is on its own card
             (owner, 2026-10-23: no duplicated information). --}}
        <aside class="a-aside-sticky">
            <section @class(['a-panel a-outcome', 'is-ok' => $possible === true, 'is-bad' => $possible === false]) aria-labelledby="outcome-title">
                <div class="a-panel-body a-stack gap-3!">
                    <div>
                        <p class="a-micro">{{ __('conformity.outcome') }}</p>
                        <p class="a-outcome-title mt-1" id="outcome-title">
                            {{ match ($possible) { true => __('conformity.self_possible'), false => __('conformity.nb_mandatory'), null => __('conformity.pending') } }}
                        </p>
                    </div>
                </div>
                @if ($editable)
                    <div class="a-panel-foot">
                        <button type="submit" data-shortcut="mod+s" data-shortcut-label="{{ __('common.save') }}" class="a-btn a-btn-primary a-btn-block"><x-lucide-save aria-hidden="true" />{{ __('common.save') }}</button>
                    </div>
                @endif
            </section>
        </aside>
    </form>
</x-layouts.app>
