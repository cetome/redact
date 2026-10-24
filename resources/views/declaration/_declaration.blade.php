{{-- Declaration tab: the EU declaration of conformity following RED Annex VI point by point, the
     manufacturer (organisation), the Notified Body's certificate, and the simplified declaration. --}}
@php
    $org = $data->organisation();
    $nb = $data->notifiedBody();
    $components = $data->softwareComponents();
    $standardRows = function (string $group) use ($data) {
        $rows = old('standards') !== null
            ? array_values(array_filter((array) old('standards'), fn ($r) => ($r['article'] ?? '') === $group))
            : $data->otherStandardsFor($group);

        return [...$rows, ['reference' => '', 'article' => $group], ['reference' => '', 'article' => $group]];
    };
    $row = 0;
    $legislation = old('legislation') !== null ? array_values(array_filter((array) old('legislation'))) : $data->legislation();
@endphp

<div class="a-grid-main a-decl">
    <div class="a-stack min-w-0">
        {{-- The Notified Body's certificate: only once issued, after the review. --}}
        @if ($data->needsNotifiedBody())
            @include('declaration._certificate')
        @endif

        {{-- The manufacturer, kept on the organisation (Annex VI point 2). --}}
        @include('declaration._manufacturer')

        <form method="POST" action="{{ route('versions.declaration-data', $version) }}" id="declaration-form" class="a-panel" aria-labelledby="declaration-form-title">
            @csrf
            @method('PUT')
            <div class="a-panel-head">
                <div class="min-w-0">
                    <h2 class="a-h3 flex items-center gap-2" id="declaration-form-title"><x-lucide-file-signature class="size-4 text-brand-blue" aria-hidden="true" />{{ __('declaration.annex.title') }}</h2>
                    <p class="a-help mt-0.5">{{ __('declaration.form_lead') }}</p>
                </div>
                <span class="a-badge a-badge-outline shrink-0" title="{{ __('declaration.source_tip') }}">{{ __('declaration.source') }}</span>
            </div>
            <fieldset @disabled(! $canEdit) class="a-fieldset a-panel-body a-stack gap-3!">
                <div class="a-field a-decl-number">
                    <label class="a-label" for="decl-number">{{ __('declaration.fields.number') }} <span class="a-label-hint">{{ __('common.optional') }}</span></label>
                    <input id="decl-number" name="number" class="a-input a-input-sm" maxlength="60" value="{{ old('number', $data->value('number')) }}" placeholder="{{ __('declaration.fields.number_placeholder') }}">
                    <p class="a-help">{{ __('declaration.annex.footnote') }}</p>
                </div>

                {{-- 1. Radio equipment --}}
                <div class="a-check-card a-decl-point">
                    <span class="a-check-no" aria-hidden="true">1</span>
                    <div class="a-check-body">
                        <h3 class="a-check-title">{{ __('declaration.annex.p1') }}</h3>
                        <dl class="a-dl a-decl-dl">
                            <dt>{{ __('declaration.word.product') }}</dt><dd>{{ $version->product->name }}</dd>
                            {{-- Each model and identifier once; a type line only when none gives the type. --}}
                            @if (collect($data->identifiers())->whereIn('kind', ['model', 'type', 'reference', 'hvin'])->isEmpty() && $data->typeDesignation() !== $version->product->name)
                                <dt>{{ __('declaration.word.type') }}</dt><dd>{{ $data->typeDesignation() }}</dd>
                            @endif
                            @foreach ($data->identifiers() as $identifier)
                                <dt>{{ $identifier['label'] }}</dt><dd>{{ $identifier['value'] }}@if ($identifier['notes'] !== '') <span class="a-muted">({{ $identifier['notes'] }})</span>@endif</dd>
                            @endforeach
                        </dl>
                        <p class="a-decl-from"><x-lucide-package aria-hidden="true" />{{ __('declaration.from_app') }}
                            <a href="{{ route('versions.edit', $version) }}#identification" class="a-link-btn">{{ __('declaration.edit_identifiers') }}</a></p>
                        <div class="a-field">
                            <label class="a-label" for="decl-serials">{{ __('declaration.fields.serials') }} <span class="a-label-hint">{{ __('common.optional') }}</span></label>
                            <input id="decl-serials" name="serials" class="a-input a-input-sm" maxlength="300" value="{{ old('serials', $data->value('serials')) }}" placeholder="{{ __('declaration.word.serials_all') }}">
                        </div>
                    </div>
                </div>

                {{-- 2. Manufacturer --}}
                <div class="a-check-card a-decl-point">
                    <span class="a-check-no" aria-hidden="true">2</span>
                    <div class="a-check-body">
                        <h3 class="a-check-title">{{ __('declaration.annex.p2') }}</h3>
                        @if ($data->hasManufacturer())
                            <p class="a-decl-address">{{ $data->manufacturerName() }}<br>{!! nl2br(e($data->manufacturerAddress())) !!}</p>
                            @if ($data->authorisedRepresentative())
                                <p class="a-decl-address"><span class="a-micro">{{ __('declaration.word.representative') }}</span><br>{!! nl2br(e($data->authorisedRepresentative())) !!}</p>
                            @endif
                        @else
                            <p class="a-decl-missing"><x-lucide-circle-alert aria-hidden="true" />{{ __('declaration.manufacturer.missing') }}</p>
                        @endif
                        {{-- The demo: its fictitious manufacturer is said once, in the Manufacturer panel. --}}
                        @unless ($data->isDemo())
                            <p class="a-decl-from"><x-lucide-building-2 aria-hidden="true" />{{ __('declaration.from_org', ['name' => $org->name]) }}
                                <a href="#manufacturer" class="a-link-btn">{{ __('declaration.manufacturer.edit_link') }}</a></p>
                        @endunless
                    </div>
                </div>

                {{-- 3. Sole responsibility --}}
                <div class="a-check-card a-decl-point is-fixed">
                    <span class="a-check-no" aria-hidden="true">3</span>
                    <div class="a-check-body"><h3 class="a-check-title">{{ __('declaration.annex.p3') }}</h3></div>
                </div>

                {{-- 4. Object of the declaration --}}
                <div class="a-check-card a-decl-point">
                    <span class="a-check-no" aria-hidden="true">4</span>
                    <div class="a-check-body">
                        <label class="a-check-title" for="decl-object">{{ __('declaration.annex.p4') }}</label>
                        <textarea id="decl-object" name="object" class="a-textarea" rows="5" maxlength="3000">{{ old('object', $data->value('object')) }}</textarea>
                        @if (! $data->saved('object'))
                            <p class="a-decl-from"><x-lucide-file-input aria-hidden="true" />{{ __('declaration.proposed_object') }}</p>
                        @endif
                        @if ($data->imagePath())
                            <label class="a-switch a-decl-image">
                                <span class="a-switch-text flex items-center gap-2"><x-ui.thumb :product="$version->product" size="sm" />{{ __('declaration.fields.include_image') }}</span>
                                <input type="hidden" name="include_image" value="0">
                                <input type="checkbox" name="include_image" value="1" @checked(old('include_image', $data->includeImage() ? '1' : '0') === '1')>
                                <span class="a-switch-pill" data-on="{{ __('common.yes') }}" data-off="{{ __('common.no') }}" aria-hidden="true"></span>
                            </label>
                        @else
                            <p class="a-help">{{ __('declaration.no_picture') }} <a href="{{ route('versions.edit', $version) }}#picture-title" class="a-link-btn">{{ __('declaration.add_picture') }}</a></p>
                        @endif
                    </div>
                </div>

                {{-- 5. Union harmonisation legislation --}}
                <div class="a-check-card a-decl-point">
                    <span class="a-check-no" aria-hidden="true">5</span>
                    <div class="a-check-body">
                        <h3 class="a-check-title">{{ __('declaration.annex.p5') }}</h3>
                        <ul class="a-decl-fixed">
                            <li><x-lucide-landmark aria-hidden="true" /><b>{{ __('declaration.annex.p5_red') }}</b></li>
                            @if ($data->articlesInScope() !== [])
                                <li><x-lucide-landmark aria-hidden="true" />{{ __('declaration.word.delegated', ['articles' => implode(', ', array_column($data->articlesInScope(), 'label'))]) }}</li>
                            @endif
                        </ul>
                        <p class="a-decl-from"><x-lucide-target aria-hidden="true" />{{ __('declaration.from_scoping') }}
                            <a href="{{ route('versions.scoping', $version) }}" class="a-link-btn">{{ __('workflow.steps.scoping') }}</a></p>
                        <fieldset class="a-decl-rows">
                            <legend class="a-micro">{{ __('declaration.annex.p5_other') }}</legend>
                            @foreach ([...$legislation, '', ''] as $i => $line)
                                <input name="legislation[]" class="a-input a-input-sm" maxlength="200" list="legislation-suggestions" value="{{ $line }}"
                                       aria-label="{{ __('declaration.annex.p5_other') }} {{ $i + 1 }}" placeholder="{{ $i === 0 ? __('declaration.fields.legislation_placeholder') : '' }}">
                            @endforeach
                            <datalist id="legislation-suggestions">
                                @foreach (\App\Declaration\DeclarationData::LEGISLATION_SUGGESTIONS as $suggestion)
                                    <option value="{{ $suggestion }}"></option>
                                @endforeach
                            </datalist>
                            <p class="a-help">{{ __('declaration.fields.legislation_help') }}</p>
                        </fieldset>
                    </div>
                </div>

                {{-- 6. Standards --}}
                <div class="a-check-card a-decl-point" id="standards">
                    <span class="a-check-no" aria-hidden="true">6</span>
                    <div class="a-check-body">
                        <h3 class="a-check-title">{{ __('declaration.annex.p6') }}</h3>
                        <div class="a-decl-std-group">
                            <p class="a-decl-std-head"><span class="a-micro">{{ __('declaration.standards.assessed') }}</span><span class="a-badge a-badge-success"><x-lucide-badge-check aria-hidden="true" />{{ __('declaration.standards.assessed_badge') }}</span></p>
                            @forelse ($data->assessedStandards() as $standard)
                                <p class="a-decl-std"><b>{{ $standard['reference'] }}</b><span class="a-muted">{{ $standard['article'] }}</span></p>
                            @empty
                                <p class="a-decl-missing"><x-lucide-circle-alert aria-hidden="true" />{{ __('declaration.standards.none_assessed') }}</p>
                            @endforelse
                            @if ($data->assessedStandards() !== [])
                                <p class="a-help">{{ $data->citedWithRestrictions() ? __('declaration.standards.restrictions') : __('declaration.standards.cited') }}</p>
                            @endif
                        </div>
                        @foreach (\App\Declaration\DeclarationData::OTHER_ARTICLES as $group)
                            <fieldset class="a-decl-std-group a-decl-rows">
                                <legend class="a-decl-std-head">
                                    <span class="a-micro">{{ __('declaration.standards.groups.'.str_replace('.', '', $group)) }}</span>
                                    <span class="a-badge a-badge-warning" title="{{ __('declaration.standards.entered_tip') }}"><x-lucide-user aria-hidden="true" />{{ __('declaration.standards.entered_badge') }}</span>
                                </legend>
                                @foreach ($standardRows($group) as $entry)
                                    <input type="hidden" name="standards[{{ $row }}][article]" value="{{ $group }}">
                                    <input name="standards[{{ $row }}][reference]" class="a-input a-input-sm" maxlength="160" value="{{ $entry['reference'] ?? '' }}"
                                           @isset(\App\Declaration\DeclarationData::SUGGESTIONS[$group]) list="standards-{{ str_replace('.', '', $group) }}" @endisset
                                           aria-label="{{ __('declaration.standards.groups.'.str_replace('.', '', $group)) }}" placeholder="{{ $loop->first ? (\App\Declaration\DeclarationData::SUGGESTIONS[$group][0] ?? __('declaration.standards.placeholder_other')) : '' }}">
                                    @php $row++; @endphp
                                @endforeach
                                @isset(\App\Declaration\DeclarationData::SUGGESTIONS[$group])
                                    <datalist id="standards-{{ str_replace('.', '', $group) }}">
                                        @foreach (\App\Declaration\DeclarationData::SUGGESTIONS[$group] as $suggestion)
                                            <option value="{{ $suggestion }}"></option>
                                        @endforeach
                                    </datalist>
                                @endisset
                            </fieldset>
                        @endforeach
                        <p class="a-help">{{ __('declaration.standards.help') }}</p>
                    </div>
                </div>

                {{-- 7. Notified body --}}
                <div @class(['a-check-card a-decl-point', 'is-na' => $nb === null])>
                    <span class="a-check-no" aria-hidden="true">7</span>
                    <div class="a-check-body">
                        <h3 class="a-check-title">{{ __('declaration.annex.p7') }}</h3>
                        @if ($nb === null)
                            <p class="a-help">{{ $version->conformity_route ? __('declaration.word.nb_na') : __('declaration.nb.no_route') }}
                                <a href="{{ route('versions.conformity', $version) }}" class="a-link-btn">{{ __('workflow.steps.conformity') }}</a></p>
                        @else
                            <dl class="a-dl a-decl-dl">
                                <dt>{{ __('identification.notified_body') }}</dt><dd>{{ $nb['name'] ?: '—' }}</dd>
                                <dt>{{ __('identification.notified_body_number') }}</dt>
                                <dd>{{ $nb['number'] ?: '—' }}
                                    @if ($nbUnknown)<span class="a-badge a-badge-warning ml-1" title="{{ __('declaration.nando.not_listed', ['date' => \App\Support\NotifiedBodies::retrieved()]) }}"><x-lucide-triangle-alert aria-hidden="true" />{{ __('declaration.nando.not_listed_short') }}</span>@endif
                                </dd>
                                <dt>{{ __('declaration.nb.certificate') }}</dt>
                                <dd>{{ $nb['certificate'] ?: __('declaration.nb.after_review') }}</dd>
                            </dl>
                            <p class="a-decl-from"><x-lucide-badge-check aria-hidden="true" />{{ __('declaration.from_conformity') }}
                                <a href="{{ route('versions.conformity', $version) }}" class="a-link-btn">{{ __('workflow.steps.conformity') }}</a></p>
                            <div class="a-field">
                                <label class="a-label" for="decl-nb-task">{{ __('declaration.fields.nb_task') }}</label>
                                <input id="decl-nb-task" name="nb_task" class="a-input a-input-sm" maxlength="400" value="{{ old('nb_task', $data->value('nb_task')) }}">
                            </div>
                        @endif
                    </div>
                </div>

                {{-- 8. Accessories, components, software --}}
                <div class="a-check-card a-decl-point">
                    <span class="a-check-no" aria-hidden="true">8</span>
                    <div class="a-check-body">
                        <label class="a-check-title" for="decl-accessories">{{ __('declaration.annex.p8') }}</label>
                        <textarea id="decl-accessories" name="accessories" class="a-textarea" rows="4" maxlength="3000" placeholder="{{ __('declaration.word.not_applicable') }}">{{ old('accessories', $data->value('accessories')) }}</textarea>
                        @if ($components !== [])
                            <details class="a-decl-suggest">
                                <summary><x-lucide-cpu aria-hidden="true" />{{ trans_choice('declaration.components', count($components), ['count' => count($components)]) }}</summary>
                                <ul>
                                    @foreach ($components as $component)
                                        <li><b>{{ $component['name'] }}</b>@if ($component['version'] !== '') <span class="a-code">{{ $component['version'] }}</span>@endif @if ($component['type'] !== '')<span class="a-muted">{{ $component['type'] }}</span>@endif</li>
                                    @endforeach
                                </ul>
                                <p class="a-help">{{ __('declaration.components_help') }}</p>
                            </details>
                        @endif
                    </div>
                </div>

                {{-- 9. Additional information --}}
                <div class="a-check-card a-decl-point">
                    <span class="a-check-no" aria-hidden="true">9</span>
                    <div class="a-check-body">
                        <label class="a-check-title" for="decl-additional">{{ __('declaration.annex.p9') }}</label>
                        <textarea id="decl-additional" name="additional" class="a-textarea" rows="3" maxlength="3000" placeholder="{{ __('declaration.fields.additional_placeholder') }}">{{ old('additional', $data->value('additional')) }}</textarea>
                    </div>
                </div>

                {{-- Signature --}}
                <div class="a-check-card a-decl-point">
                    <span class="a-check-no" aria-hidden="true"><x-lucide-signature class="size-3.5" aria-hidden="true" /></span>
                    <div class="a-check-body">
                        <h3 class="a-check-title">{{ __('declaration.signature') }}</h3>
                        <div class="a-form-grid">
                            <div class="a-field a-span-all">
                                <label class="a-label" for="decl-signed-for">{{ __('declaration.annex.signed_for') }}</label>
                                <input id="decl-signed-for" name="signed_for" class="a-input a-input-sm" maxlength="200" value="{{ old('signed_for', $data->value('signed_for')) }}">
                            </div>
                            <div class="a-field">
                                <label class="a-label" for="decl-place">{{ __('declaration.fields.place') }}</label>
                                <input id="decl-place" name="place" class="a-input a-input-sm" maxlength="120" value="{{ old('place', $data->value('place')) }}">
                            </div>
                            <div class="a-field">
                                <label class="a-label" for="decl-date">{{ __('declaration.fields.date') }}</label>
                                <input id="decl-date" name="date" type="date" class="a-input a-input-sm" value="{{ old('date', $data->value('date')) }}">
                                @error('date')<p class="a-error">{{ $message }}</p>@enderror
                            </div>
                            <div class="a-field">
                                <label class="a-label" for="decl-name">{{ __('declaration.fields.signatory_name') }}</label>
                                <input id="decl-name" name="signatory_name" class="a-input a-input-sm" maxlength="120" autocomplete="name" value="{{ old('signatory_name', $data->value('signatory_name')) }}">
                            </div>
                            <div class="a-field">
                                <label class="a-label" for="decl-function">{{ __('declaration.fields.signatory_function') }}</label>
                                <input id="decl-function" name="signatory_function" class="a-input a-input-sm" maxlength="120" autocomplete="organization-title" value="{{ old('signatory_function', $data->value('signatory_function')) }}">
                            </div>
                        </div>
                        <p class="a-help flex items-start gap-1.5"><x-lucide-pen-line class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ __('declaration.signature_help') }}</p>
                    </div>
                </div>
            </fieldset>
            @if ($canEdit)
                <div class="a-panel-foot">
                    <button type="submit" data-shortcut="mod+s" data-shortcut-label="{{ __('common.save') }}" class="a-btn a-btn-primary"><x-lucide-save aria-hidden="true" />{{ __('common.save') }}</button>
                </div>
            @endif
        </form>
    </div>

    <aside class="a-aside-sticky a-stack">
        @php
            // One list: what makes the declaration valid, each open reason linking to its fix, done ones ticked.
            $openByKey = collect($data->openItems())->keyBy('key');
            $checks = [];
            $checks[] = $approved
                ? ['done' => true, 'label' => \App\Assessment\ReviewRights::selfApproved($version) ? __('declaration.checks.approved_self') : __('declaration.checks.approved'), 'href' => null]
                : ['done' => false] + $openByKey['not_approved'];
            $assessmentOpen = $openByKey->except(['not_approved', 'certificate'])->values()->all();
            foreach ($assessmentOpen as $item) {
                $checks[] = ['done' => false] + $item;
            }
            if ($assessmentOpen === []) {
                $checks[] = ['done' => true, 'label' => __('declaration.checks.assessment'), 'href' => null];
            }
            $checks[] = ['done' => $data->hasManufacturer(), 'label' => __('declaration.checks.manufacturer'), 'href' => '#manufacturer'];
            if ($data->needsNotifiedBody()) {
                $checks[] = $data->hasCertificate()
                    ? ['done' => true, 'label' => __('declaration.checks.certificate'), 'href' => null]
                    : ['done' => false] + $openByKey['certificate'];
            }
            $signed = array_intersect(['signatory', 'place', 'date'], $data->missing()) === [];
            $checks[] = ['done' => $signed, 'label' => __('declaration.checks.signature'), 'href' => '#decl-signed-for'];
            [$stateLabel, $stateBadge] = match (true) {
                $data->complete() => [__('declaration.state.ready'), 'a-badge-success'],
                $data->isDraft() => [$data->watermark(), 'a-badge-danger'],
                default => [__('declaration.state.to_complete'), 'a-badge-warning'],
            };
        @endphp
        <section class="a-panel" aria-labelledby="decl-state-title">
            <div class="a-panel-body a-stack gap-3!">
                <div class="a-decl-state">
                    <h2 class="a-micro" id="decl-state-title">{{ __('declaration.state.title') }}</h2>
                    <span class="a-badge {{ $stateBadge }}">{{ $stateLabel }}</span>
                </div>
                <ul class="a-decl-checks">
                    @foreach ($checks as $check)
                        <li @class(['is-ok' => $check['done']])>
                            @if ($check['done'])
                                <x-lucide-circle-check aria-hidden="true" /><span class="a-sr-only">{{ __('workflow.done') }} · </span>{{ $check['label'] }}
                            @else
                                <x-lucide-circle-dashed aria-hidden="true" />
                                @if ($check['href'])<a href="{{ $check['href'] }}" class="a-link-btn">{{ $check['label'] }}</a>@else{{ $check['label'] }}@endif
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="a-decl-downloads">
                    {{-- One official language (Annex VI as published in the Official Journal), or all of them in a zip. --}}
                    <form method="POST" action="{{ route('versions.declaration.pick', $version) }}" class="a-decl-lang">
                        @csrf
                        <label class="a-micro" for="decl-lang">{{ __('declaration.download_word') }}</label>
                        <span class="a-decl-lang-row">
                            <select id="decl-lang" name="lang" class="a-select a-input-sm">
                                @foreach (\App\Declaration\AnnexText::languages() as $code => $name)
                                    <option value="{{ $code }}" lang="{{ $code }}" @selected($code === \App\Declaration\AnnexText::pick(app()->getLocale()))>{{ $name }} · {{ strtoupper($code) }}</option>
                                @endforeach
                            </select>
                            <span class="a-decl-split">
                                <button type="submit" name="format" value="docx" class="a-btn a-btn-outline a-btn-sm"><x-lucide-download aria-hidden="true" />{{ __('declaration.download_one') }}</button>
                                <button type="submit" name="format" value="pdf" class="a-btn a-btn-outline a-btn-sm">{{ __('declaration.download_pdf') }}</button>
                            </span>
                        </span>
                    </form>
                    <a href="{{ route('versions.declaration.all', $version) }}" class="a-btn a-btn-outline a-btn-sm a-btn-block" download title="{{ __('declaration.all_languages_tip', ['count' => count(\App\Declaration\AnnexText::languages())]) }}">
                        <x-lucide-languages aria-hidden="true" />{{ __('declaration.all_languages', ['count' => count(\App\Declaration\AnnexText::languages())]) }}</a>
                    <p class="a-help">{{ __('declaration.word_only') }}</p>
                </div>
            </div>
            @if ($canEdit)
                <div class="a-panel-foot">
                    <button type="submit" form="declaration-form" class="a-btn a-btn-primary a-btn-block"><x-lucide-save aria-hidden="true" />{{ __('common.save') }}</button>
                </div>
            @endif
        </section>

        {{-- Annex VII: the simplified declaration, for the user manual. --}}
        <section class="a-panel" id="simplified" aria-labelledby="simplified-title">
            <div class="a-panel-head"><h2 class="a-h3 flex items-center gap-2" id="simplified-title"><x-lucide-scroll-text class="size-4 text-brand-blue" aria-hidden="true" />{{ __('declaration.simplified.title') }}</h2></div>
            <div class="a-panel-body a-stack gap-2!">
                <div class="a-field">
                    <label class="a-label" for="decl-url">{{ __('declaration.fields.url') }}</label>
                    <input id="decl-url" name="url" form="declaration-form" type="url" class="a-input a-input-sm" maxlength="300" placeholder="https://" value="{{ old('url', $data->value('url')) }}" @disabled(! $canEdit)>
                    @error('url')<p class="a-error">{{ $message }}</p>@enderror
                </div>
                <label class="a-label" for="simplified-text">{{ __('declaration.simplified.copy') }}</label>
                <textarea id="simplified-text" class="a-textarea a-decl-simplified" rows="6" readonly>{{ $data->simplified() }}</textarea>
                <p class="a-help">{{ __('declaration.simplified.help') }}</p>
            </div>
        </section>
    </aside>
</div>
