{{--
    The rating matrix of the risk form (see risks/_rating): impact rows from 5 (top) to 1, likelihood
    columns 1 to 5, both named; each cell is a radio named "cell" showing the level it gives.
--}}
<div class="a-rate" role="radiogroup" aria-label="{{ __('risks.rate_title') }}">
    <div class="a-rate-corner">
        <span>{{ __('risks.impact') }} ↓</span>
        <span>{{ __('risks.likelihood') }} →</span>
    </div>
    @foreach ([1, 2, 3, 4, 5] as $l)
        <div class="a-rate-col"><b>{{ $l }}</b><span>{{ $likelihoodWords[$l] ?? '' }}</span></div>
    @endforeach
    @foreach ([5, 4, 3, 2, 1] as $i)
        <div class="a-rate-row"><b>{{ $i }}</b><span>{{ $impactWords[$i] ?? '' }}</span></div>
        @foreach ([1, 2, 3, 4, 5] as $l)
            @php $rank = $cells[$i.'-'.$l] ?? null; @endphp
            <label class="a-rate-cell risk-level-{{ $rank }}" data-rank="{{ $rank }}" data-name="{{ $levels[$rank]->name ?? '' }}">
                <input type="radio" name="cell" value="{{ $i }}-{{ $l }}" @checked($cell === $i.'-'.$l)
                       aria-label="{{ __('risks.impact') }} {{ $i }} ({{ $impactWords[$i] ?? '' }}), {{ __('risks.likelihood') }} {{ $l }} ({{ $likelihoodWords[$l] ?? '' }}): {{ $levels[$rank]->name ?? '' }}">
                <span class="a-rate-level">{{ $levels[$rank]->name ?? '' }}</span>
                <span class="a-rate-score" aria-hidden="true">{{ $i }} × {{ $l }}</span>
            </label>
        @endforeach
    @endforeach
</div>
