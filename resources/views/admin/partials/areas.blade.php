{{-- The main areas of a person's or product's work (App\Admin\WorkActivity::AREAS), largest first. Expects $areas. --}}
<ul class="a-work-areas">
    @foreach (array_slice($areas, 0, 3, true) as $area => $count)
        <li><span @class(['a-work-dot', 'is-'.$area]) aria-hidden="true"></span>{{ __('admin.work.areas.'.$area) }} <span class="a-work-count">{{ \Illuminate\Support\Number::format($count) }}</span></li>
    @endforeach
</ul>
