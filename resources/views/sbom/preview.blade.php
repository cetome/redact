<x-layouts.app :title="__('sbom.preview.title')">
    <x-slot:subnav><x-version-nav :version="$version" active="inventory" /></x-slot:subnav>
    @php
        $text = fn ($v) => \App\Content\Text::of($v);
        $typeField = collect($list['fields'] ?? [])->firstWhere('key', 'type');
        $typeLabel = fn (string $value) => $typeField ? $text(collect($typeField['options'] ?? [])->firstWhere('value', $value)['label'] ?? $value) : $value;
        $fileNames = collect($import->files)->pluck('name')->implode(', ');
        $statusBadge = ['new' => 'a-badge-success', 'updated' => 'a-badge', 'present' => 'a-badge-neutral'];
    @endphp

    <x-ui.page-header :title="__('sbom.preview.title')" :subtitle="__('sbom.preview.intro', ['count' => $import->component_count, 'files' => $fileNames])">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /> · {{ __('sbom.format.'.$import->format) }} · {{ __('sbom.kind.'.$import->kind) }}</x-slot:eyebrow>
        <x-slot:actions>
            <a href="{{ route('versions.sbom', $version) }}" class="a-btn a-btn-ghost"><x-lucide-arrow-left aria-hidden="true" />{{ __('sbom.import.title') }}</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($import->status === 'imported')
        <div class="a-alert a-alert-success mb-3" role="status">
            <x-lucide-circle-check aria-hidden="true" />
            <span>{{ __('sbom.preview.imported', [
                'date' => $import->imported_at?->isoFormat('D MMMM YYYY'), 'name' => $import->user?->name ?? '—',
                'created' => $import->summary['created'] ?? 0, 'updated' => $import->summary['updated'] ?? 0, 'skipped' => $import->summary['skipped'] ?? 0,
            ]) }}</span>
        </div>
        <p class="flex flex-wrap gap-2">
            <a href="{{ route('versions.inventory', [$version, $list['key']]) }}" class="a-btn a-btn-outline"><x-lucide-list aria-hidden="true" />{{ __('sbom.import.back') }}</a>
            @if (\App\Sbom\SbomFeature::checkable())
                <a href="{{ route('versions.vulnerabilities', $version) }}" class="a-btn a-btn-outline"><x-lucide-shield-alert aria-hidden="true" />{{ __('sbom.vulnerabilities_button') }}</a>
            @endif
        </p>
    @else
        @if ($vexImport)
            <div class="a-alert mb-3" role="note">
                <x-lucide-shield-check aria-hidden="true" />
                <span>{{ __('sbom.vex.embedded', ['count' => $vexImport->statement_count]) }}
                    <a href="{{ route('versions.vex.show', [$version, $vexImport]) }}">{{ __('sbom.vex.review') }}</a></span>
            </div>
        @endif
        @if ($import->warnings)
            <div class="a-alert a-alert-warning mb-3" role="note">
                <x-lucide-triangle-alert aria-hidden="true" />
                <div><p class="font-bold">{{ __('sbom.preview.warnings') }}</p>
                    <ul>@foreach ($import->warnings as $warning)<li>{{ $warning }}</li>@endforeach</ul>
                </div>
            </div>
        @endif

        <div class="a-toolbar" data-filter-scope>
            <x-ui.search target="#sbom-rows" :placeholder="__('sbom.preview.search')" />
            <div class="a-seg" role="group" aria-label="{{ __('sbom.preview.include') }}" data-facet-target="#sbom-rows">
                <button type="button" class="a-seg-btn" data-facet="" aria-pressed="true">{{ __('sbom.preview.all') }} <span class="a-seg-count">{{ count($rows) }}</span></button>
                @foreach (['new', 'updated', 'present'] as $status)
                    @if ($counts[$status] ?? 0)
                        <button type="button" class="a-seg-btn" data-facet="{{ $status }}" aria-pressed="false" title="{{ __('sbom.preview.status_help.'.$status) }}">{{ __('sbom.preview.status.'.$status) }} <span class="a-seg-count">{{ $counts[$status] }}</span></button>
                    @endif
                @endforeach
            </div>
            <span class="a-toolbar-count" data-filter-count data-template="{{ __('ui.shown') }}"></span>
        </div>

        <form method="POST" action="{{ route('versions.sbom.apply', [$version, $import]) }}" id="sbom-apply">@csrf</form>
        @if ($editable)
            <form method="POST" action="{{ route('versions.sbom.destroy', [$version, $import]) }}" id="sbom-discard"
                  x-data="confirmSubmit" data-confirm="{{ __('sbom.import.discard_confirm') }}" @submit="check">
                @csrf
                @method('DELETE')
            </form>
        @endif

        <div class="a-table-wrap a-table-scroll">
            <table class="a-table a-table-compact a-sbom-table">
                <thead>
                    <tr>
                        <th scope="col" class="is-shrink a-select-cell">
                            @if ($editable)
                                <button type="button" class="a-select-all" data-check-visible="#sbom-rows" title="{{ __('sbom.preview.tick_shown') }}" aria-label="{{ __('sbom.preview.tick_shown') }}"><x-lucide-list-checks aria-hidden="true" /></button>
                            @endif
                        </th>
                        <th scope="col">{{ __('sbom.preview.component') }}</th>
                        <th scope="col">{{ __('sbom.preview.version') }}</th>
                        <th scope="col">{{ __('sbom.preview.supplier') }}</th>
                        <th scope="col">{{ __('sbom.preview.type') }}</th>
                        <th scope="col">{{ __('sbom.preview.identifiers') }}</th>
                        <th scope="col">{{ __('sbom.preview.licence') }}</th>
                    </tr>
                </thead>
                <tbody id="sbom-rows">
                    @foreach ($rows as $row)
                        @php
                            $c = $row['component'];
                            $i = $row['index'];
                            $ticked = $row['status'] !== 'present' && ! $c->root;
                            $currentType = $row['item']?->attr('type') ?: $c->type;
                        @endphp
                        <tr data-filter-item data-facet-value="{{ $row['status'] }}" data-search="{{ $c->name }} {{ $c->version }} {{ $c->supplier }} {{ $c->purl }} {{ $c->cpe }}"
                            @class(['is-muted' => $row['status'] === 'present'])>
                            <td class="is-shrink a-select-cell">
                                <input type="checkbox" name="rows[]" value="{{ $i }}" form="sbom-apply" class="a-select-box" @checked($ticked) @disabled(! $editable || $row['status'] === 'present')
                                       aria-label="{{ __('sbom.preview.include') }} {{ $c->name }}">
                            </td>
                            <td class="min-w-56">
                                <span class="font-semibold">{{ $c->name }}</span>
                                <span class="a-sbom-tags">
                                    <span class="a-badge {{ $statusBadge[$row['status']] }}" title="{{ __('sbom.preview.status_help.'.$row['status']) }}">{{ __('sbom.preview.status.'.$row['status']) }}</span>
                                    @if ($row['item'])
                                        <span class="a-code a-muted">{{ __('sbom.preview.existing', ['code' => $row['item']->code]) }}</span>
                                    @endif
                                    @if ($c->root)
                                        <span class="a-badge a-badge-outline" title="{{ __('sbom.preview.root_help') }}">{{ __('sbom.preview.root') }}</span>
                                    @endif
                                </span>
                                @foreach ($row['changes'] as $key => [$old, $new])
                                    <span class="a-row-sub">{{ $key === 'version' ? __('sbom.preview.change.version', ['old' => $old ?? '—', 'new' => $new]) : __('sbom.preview.change.'.$key) }}</span>
                                @endforeach
                                @if ($c->description && ! $row['changes'])
                                    <span class="a-row-sub line-clamp-1" title="{{ $c->description }}">{{ $c->description }}</span>
                                @endif
                            </td>
                            <td class="is-nowrap a-code">{{ $c->version ?? '—' }}</td>
                            <td class="a-muted max-w-48 truncate" title="{{ $c->supplier }}">{{ $c->supplier ?? '—' }}</td>
                            <td class="is-nowrap">
                                @if ($editable && $row['status'] !== 'present')
                                    <label class="a-sr-only" for="sbom-type-{{ $i }}">{{ __('sbom.preview.type') }} {{ $c->name }}</label>
                                    <select id="sbom-type-{{ $i }}" name="types[{{ $i }}]" form="sbom-apply" class="a-select a-input-sm">
                                        @foreach (['software', 'hardware'] as $type)
                                            <option value="{{ $type }}" @selected($currentType === $type)>{{ $typeLabel($type) }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="a-badge a-badge-neutral">{{ $typeLabel($currentType) }}</span>
                                @endif
                            </td>
                            <td class="a-sbom-ids">
                                @if ($c->purl)<span class="a-chip a-chip-sm a-chip-code" title="{{ $c->purl }}">{{ $c->purl }}</span>@endif
                                @if ($c->cpe)<span class="a-chip a-chip-sm a-chip-code" title="{{ $c->cpe }}">{{ $c->cpe }}</span>@endif
                                @if (! $c->purl && ! $c->cpe)<span class="a-muted">—</span>@endif
                            </td>
                            <td class="a-muted max-w-40 truncate" title="{{ $c->licence }}">{{ $c->licence ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="a-help px-4 py-3" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</p>
        </div>

        @if ($editable)
            <div class="a-savebar a-sbom-savebar">
                <span class="a-selectbar-count"><b data-checked-count="#sbom-rows">0</b> {{ __('sbom.preview.ticked') }}</span>
                @if ($checkRefusal === null)
                    <label class="a-check" title="{{ __('sbom.preview.check_after_help') }}">
                        <input type="checkbox" name="check" value="1" form="sbom-apply" checked>
                        <span>{{ __('sbom.preview.check_after') }}</span>
                    </label>
                @else
                    <span class="a-help flex items-center gap-1"><x-lucide-info class="size-3.5 shrink-0" aria-hidden="true" />{{ $checkRefusal }}</span>
                @endif
                <span class="a-spacer"></span>
                <button type="submit" form="sbom-discard" class="a-btn a-btn-ghost"><x-lucide-trash-2 aria-hidden="true" />{{ __('sbom.import.discard') }}</button>
                <button type="submit" form="sbom-apply" class="a-btn a-btn-primary" data-needs-checked="#sbom-rows"><x-lucide-download aria-hidden="true" />{{ __('sbom.preview.import') }}</button>
            </div>
        @endif
    @endif
</x-layouts.app>
