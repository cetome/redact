{{--
    Confirmation of the articles and standards (ArticlesCheck::state()) and the buttons of the
    form: Save, Confirm (saves, then records who and when), Save and continue (assets & interfaces).
    Body and foot of the aside panel, after scoping._result.
    @include('scoping._confirm', ['state' => 'unanswered|unconfirmed|stale|confirmed', 'confirmedBy' => ?string, 'editable' => bool])
--}}
@php
    $when = $version->articles_confirmed_at?->isoFormat('D MMMM YYYY');
    $who = $confirmedBy ?: __('articles.someone');
    $icon = ['confirmed' => 'circle-check', 'stale' => 'history', 'unconfirmed' => 'circle-dashed', 'unanswered' => 'circle-dashed'][$state];
    $next = \App\Assessment\Assessment::for($version)->nextStepAfter('scoping');
@endphp
<div class="a-art-confirm is-{{ $state }}" role="status">
    <x-dynamic-component :component="'lucide-'.$icon" aria-hidden="true" />
    <span>
        <span class="a-micro block">{{ __('articles.confirmation') }}</span>
        {{ __('articles.state.'.$state, ['name' => $who, 'date' => $when]) }}
    </span>
</div>
@if ($editable)
    <div class="a-panel-foot a-art-actions">
        @if ($state !== 'confirmed')
            <button type="submit" name="confirm" value="1" class="a-btn a-btn-primary a-btn-block" title="{{ __('articles.confirm_help') }}">
                <x-lucide-check-check aria-hidden="true" />{{ $state === 'stale' ? __('articles.confirm_again') : __('articles.confirm') }}
            </button>
        @endif
        <button type="submit" data-shortcut="mod+s" data-shortcut-label="{{ __('common.save') }}" class="a-btn a-btn-outline"><x-lucide-save aria-hidden="true" />{{ __('common.save') }}</button>
        <button type="submit" name="continue" value="1" @class(['a-btn', 'a-btn-primary' => $state === 'confirmed', 'a-btn-outline' => $state !== 'confirmed'])
                @if ($next) title="{{ __('workflow.next_step_go', ['step' => $next['label']]) }}" @endif>{{ __('scoping.continue') }}<x-lucide-arrow-right aria-hidden="true" /></button>
    </div>
@endif
