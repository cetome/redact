<x-layouts.app :title="__('admin.users.title')" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="__('admin.users.title')">
        <x-slot:eyebrow>{{ __('admin.title') }}</x-slot:eyebrow>
        @if ($canExport)
            <x-slot:actions>
                <a href="{{ route('admin.users.export') }}" class="a-btn a-btn-outline"><x-lucide-download aria-hidden="true" />{{ __('admin.export_csv') }}</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <form method="POST" action="{{ route('admin.users.filter') }}" class="a-toolbar" role="search">
        @csrf
        <label class="a-search">
            <span class="a-sr-only">{{ __('common.search') }}</span>
            <x-lucide-search aria-hidden="true" />
            <input id="q" name="q" type="search" class="a-input a-input-sm" value="{{ $filters['q'] }}" maxlength="120"
                placeholder="{{ __('admin.users.search_placeholder') }}">
        </label>
        <label class="a-sr-only" for="status">{{ __('admin.users.status') }}</label>
        <select id="status" name="status" class="a-select a-input-sm w-auto!">
            <option value="">{{ __('admin.users.status') }} · {{ __('admin.all') }}</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __('admin.users.statuses.'.$status) }}</option>
            @endforeach
        </select>
        <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-filter aria-hidden="true" />{{ __('admin.filter') }}</button>
        @if (array_filter($filters))<button type="submit" name="reset" value="1" class="a-btn a-btn-ghost a-btn-sm"><x-lucide-rotate-ccw aria-hidden="true" />{{ __('admin.reset') }}</button>@endif
        <span class="a-toolbar-end a-toolbar-count">{{ trans_choice('admin.users.count', $users->total(), ['count' => \Illuminate\Support\Number::format($users->total())]) }}</span>
    </form>

    <div class="a-table-wrap">
        <table class="a-table">
            <caption class="a-sr-only">{{ __('admin.users.title') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('admin.users.name') }}</th>
                    <th scope="col">{{ __('admin.users.organisation') }}</th>
                    <th scope="col">{{ __('admin.users.created') }}</th>
                    <th scope="col">{{ __('admin.users.verified') }}</th>
                    <th scope="col">{{ __('admin.users.last_sign_in') }}</th>
                    <th scope="col" class="is-num">{{ __('admin.users.passkeys') }}</th>
                    <th scope="col" class="is-num">{{ __('admin.users.products') }}</th>
                    <th scope="col">{{ __('admin.users.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <th scope="row">
                            <a href="{{ route('admin.users.show', $user) }}" class="a-row-link" data-row-link>{{ $user->name }}</a>
                            <span class="a-row-sub">{{ $user->email }}</span>
                        </th>
                        <td>{{ \App\Admin\UserDirectory::organisationOf($user) ?: '—' }}</td>
                        <td class="is-nowrap">{{ $user->created_at?->isoFormat('D MMMM YYYY') }}</td>
                        <td class="is-nowrap">{{ $user->email_verified_at?->isoFormat('D MMMM YYYY') ?? __('common.no') }}</td>
                        <td class="is-nowrap">
                            @if ($user->last_login_at)
                                {{ $user->last_login_at->isoFormat('D MMMM YYYY, HH:mm') }}
                                <span class="a-row-sub">{{ \App\Admin\Label::of('methods', $user->last_login_method) }}</span>
                            @else
                                <span class="a-muted">{{ __('admin.never') }}</span>
                            @endif
                        </td>
                        <td class="is-num">{{ $user->passkeys_count }}</td>
                        <td class="is-num">{{ $user->products_count }}</td>
                        <td>@include('admin.users.status', ['user' => $user])</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="a-muted">{{ __('admin.users.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
</x-layouts.app>
