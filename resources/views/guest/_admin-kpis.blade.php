{{-- Console overview: guest sandboxes (App\Admin\DashboardStats::sandboxes). Expects $sandboxes. --}}
<section class="a-section" aria-labelledby="sandbox-heading">
    <div class="a-section-head">
        <div>
            <h2 id="sandbox-heading" class="a-h2">{{ __('admin.sandboxes.title') }}</h2>
            <p class="a-help mt-0.5">{{ __('admin.sandboxes.help') }}</p>
        </div>
    </div>
    <ul class="a-stats grid-cols-2! sm:grid-cols-4!">
        @foreach ([
            [__('admin.sandboxes.active'), $sandboxes['active'], null],
            [__('admin.sandboxes.started'), $sandboxes['started_7'], __('admin.sandboxes.in_30', ['count' => number_format($sandboxes['started_30'])])],
            [__('admin.sandboxes.kept'), $sandboxes['kept_7'], __('admin.sandboxes.in_30', ['count' => number_format($sandboxes['kept_30'])])],
            [__('admin.sandboxes.expired'), $sandboxes['expired_7'], __('admin.sandboxes.in_30', ['count' => number_format($sandboxes['expired_30'])])],
        ] as [$label, $value, $sub])
            <li class="a-stat">
                <span class="a-stat-label">{{ $label }}</span>
                <span class="a-stat-value">{{ number_format($value) }}</span>
                @if ($sub)
                    <span class="a-help">{{ $sub }}</span>
                @endif
            </li>
        @endforeach
    </ul>
</section>
