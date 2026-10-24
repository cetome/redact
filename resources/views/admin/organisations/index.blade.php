<x-layouts.app :title="__('plans.admin.title')" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="__('plans.admin.title')" :subtitle="__('plans.admin.lead')">
        <x-slot:eyebrow>{{ __('admin.title') }}</x-slot:eyebrow>
    </x-ui.page-header>

    <form method="POST" action="{{ route('admin.organisations.filter') }}" class="a-toolbar" role="search">
        @csrf
        <label class="a-search">
            <span class="a-sr-only">{{ __('common.search') }}</span>
            <x-lucide-search aria-hidden="true" />
            <input id="q" name="q" type="search" class="a-input a-input-sm" value="{{ $filters['q'] }}" maxlength="120"
                placeholder="{{ __('plans.admin.search_placeholder') }}">
        </label>
        <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-filter aria-hidden="true" />{{ __('admin.filter') }}</button>
        @if (filled($filters['q']))<button type="submit" name="reset" value="1" class="a-btn a-btn-ghost a-btn-sm"><x-lucide-rotate-ccw aria-hidden="true" />{{ __('admin.reset') }}</button>@endif
        <span class="a-toolbar-end a-toolbar-count">{{ trans_choice('plans.admin.count', $organisations->total(), ['count' => number_format($organisations->total())]) }}</span>
    </form>

    <div class="a-table-wrap">
        <table class="a-table">
            <caption class="a-sr-only">{{ __('plans.admin.title') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('plans.admin.name') }}</th>
                    <th scope="col">{{ __('plans.admin.plan') }}</th>
                    <th scope="col" class="is-num">{{ __('plans.admin.products') }}</th>
                    <th scope="col" class="is-num">{{ __('plans.admin.members') }}</th>
                    <th scope="col">{{ __('plans.admin.subscription') }}</th>
                    <th scope="col">{{ __('plans.admin.created') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($organisations as $organisation)
                    @php $subscription = $organisation->subscriptions->first(); @endphp
                    <tr>
                        <th scope="row">
                            <a href="{{ route('admin.organisations.show', $organisation) }}" class="a-row-link" data-row-link>{{ $organisation->name }}</a>
                        </th>
                        <td>
                            <span class="a-badge {{ $organisation->plan === 'enterprise' ? 'a-badge-dark' : 'a-badge-neutral' }}">{{ __('plans.name.'.($organisation->plan === 'enterprise' ? 'enterprise' : 'free')) }}</span>
                            @if ($organisation->limits)
                                <span class="a-badge a-badge-outline" title="{{ json_encode($organisation->limits) }}">{{ __('plans.admin.overrides') }}</span>
                            @endif
                        </td>
                        <td class="is-num">{{ $organisation->products_count }}</td>
                        <td class="is-num">{{ $organisation->members_count }}</td>
                        <td class="is-nowrap">
                            @if ($subscription)
                                {{ $subscription->stripe_status }} · {{ (int) $subscription->quantity }}
                            @else
                                <span class="a-muted">—</span>
                            @endif
                        </td>
                        <td class="is-nowrap a-muted">{{ $organisation->created_at?->isoFormat('D MMMM YYYY') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="a-muted">{{ __('plans.admin.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $organisations->links() }}</div>
</x-layouts.app>
