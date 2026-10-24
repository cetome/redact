{{-- Contact form messages, newest first (read-only; App\Http\Controllers\Admin\ContactMessageController). --}}
<x-layouts.app :title="__('contact.admin.title')" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="__('contact.admin.title')" :subtitle="__('contact.admin.lead', ['days' => $retention])">
        <x-slot:eyebrow>{{ __('admin.title') }}</x-slot:eyebrow>
    </x-ui.page-header>

    <form method="POST" action="{{ route('admin.contact.filter') }}" class="a-toolbar" role="search">
        @csrf
        <label class="a-search">
            <span class="a-sr-only">{{ __('common.search') }}</span>
            <x-lucide-search aria-hidden="true" />
            <input id="q" name="q" type="search" class="a-input a-input-sm" value="{{ $q }}" maxlength="120"
                placeholder="{{ __('contact.admin.search_placeholder') }}">
        </label>
        <label for="topic" class="a-sr-only">{{ __('contact.fields.topic') }}</label>
        <select id="topic" name="topic" class="a-select a-input-sm w-auto">
            <option value="">{{ __('contact.admin.all_topics') }}</option>
            @foreach (\App\Models\ContactMessage::TOPICS as $value)
                <option value="{{ $value }}" @selected($topic === $value)>{{ __('contact.topics.'.$value) }}</option>
            @endforeach
        </select>
        <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-filter aria-hidden="true" />{{ __('admin.filter') }}</button>
        @if ($q !== '' || $topic)<button type="submit" name="reset" value="1" class="a-btn a-btn-ghost a-btn-sm"><x-lucide-rotate-ccw aria-hidden="true" />{{ __('admin.reset') }}</button>@endif
        <span class="a-toolbar-end a-toolbar-count">{{ trans_choice('contact.admin.count', $messages->total(), ['count' => number_format($messages->total())]) }}</span>
    </form>

    <div class="a-table-wrap">
        <table class="a-table">
            <caption class="a-sr-only">{{ __('contact.admin.title') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('contact.fields.name') }}</th>
                    <th scope="col">{{ __('contact.fields.company') }}</th>
                    <th scope="col">{{ __('contact.fields.topic') }}</th>
                    <th scope="col">{{ __('contact.admin.account') }}</th>
                    <th scope="col">{{ __('contact.admin.status') }}</th>
                    <th scope="col">{{ __('contact.admin.received') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($messages as $item)
                    <tr>
                        <th scope="row">
                            <a href="{{ route('admin.contact.show', $item) }}" class="a-row-link" data-row-link>{{ $item->name }}</a>
                            <span class="a-row-sub">{{ $item->email }}</span>
                        </th>
                        <td>{{ $item->company ?? '—' }}</td>
                        <td><span class="a-badge a-badge-neutral">{{ $item->topicLabel() }}</span></td>
                        <td class="is-nowrap">
                            @if ($item->user)
                                <a href="{{ route('admin.users.show', $item->user) }}" class="a-link-btn">#{{ $item->user->id }}</a>
                            @else
                                <span class="a-muted">—</span>
                            @endif
                        </td>
                        <td>@include('admin.contact._status', ['item' => $item])</td>
                        <td class="is-nowrap a-muted">{{ $item->created_at->isoFormat('D MMMM YYYY, HH:mm') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="a-muted">{{ __('contact.admin.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $messages->links() }}</div>
</x-layouts.app>
