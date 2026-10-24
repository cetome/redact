{{--
    Initial rating of one risk, inside the risk form: a readable summary (level, impact × likelihood,
    a small map showing where it sits) and a "Rate" button that opens the full matrix in a modal.
    The modal lives inside the form, so its radios (name="cell") are submitted with it.
    Without script the full matrix is shown in place (<noscript>).
    Params: $p (id prefix), $cell ("impact-likelihood" or ''), $cells, $levels, $initialRank, $editable.
--}}
@php
    $impactWords = __('risks.impact_scale');
    $likelihoodWords = __('risks.likelihood_scale');
    [$curImpact, $curLikelihood] = $cell !== '' ? array_map('intval', explode('-', $cell)) : [null, null];
@endphp
<div class="a-rating">
    <div class="a-minimap" aria-hidden="true" data-minimap>
        @foreach ([5, 4, 3, 2, 1] as $i)
            @foreach ([1, 2, 3, 4, 5] as $l)
                <span @class(['risk-level-'.($cells[$i.'-'.$l] ?? 0), 'is-current' => $cell === $i.'-'.$l]) data-minimap-cell="{{ $i }}-{{ $l }}"></span>
            @endforeach
        @endforeach
    </div>
    <div class="a-stack min-w-0 flex-1 gap-1.5!">
        <p class="flex flex-wrap items-center gap-2" data-heat-output data-none="{{ __('risks.not_rated') }}" data-template="{{ __('risks.rating_line') }}" aria-live="polite">
            @if ($initialRank)
                <span class="a-level risk-level-{{ $initialRank }}">{{ $levels[$initialRank]->name ?? '' }}</span>
                <span class="a-help">{{ __('risks.rating_line', ['impact' => $curImpact, 'likelihood' => $curLikelihood]) }}</span>
            @else
                <span class="a-level a-level-empty">{{ __('risks.not_rated') }}</span>
            @endif
        </p>
        @if ($editable)
            <button type="button" class="a-btn a-btn-outline a-btn-sm self-start js-only" data-dialog-open="{{ $p }}-rate"><x-lucide-grid-3x3 aria-hidden="true" />{{ __('risks.rate') }}</button>
        @endif
    </div>
</div>

@if ($editable)
    {{-- The matrix: rows = impact (5 at the top), columns = likelihood; each cell names its level. --}}
    <dialog id="{{ $p }}-rate" class="a-modal a-modal-wide" aria-labelledby="{{ $p }}-rate-title">
        <div class="a-drawer-head">
            <div class="min-w-0">
                <h2 class="a-drawer-title" id="{{ $p }}-rate-title">{{ __('risks.rate_title') }}</h2>
                <p class="a-help mt-0.5">{{ __('risks.rate_help') }}</p>
            </div>
            <button type="button" class="a-close" data-dialog-close aria-label="{{ __('common.close') }}"><x-lucide-x aria-hidden="true" /></button>
        </div>
        <div class="a-drawer-body">@include('risks._rating-matrix')</div>
        <div class="a-drawer-foot">
            <label class="a-chip-toggle a-chip-none">
                <input type="radio" name="cell" value="" @checked($cell === '')>
                <span>{{ __('risks.clear_rating') }}</span>
            </label>
            <span class="a-spacer"></span>
            <button type="button" class="a-btn a-btn-primary" data-dialog-close><x-lucide-check aria-hidden="true" />{{ __('risks.rate_done') }}</button>
        </div>
    </dialog>
    <noscript>@include('risks._rating-matrix')</noscript>
@endif
