{{-- One step of the demo product, light and dark, 1x/2x (public/images/tour, see its README). Lazy unless
     $eager. A step without pictures yet (just added to the workflow) shows none. --}}
@if (is_file(public_path('images/tour/'.$step.'-light.webp')))
<figure class="r-tour-figure">
    @foreach (['light', 'dark'] as $theme)
        <img src="{{ asset('images/tour/'.$step.'-'.$theme.'.webp') }}"
             srcset="{{ asset('images/tour/'.$step.'-'.$theme.'.webp') }} 1x, {{ asset('images/tour/'.$step.'-'.$theme.'@2x.webp') }} 2x"
             width="1440" height="900" loading="{{ ($eager ?? false) ? 'eager' : 'lazy' }}" decoding="async" alt="{{ __('public.tour.alt', ['step' => $label]) }}" class="c-only-{{ $theme }}">
    @endforeach
</figure>
@endif
