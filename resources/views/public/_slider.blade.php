{{--
    /about "Everything in one place": the workflow as a vertical slider (cetome.com "vortex-tabs vertical",
    made richer). Numbered tabs on the left (the active one expanded), the screen of that step on the right.
    slider.js: tablist / tab / tabpanel, ↑/↓ (and ←/→), Home/End, gentle autoplay with a progress line that
    stops on any interaction or hover, never with prefers-reduced-motion. Pictures of the other steps are
    lazy in hidden panels, so each loads when its step is shown; the first one is eager.
    Without script every panel shows, one under the other. /about#<step> (the home page steps) opens that step.
    Each picture opens full screen (the corner button, or a click on the picture).
--}}
<div class="r-slider" data-slider>
    <div class="r-slider-tabs" role="tablist" aria-orientation="vertical" aria-label="{{ __('public.includes_title') }}">
        @foreach ($steps as $step => $label)
            <button type="button" role="tab" class="r-slider-tab" id="tour-tab-{{ $step }}" aria-controls="{{ $step }}"
                    aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" data-slider-tab>
                <span class="r-slider-no" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                <span class="r-slider-name">{{ $label }}</span>
                @if (Lang::has('public.tour.texts.'.$step))
                    <span class="r-slider-line">{{ __('public.tour.texts.'.$step) }}</span>
                @endif
                <span class="r-slider-progress" aria-hidden="true"><span></span></span>
            </button>
        @endforeach
    </div>
    <div class="r-slider-panels">
        @foreach ($steps as $step => $label)
            <div role="tabpanel" class="r-slider-panel" id="{{ $step }}" aria-labelledby="tour-tab-{{ $step }}" data-slider-panel>
                <div class="r-slider-head">
                    <p class="r-slider-head-no" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</p>
                    <div>
                        <h3 class="r-slider-head-name">{{ $label }}</h3>
                        @if (Lang::has('public.tour.texts.'.$step))
                            <p class="r-slider-head-line">{{ __('public.tour.texts.'.$step) }}</p>
                        @endif
                    </div>
                </div>
                <div class="r-slider-shot">
                    @include('public._tour-picture', ['step' => $step, 'label' => $label, 'eager' => $loop->first])
                    <button type="button" class="r-zoom-open" data-zoom-open aria-label="{{ __('public.tour.zoom.open', ['step' => $label]) }}">
                        <x-lucide-maximize-2 aria-hidden="true" />
                    </button>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Full screen (slider.js): the 2x picture of the current theme, as large as the screen allows;
         ←/→, the arrows or a swipe move between steps (the slider follows), Esc or a click beside it closes. --}}
    <dialog class="r-zoom" aria-labelledby="slider-zoom-title" data-slider-zoom>
        <div class="r-zoom-bar">
            <p class="r-zoom-title" id="slider-zoom-title"><span class="r-zoom-no" data-zoom-no></span> <span data-zoom-name></span></p>
            <button type="button" class="r-zoom-btn" data-zoom-go="-1" aria-label="{{ __('public.tour.zoom.previous') }}"><x-lucide-arrow-left aria-hidden="true" /></button>
            <button type="button" class="r-zoom-btn" data-zoom-go="1" aria-label="{{ __('public.tour.zoom.next') }}"><x-lucide-arrow-right aria-hidden="true" /></button>
            <button type="button" class="r-zoom-btn" data-zoom-close aria-label="{{ __('common.close') }}"><x-lucide-x aria-hidden="true" /></button>
        </div>
        <div class="r-zoom-stage" data-zoom-stage>
            <img alt="" data-zoom-img>
        </div>
    </dialog>
</div>
