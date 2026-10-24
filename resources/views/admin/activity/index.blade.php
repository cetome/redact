<x-layouts.app :title="__('admin.activity.title')" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="__('admin.activity.title')" :subtitle="__('admin.activity.lead')">
        <x-slot:eyebrow>{{ __('admin.title') }}</x-slot:eyebrow>
        @if ($canExport)
            <x-slot:actions>
                <a href="{{ route('admin.activity.export') }}" class="a-btn a-btn-outline"><x-lucide-download aria-hidden="true" />{{ __('admin.export_csv') }}</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <form method="POST" action="{{ route('admin.activity.filter') }}" class="a-panel mb-3 grid items-end gap-3 p-3 sm:grid-cols-3 lg:grid-cols-[repeat(6,minmax(0,1fr))_auto]">
        @csrf
        <div class="a-field">
            <label for="log" class="a-label">{{ __('admin.activity.log') }}</label>
            <select id="log" name="log" class="a-select a-input-sm">
                <option value="">{{ __('admin.all') }}</option>
                @foreach ($logNames as $name)
                    <option value="{{ $name }}" @selected($filters['log'] === $name)>{{ \App\Admin\Label::of('logs', $name) }}</option>
                @endforeach
            </select>
        </div>
        <div class="a-field">
            <label for="subject" class="a-label">{{ __('admin.activity.subject') }}</label>
            <select id="subject" name="subject" class="a-select a-input-sm">
                <option value="">{{ __('admin.all') }}</option>
                @foreach ($subjectTypes as $type => $short)
                    <option value="{{ $type }}" @selected($filters['subject'] === $type)>{{ $short }}</option>
                @endforeach
            </select>
        </div>
        <div class="a-field">
            <label for="causer" class="a-label">{{ __('admin.activity.causer') }}</label>
            <input id="causer" name="causer" type="text" class="a-input a-input-sm" value="{{ $filters['causer'] }}" maxlength="191"
                placeholder="{{ __('admin.activity.causer_placeholder') }}">
        </div>
        <div class="a-field">
            <label for="from" class="a-label">{{ __('admin.from') }}</label>
            <input id="from" name="from" type="date" class="a-input a-input-sm" value="{{ $filters['from'] }}">
        </div>
        <div class="a-field">
            <label for="to" class="a-label">{{ __('admin.to') }}</label>
            <input id="to" name="to" type="date" class="a-input a-input-sm" value="{{ $filters['to'] }}">
        </div>
        <div class="a-field">
            <label for="q" class="a-label">{{ __('admin.activity.text') }}</label>
            <input id="q" name="q" type="search" class="a-input a-input-sm" value="{{ $filters['q'] }}" maxlength="120">
        </div>
        <div class="flex gap-1.5">
            <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-filter aria-hidden="true" />{{ __('admin.filter') }}</button>
            <button type="submit" name="reset" value="1" class="a-btn a-btn-ghost a-btn-sm"><x-lucide-rotate-ccw aria-hidden="true" />{{ __('admin.reset') }}</button>
        </div>
    </form>

    <p class="a-toolbar-count mb-2">{{ trans_choice('admin.activity.count', $activities->total(), ['count' => number_format($activities->total())]) }}</p>

    <div class="a-table-wrap">
        <table class="a-table a-table-compact">
            <caption class="a-sr-only">{{ __('admin.activity.title') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('admin.activity.date') }}</th>
                    <th scope="col">{{ __('admin.activity.log') }}</th>
                    <th scope="col">{{ __('admin.activity.description') }}</th>
                    <th scope="col">{{ __('admin.activity.causer') }}</th>
                    <th scope="col">{{ __('admin.activity.subject') }}</th>
                    <th scope="col">{{ __('admin.activity.details') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($activities as $activity)
                    <tr class="align-top">
                        <td class="is-nowrap align-top! a-muted">{{ $activity->created_at?->isoFormat('D MMMM YYYY, HH:mm:ss') }}</td>
                        <td class="align-top!"><span class="a-badge a-badge-neutral">{{ \App\Admin\Label::of('logs', $activity->log_name) }}</span></td>
                        <td class="align-top! font-semibold">
                            {{ $activity->description }}
                            @if ($activity->event && $activity->event !== $activity->description)
                                <span class="a-row-sub">{{ $activity->event }}</span>
                            @endif
                        </td>
                        <td class="align-top!">
                            @if ($activity->causer instanceof \App\Models\User)
                                <a href="{{ route('admin.users.show', $activity->causer) }}" class="a-link-btn">{{ $activity->causer->email }}</a>
                            @else
                                <span class="a-muted">{{ \App\Admin\ActivityExplorer::causerLabel($activity) ?: __('admin.activity.system') }}</span>
                            @endif
                        </td>
                        <td class="align-top! is-nowrap">
                            @if ($activity->subject_type)
                                {{ class_basename($activity->subject_type) }}
                                <span class="a-row-sub a-mono">{{ $activity->subject_id }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="align-top! min-w-64">
                            @include('admin.partials.changes', ['changes' => \App\Admin\ActivityExplorer::changes($activity)])
                            @php($context = \App\Admin\ActivityExplorer::context($activity))
                            @if ($context !== [])
                                <dl class="a-dl mt-1 text-label!">
                                    @foreach ($context as $key => $value)
                                        @continue($key === 'ip' && ! $canExport)
                                        <dt>{{ $key }}</dt>
                                        <dd class="line-clamp-2 break-all font-normal!" title="{{ $value }}">{{ $value }}</dd>
                                    @endforeach
                                </dl>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="a-muted">{{ __('admin.activity.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $activities->links() }}</div>
</x-layouts.app>
