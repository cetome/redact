<x-layouts.app :title="__('admin.products.title')" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="__('admin.products.title')" :subtitle="__('admin.products.lead')">
        <x-slot:eyebrow>{{ __('admin.title') }}</x-slot:eyebrow>
    </x-ui.page-header>

    <form method="POST" action="{{ route('admin.products.filter') }}" class="a-toolbar" role="search">
        @csrf
        <label class="a-search">
            <span class="a-sr-only">{{ __('common.search') }}</span>
            <x-lucide-search aria-hidden="true" />
            <input id="q" name="q" type="search" class="a-input a-input-sm" value="{{ $filters['q'] }}" maxlength="120"
                placeholder="{{ __('admin.products.search_placeholder') }}">
        </label>
        <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-filter aria-hidden="true" />{{ __('admin.filter') }}</button>
        @if (filled($filters['q']))<button type="submit" name="reset" value="1" class="a-btn a-btn-ghost a-btn-sm"><x-lucide-rotate-ccw aria-hidden="true" />{{ __('admin.reset') }}</button>@endif
        <span class="a-toolbar-end a-toolbar-count">{{ trans_choice('admin.products.count', $products->total(), ['count' => number_format($products->total())]) }}</span>
    </form>

    <div class="a-table-wrap">
        <table class="a-table">
            <caption class="a-sr-only">{{ __('admin.products.title') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('admin.products.name') }}</th>
                    <th scope="col">{{ __('admin.products.organisation') }}</th>
                    <th scope="col">{{ __('admin.products.owner') }}</th>
                    <th scope="col" class="is-num">{{ __('admin.products.versions') }}</th>
                    <th scope="col">{{ __('admin.products.latest') }}</th>
                    <th scope="col" class="is-num">{{ __('admin.products.members') }}</th>
                    <th scope="col">{{ __('admin.products.last_activity') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    @php($lastActivity = $product->last_hit_at ? \Carbon\Carbon::parse($product->last_hit_at) : $product->updated_at)
                    <tr>
                        <th scope="row">
                            <a href="{{ route('products.show', $product) }}" class="a-row-link" data-row-link>{{ $product->name }}</a>
                            @if ($product->is_demo)
                                <span class="a-badge a-badge-neutral">{{ __('plans.slots.demo') }}</span>
                            @endif
                            @if ($product->reference)
                                <span class="a-row-sub">{{ $product->reference }}</span>
                            @endif
                        </th>
                        <td>{{ $product->organisation?->name ?? '—' }}</td>
                        <td>
                            @if ($product->owner)
                                <a href="{{ route('admin.users.show', $product->owner) }}" class="a-link-btn">{{ $product->owner->name }}</a>
                                <span class="a-row-sub">{{ $product->owner->email }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="is-num">{{ $product->versions_count }}</td>
                        <td class="is-nowrap">
                            @if ($product->latest_status && ($status = \App\Enums\VersionStatus::tryFrom($product->latest_status)))
                                <span class="font-semibold">{{ $product->latest_label }}</span>
                                <x-version-status :status="$status" class="ml-1" />
                            @elseif ($product->latest_status)
                                {{ $product->latest_label }} · {{ $product->latest_status }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="is-num">{{ $product->members_count }}</td>
                        <td class="is-nowrap a-muted">{{ $lastActivity?->isoFormat('D MMMM YYYY, HH:mm') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="a-muted">{{ __('admin.products.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>
</x-layouts.app>
