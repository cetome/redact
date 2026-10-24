{{-- Console usage: page views by audience (App\Admin\UsageAnalytics::pageViewsByAudience). Expects $audience, $days. --}}
<section class="a-panel" aria-labelledby="audience-heading">
    <div class="a-panel-head">
        <div>
            <h2 id="audience-heading" class="a-h3">{{ __('admin.usage.audience') }}</h2>
            <p class="a-help mt-0.5">{{ __('admin.usage.audience_help', ['days' => $days]) }}</p>
        </div>
    </div>
    @include('admin.usage.split', [
        'rows' => collect($audience)->mapWithKeys(fn ($n, $key) => [__('admin.usage.audiences.'.$key) => $n])->all(),
        'labelHeading' => __('admin.usage.audience'), 'valueHeading' => __('admin.charts.views'), 'percent' => true,
    ])
</section>
