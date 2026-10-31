{{--
    "Changes" step (route versions.changes), only on a version made from an earlier one: the signs of
    a significant change since that version (new or higher risks, assets and interfaces added,
    changed or removed, parts of EN 18031 now in scope), what a significant change put back to
    re-check, and in the sticky aside the decision with its justification (SignificantChange).
--}}
@php
    $previous = $changes->previous->label;
    $editable = $access->canEdit($version);
    $count = count($changes->signs());
    $kinds = ['risk_new', 'risk_up', 'item_added', 'item_changed', 'item_removed', 'scope_added'];
    $when = $version->change_decided_at?->isoFormat('D MMMM YYYY');
    $who = $decidedBy ?: __('articles.someone');
    $box = ['to_decide' => 'unconfirmed', 'outdated' => 'stale', 'decided' => 'confirmed', 'no_signs' => 'confirmed'][$state];
    $icon = ['to_decide' => 'circle-dashed', 'outdated' => 'history', 'decided' => 'circle-check', 'no_signs' => 'circle-check'][$state];
    $current = old('decision', $version->change_decision);
@endphp
<x-layouts.app :title="__('changes.title', ['label' => $previous])">
    <x-slot:subnav><x-version-nav :version="$version" active="changes" /></x-slot:subnav>
    <x-read-only-notice :access="$access" :version="$version" />

    <x-ui.page-header :title="__('changes.title', ['label' => $previous])" :subtitle="__('changes.lead', ['label' => $previous])">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
    </x-ui.page-header>

    <div class="a-grid-main a-sign-grid">
        <div class="a-stack min-w-0">
            @if ($count === 0)
                <x-ui.empty icon="git-compare" :title="__('changes.no_signs_title')" :text="__('changes.no_signs_text', ['label' => $previous])" />
            @else
                <section aria-labelledby="signs-title">
                    <div class="a-section-head">
                        <h2 class="a-h3" id="signs-title">{{ __('changes.signs_title') }}</h2>
                        <span class="a-badge">{{ trans_choice('changes.sign_count', $count, ['count' => $count]) }}</span>
                    </div>
                    <div class="a-table-wrap">
                        <table class="a-table a-table-compact a-signs">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('changes.col.what') }}</th>
                                    <th scope="col">{{ __('changes.col.change') }}</th>
                                </tr>
                            </thead>
                            @foreach ($kinds as $kind)
                                @if ($signs->has($kind))
                                    <tbody>
                                        <tr class="a-table-group">
                                            <th scope="colgroup" colspan="2">
                                                <x-dynamic-component :component="'lucide-'.__('changes.icon.'.$kind)" aria-hidden="true" />
                                                {{ __('changes.kind.'.$kind) }} <span class="a-seg-count">{{ $signs[$kind]->count() }}</span>
                                            </th>
                                        </tr>
                                        @foreach ($signs[$kind] as $sign)
                                            <tr>
                                                <th scope="row" class="font-normal!">
                                                    @if ($sign['href'])
                                                        <a href="{{ $sign['href'] }}">{{ $sign['label'] }}</a>
                                                    @else
                                                        <span class="a-sign-removed">{{ $sign['label'] }}</span>
                                                    @endif
                                                </th>
                                                <td class="a-sign-detail">{{ $sign['detail'] !== '' ? $sign['detail'] : __('changes.detail.'.$kind) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                @endif
                            @endforeach
                        </table>
                    </div>
                </section>
            @endif

            @if ($changes->significant())
                <section aria-labelledby="recheck-title">
                    <div class="a-section-head">
                        <h2 class="a-h3" id="recheck-title">{{ __('changes.recheck_title') }}</h2>
                        @if ($rechecks !== [])
                            <span class="a-badge a-badge-warning">{{ trans_choice('changes.recheck_count', count($rechecks), ['count' => count($rechecks)]) }}</span>
                        @else
                            <span class="a-badge a-badge-success"><x-lucide-check aria-hidden="true" />{{ __('changes.recheck_done') }}</span>
                        @endif
                    </div>
                    @if ($rechecks === [])
                        <p class="a-help">{{ __('changes.recheck_none') }}</p>
                    @else
                        <p class="a-help mb-2">{{ __('changes.recheck_help') }}</p>
                        <div class="a-table-wrap">
                            <table class="a-table a-table-compact">
                                <tbody>
                                    @foreach ($rechecks as $row)
                                        <tr>
                                            <th scope="row" class="font-normal!">
                                                <x-dynamic-component :component="'lucide-'.($row['type'] === 'evaluation' ? 'git-fork' : 'file-text')" class="a-sign-type" aria-hidden="true" />
                                                <a href="{{ $row['href'] }}">{{ $row['label'] }}</a>
                                            </th>
                                            <td class="is-shrink">
                                                @if ($editable)
                                                    <form method="POST" action="{{ route($row['type'] === 'evaluation' ? 'versions.changes.confirm.evaluation' : 'versions.changes.confirm.einfo', [$version, $row['id']]) }}">
                                                        @csrf
                                                        <button type="submit" class="a-btn a-btn-outline a-btn-sm" title="{{ __('changes.still_valid_help') }}"><x-lucide-check aria-hidden="true" />{{ __('changes.still_valid') }}</button>
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif
        </div>

        <aside class="a-aside-sticky">
            <form method="POST" action="{{ route('versions.changes.decide', $version) }}" class="a-panel">
                @csrf
                <div class="a-panel-head"><h2 class="a-h3">{{ __('changes.decision') }}</h2></div>
                <div class="a-art-confirm is-{{ $box }} mt-3" role="status">
                    <x-dynamic-component :component="'lucide-'.$icon" aria-hidden="true" />
                    <span>
                        <span class="a-micro block">{{ __('changes.decision_state') }}</span>
                        {{ trans_choice('changes.state.'.($state === 'decided' ? $version->change_decision : $state), $count, ['name' => $who, 'date' => $when, 'label' => $previous, 'count' => $count]) }}
                    </span>
                </div>
                {{-- Out of date, an editor reads the justification in the form below: not twice. --}}
                @if ($version->change_justification && ($state === 'decided' || ($state === 'outdated' && ! $editable)))
                    <blockquote class="a-sign-why">{{ $version->change_justification }}</blockquote>
                @endif
                @if ($editable && ($state !== 'no_signs' || $version->change_decision))
                    {{-- Decided: the form folds under "Change the decision" (the justification is shown above). --}}
                    @if ($state === 'decided')
                        <details class="a-sign-form" @if ($errors->any()) open @endif>
                        <summary class="a-sign-change"><x-lucide-pencil aria-hidden="true" />{{ __('changes.change_decision') }}</summary>
                    @endif
                    <div class="a-panel-body a-stack gap-3! pt-0!">
                        <fieldset class="a-fieldset a-stack gap-2!">
                            <legend class="a-label">{{ __('changes.your_decision') }}</legend>
                            @foreach (\App\Assessment\SignificantChange::DECISIONS as $decision)
                                <label class="a-toggle-card">
                                    <input type="radio" name="decision" value="{{ $decision }}" @checked($current === $decision) required>
                                    <span><strong>{{ __('changes.decisions.'.$decision) }}</strong><span class="a-toggle-card-text">{{ __('changes.decisions_help.'.$decision) }}</span></span>
                                </label>
                            @endforeach
                            @error('decision')<p class="a-error">{{ $message }}</p>@enderror
                        </fieldset>
                        <div class="a-field">
                            <label class="a-label" for="justification">{{ __('changes.justification') }}</label>
                            <textarea id="justification" name="justification" class="a-textarea" rows="4" minlength="10" maxlength="5000" required aria-describedby="justification-help">{{ old('justification', $version->change_justification) }}</textarea>
                            <p class="a-help" id="justification-help">{{ __('changes.justification_help') }}</p>
                            @error('justification')<p class="a-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="a-panel-foot">
                        <button class="a-btn a-btn-primary a-btn-block" type="submit"><x-lucide-check-check aria-hidden="true" />{{ $state === 'outdated' ? __('changes.decide_again') : __('changes.decide') }}</button>
                    </div>
                    @if ($state === 'decided')
                        </details>
                    @endif
                @endif
                @if ($next)
                    <div class="a-panel-foot">
                        <a href="{{ $next['href'] }}" @class(['a-btn a-btn-block', 'a-btn-primary' => $changes->settled(), 'a-btn-outline' => ! $changes->settled()])>{{ __('workflow.next_step_go', ['step' => $next['label']]) }}<x-lucide-arrow-right aria-hidden="true" /></a>
                    </div>
                @endif
            </form>
        </aside>
    </div>
</x-layouts.app>
