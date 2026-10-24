{{--
    The form of one risk, in a side panel: threat, STRIDE, rating on the heat map (the level is
    looked up in the version's matrix), mechanisms picked from the pack, residual level.
    Its buttons are in the panel footer (form="{{ $formId }}").
    Params: $entry (null to add), $formId, $action, $method; from the page: $levels, $cells,
    $stride, $articles (in-scope first), $scopedArticles (null before any scoping answer),
    $mechanisms (families and requirements with "in_scope", in-scope first), $access, $version.
    Never depends on the inventory: the risks come before the assets and interfaces.
--}}
@php
    $editable = $access->canEdit($version);
    $key = $entry?->id ?? 'new';
    $p = 'r'.substr(md5($key), 0, 6);
    $fromOld = old('_risk') === $key;
    $value = fn (string $field, $default = null) => $fromOld ? old($field, $default) : ($entry?->$field ?? $default);
    $cell = $fromOld ? (string) old('cell', '') : ($entry?->impact && $entry?->likelihood ? $entry->impact.'-'.$entry->likelihood : '');
    $chosen = (array) ($fromOld ? old('requirements', []) : ($entry?->requirements ?? []));
    $residual = (string) $value('residual_rank', '');
    $initialRank = $cell !== '' ? ($cells[$cell] ?? null) : null;
    $text = fn ($v) => \App\Content\Text::of($v);
@endphp
<form method="POST" action="{{ $action }}" id="{{ $formId }}" data-risk-form>
    @csrf
    @if ($method !== 'POST') @method($method) @endif
    <input type="hidden" name="_risk" value="{{ $key }}">
    <fieldset @disabled(! $editable) class="a-fieldset a-stack">
        <div class="a-field">
            <label class="a-label" for="{{ $p }}-threat">{{ __('risks.threat') }}</label>
            <input id="{{ $p }}-threat" name="threat" class="a-input" required maxlength="200" value="{{ $value('threat') }}" data-autofocus @error('threat') aria-invalid="true" @enderror>
            @error('threat')<p class="a-error">{{ $message }}</p>@enderror
        </div>
        <div class="a-field">
            <label class="a-label" for="{{ $p }}-description">{{ __('risks.description') }}</label>
            <textarea id="{{ $p }}-description" name="description" class="a-textarea a-textarea-sm" rows="2" maxlength="5000">{{ $value('description') }}</textarea>
        </div>

        <fieldset class="a-fieldset a-field">
            <legend class="a-label">{{ __('risks.stride') }} <x-ui.tip :text="__('risks.stride_help')" /></legend>
            <div class="a-chips">
                @foreach (\App\Models\RiskEntry::STRIDE as $letter)
                    <label class="a-letter-toggle">
                        <input type="checkbox" name="stride[]" value="{{ $letter }}" @checked(in_array($letter, (array) $value('stride', []), true))>
                        <b>{{ $letter }}</b>{{ $text($stride[$letter] ?? $letter) }}
                    </label>
                @endforeach
            </div>
            @error('stride.*')<p class="a-error">{{ $message }}</p>@enderror
        </fieldset>

        <div class="a-field">
            <label class="a-label" for="{{ $p }}-risk">{{ __('risks.risk') }}</label>
            <textarea id="{{ $p }}-risk" name="risk" class="a-textarea a-textarea-sm" rows="2" maxlength="5000">{{ $value('risk') }}</textarea>
        </div>

        {{-- Initial rating: a readable summary here, the matrix itself in a modal (risks/_rating). --}}
        <fieldset class="a-fieldset a-field">
            <legend class="a-label">{{ __('risks.initial_rating') }} <x-ui.tip :text="__('risks.matrix_help')" /></legend>
            @include('risks._rating', ['p' => $p, 'cell' => $cell, 'initialRank' => $initialRank, 'editable' => $editable])
        </fieldset>

        {{-- Articles: only those the scoping puts in scope (owner, 2026-10-23: nothing out of scope is
             selectable). An out-of-scope article already ticked stays, marked, so it can be unticked. --}}
        <fieldset class="a-fieldset a-field">
            <legend class="a-label">{{ __('risks.articles') }} <x-ui.tip :text="__('risks.articles_help')" /></legend>
            <div class="a-chips">
                @foreach ($articles as $code => $name)
                    @php
                        $outOfScope = $scopedArticles !== null && ! in_array($code, $scopedArticles, true);
                        $ticked = in_array($code, (array) $value('articles', []), true);
                    @endphp
                    @continue($outOfScope && ! $ticked)
                    <label @class(['a-chip-toggle', 'a-chip-out' => $outOfScope]) title="{{ $name }}">
                        <input type="checkbox" name="articles[]" value="{{ $code }}" @checked(in_array($code, (array) $value('articles', []), true))>
                        <span>{{ $code }} <small>{{ \Illuminate\Support\Str::limit($name, 32) }}</small>@if ($outOfScope) <em class="a-chip-out-note">· {{ __('risks.not_in_scope') }}</em>@endif</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        {{-- EN 18031 mechanisms: searchable picker of the pack's families and requirements. --}}
        <fieldset class="a-fieldset a-field">
            <legend class="a-label">{{ __('risks.requirements') }} <x-ui.tip :text="__('risks.requirements_pick_help')" /></legend>
            <div class="a-picker" data-picker data-remove-label="{{ __('ui.remove') }}" data-filter-scope>
                <div class="a-picker-search">
                    <label class="a-search max-w-none">
                        <span class="a-sr-only">{{ __('common.search') }}</span>
                        <x-lucide-search aria-hidden="true" />
                        <input type="search" class="a-input" data-filter="#{{ $p }}-mech" placeholder="{{ __('risks.search_mechanisms') }}" autocomplete="off">
                    </label>
                </div>
                <div class="a-picker-selected" data-picker-summary></div>
                <div class="a-picker-list" id="{{ $p }}-mech">
                    {{-- Families and requirements of the EN 18031 parts in scope only (owner, 2026-10-23). Out of
                         scope, only what this risk already has is listed, marked, so it can be unticked. --}}
                    @foreach (collect($mechanisms)->groupBy(fn ($f) => $f['in_scope'] ? 'in' : 'out', true) as $band => $families)
                        @php
                            $families = $band === 'in' || $scopedArticles === null ? $families : collect($families)
                                ->filter(fn ($f) => in_array($f['code'], $chosen, true) || array_intersect(array_keys($f['requirements']), $chosen) !== [])->all();
                        @endphp
                        @continue($families === [])
                        <div @class(['a-picker-band', 'is-out' => $band === 'out']) data-filter-group>
                            @if ($band === 'out' && $scopedArticles !== null)
                                <p class="a-picker-band-head">{{ __('risks.mechanisms_out') }}</p>
                            @endif
                            @foreach ($families as $family)
                                <div class="a-picker-group" data-filter-group>
                                    @if ($band === 'in' || $scopedArticles === null || in_array($family['code'], $chosen, true))
                                        <label class="a-mech a-mech-family" data-filter-item data-search="{{ $family['code'] }} {{ $family['name'] }}">
                                            <input type="checkbox" name="requirements[]" value="{{ $family['code'] }}" @checked(in_array($family['code'], $chosen, true))>
                                            <span class="a-code">{{ $family['code'] }}</span><span>{{ $family['name'] }}</span>
                                        </label>
                                    @endif
                                    @foreach ($family['requirements'] as $code => $requirement)
                                        @continue(! $requirement['in_scope'] && $scopedArticles !== null && ! in_array($code, $chosen, true))
                                        <label @class(['a-mech', 'is-out' => ! $requirement['in_scope']]) data-filter-item data-search="{{ $code }} {{ $requirement['title'] }} {{ $family['code'] }} {{ $family['name'] }}">
                                            <input type="checkbox" name="requirements[]" value="{{ $code }}" @checked(in_array($code, $chosen, true))>
                                            <span class="a-code">{{ $code }}</span><span class="a-muted">{{ $requirement['title'] }}</span>
                                            @if (! $requirement['in_scope'] && $band === 'in')<span class="a-mech-out">{{ __('risks.not_in_scope') }}</span>@endif
                                        </label>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                    <p class="a-help p-2" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</p>
                </div>
            </div>
            @error('requirements')<p class="a-error">{{ $message }}</p>@enderror
        </fieldset>

        <div class="a-form-grid">
            <div class="a-field">
                <label class="a-label" for="{{ $p }}-remediation">{{ __('risks.remediation') }}</label>
                <textarea id="{{ $p }}-remediation" name="remediation" class="a-textarea" rows="3" maxlength="5000">{{ $value('remediation') }}</textarea>
            </div>
            <div class="a-field">
                <label class="a-label" for="{{ $p }}-implementation">{{ __('risks.implementation') }}</label>
                <textarea id="{{ $p }}-implementation" name="implementation" class="a-textarea" rows="3" maxlength="5000">{{ $value('implementation') }}</textarea>
            </div>
        </div>

        <fieldset class="a-fieldset a-field">
            <legend class="a-label">{{ __('risks.residual') }} <x-ui.tip :text="__('risks.residual_help')" /></legend>
            <div class="a-chips">
                @foreach ($levels as $rank => $level)
                    <label class="a-chip-toggle">
                        <input type="radio" name="residual_rank" value="{{ $rank }}" @checked($residual === (string) $rank)>
                        <span class="a-dot risk-dot-{{ $rank }}" aria-hidden="true"></span><span>{{ $level->name }}</span>
                    </label>
                @endforeach
                <label class="a-chip-toggle a-chip-none">
                    <input type="radio" name="residual_rank" value="" @checked($residual === '')>
                    <span>{{ __('common.not_set') }}</span>
                </label>
            </div>
            <p class="a-alert a-alert-warning mt-1 mb-0!" data-residual-warning @if (! $initialRank || $residual === '' || (int) $residual >= $initialRank) hidden @endif>
                <x-lucide-triangle-alert aria-hidden="true" /><span>{{ __('risks.residual_worse') }}</span>
            </p>
        </fieldset>

        @if ($entry)
            <div class="flex flex-wrap gap-2">
                @foreach (['description', 'risk', 'remediation', 'implementation'] as $f)
                    <x-ai.origin type="risk_entry" :key="$entry->id" :field="$f" :value="$entry->$f" />
                @endforeach
            </div>
        @endif
    </fieldset>
</form>
