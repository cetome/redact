<x-layouts.app :title="__('import.title').' · '.$version->displayName()">
    <x-slot:subnav><x-version-nav :version="$version" active="description" /></x-slot:subnav>

    <x-ui.page-header :title="__('import.title')" :subtitle="__('import.intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
    </x-ui.page-header>

    <div class="a-stack">
        <div class="a-panel">
            <div class="a-panel-body flex flex-wrap items-start gap-x-8 gap-y-3">
                <div class="min-w-0">
                    <p class="a-micro">{{ __('import.files') }}</p>
                    <ul class="mt-1 a-stack gap-1!">
                        @foreach ($files as $file)
                            <li class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm">
                                <x-lucide-file-spreadsheet class="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                                <span class="min-w-0 break-words">{{ $file['name'] }}</span>
                                <span class="a-badge a-badge-neutral">cetome REDact · {{ $file['label'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @if ($documents !== [])
                    <div class="min-w-0">
                        <p class="a-micro">{{ trans_choice('import.documents_added', count($documents), ['count' => count($documents)]) }}</p>
                        <ul class="mt-1 a-stack gap-1! text-sm">
                            @foreach ($documents as $name)
                                <li class="flex items-center gap-2"><x-lucide-file-text class="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />{{ $name }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>

        @if ($fileErrors !== [])
            <div class="a-alert a-alert-danger" role="alert">
                <x-lucide-circle-x aria-hidden="true" />
                <div><p class="font-bold">{{ __('import.file_errors') }}</p>
                    <ul class="list-disc pl-5">@foreach ($fileErrors as $fileError)<li>{{ $fileError }}</li>@endforeach</ul></div>
            </div>
        @endif
        @if ($warnings !== [])
            <div class="a-alert a-alert-warning">
                <x-lucide-triangle-alert aria-hidden="true" />
                <div><p class="font-bold">{{ __('import.warnings') }}</p>
                    <ul class="list-disc pl-5">@foreach (array_slice($warnings, 0, 30) as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>
            </div>
        @endif

        <form method="POST" action="{{ route('versions.import.apply', [$version, $token]) }}" id="import-apply" class="a-stack">
            @csrf
            @foreach ($preview as $part => $rows)
                @continue($rows === [])
                @php
                    $counts = array_count_values(array_column($rows, 'status'));
                    $useful = ($counts['new'] ?? 0) + ($counts['change'] ?? 0) + ($counts['conflict'] ?? 0);
                @endphp
                <section class="a-panel" aria-labelledby="part-{{ $part }}-title">
                    <div class="a-panel-head flex flex-wrap items-center gap-3">
                        <label class="a-check" for="part-{{ $part }}">
                            <input type="checkbox" id="part-{{ $part }}" name="parts[]" value="{{ $part }}" @checked($useful > 0) @disabled($useful === 0)>
                            <span class="a-h3" id="part-{{ $part }}-title">{{ __('import.parts.'.$part) }}</span>
                        </label>
                        <span class="a-muted text-xs">{{ __('import.counts', ['new' => $counts['new'] ?? 0, 'change' => ($counts['change'] ?? 0) + ($counts['conflict'] ?? 0), 'same' => $counts['same'] ?? 0]) }}</span>
                    </div>
                    <div class="a-panel-body">
                        @if ($part === 'walks')
                            <p class="a-help mb-2">{{ __('import.walks_note') }}</p>
                        @endif
                        <div class="a-table-wrap a-table-scroll max-h-[28rem]">
                            <table class="a-table a-table-compact">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('import.item') }}</th>
                                        <th scope="col">{{ __('import.current') }}</th>
                                        <th scope="col">{{ __('import.new') }}</th>
                                        <th scope="col" class="is-shrink"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (array_slice($rows, 0, 300) as $row)
                                        <tr @class(['is-muted' => in_array($row['status'], ['same', 'skip'], true)])>
                                            <th scope="row" class="max-w-xs break-words font-normal">{{ $row['label'] }}</th>
                                            <td class="max-w-xs break-words text-ink-muted">{{ $row['current'] }}</td>
                                            <td class="max-w-md break-words">{{ $row['new'] }}</td>
                                            <td class="is-nowrap">
                                                <span @class(['a-badge', 'a-badge-success' => $row['status'] === 'new', 'a-badge-warning' => $row['status'] === 'conflict',
                                                    'a-badge-neutral' => $row['status'] === 'same', 'a-badge-outline' => $row['status'] === 'skip'])>{{ __('import.status.'.$row['status']) }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            @endforeach
        </form>

        <div class="a-savebar flex flex-wrap items-center gap-2">
            <button type="submit" form="import-apply" class="a-btn a-btn-primary"><x-lucide-check aria-hidden="true" />{{ __('import.apply') }}</button>
            <form method="POST" action="{{ route('versions.import.destroy', [$version, $token]) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="a-btn a-btn-ghost">{{ __('import.cancel') }}</button>
            </form>
            <span class="a-spacer"></span>
            <span class="hidden items-center gap-1.5 text-xs text-ink-muted md:flex"><x-ai.badge :origin="\App\Enums\Origin::Imported" />{{ __('ai.origin.imported.tooltip') }}</span>
        </div>
    </div>
</x-layouts.app>
