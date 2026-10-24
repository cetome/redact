<x-layouts.app :title="__('sbom.vex.title')">
    <x-slot:subnav><x-version-nav :version="$version" active="inventory" /></x-slot:subnav>
    @php
        $text = fn ($v) => \App\Content\Text::of($v);
        $fileNames = collect($import->files)->pluck('name')->implode(', ');
        $stateBadge = ['new' => 'a-badge-success', 'changed' => 'a-badge', 'same' => 'a-badge-neutral'];
        $listUrl = route('versions.inventory', [$version, $vulnerabilityList['key']]);
    @endphp

    <x-ui.page-header :title="__('sbom.vex.title')" :subtitle="__('sbom.vex.intro', ['count' => $import->statement_count, 'files' => $fileNames])">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /> · {{ __('sbom.format.'.$import->format) }}</x-slot:eyebrow>
        <x-slot:actions>
            <a href="{{ route('versions.sbom', $version) }}" class="a-btn a-btn-ghost"><x-lucide-arrow-left aria-hidden="true" />{{ __('sbom.import.title') }}</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($import->status === 'imported')
        <div class="a-alert a-alert-success mb-3" role="status">
            <x-lucide-circle-check aria-hidden="true" />
            <span>{{ __('sbom.vex.imported', [
                'date' => $import->imported_at?->isoFormat('D MMMM YYYY'), 'name' => $import->user?->name ?? '—',
                'recorded' => $import->summary['recorded'] ?? 0, 'created' => $import->summary['created'] ?? 0, 'skipped' => $import->summary['skipped'] ?? 0,
            ]) }}</span>
        </div>
        <p class="flex flex-wrap gap-2">
            <a href="{{ $listUrl }}" class="a-btn a-btn-outline"><x-lucide-list aria-hidden="true" />{{ $text($vulnerabilityList['label']) }}</a>
            <a href="{{ route('versions.vulnerabilities', $version) }}" class="a-btn a-btn-outline"><x-lucide-shield-alert aria-hidden="true" />{{ __('sbom.vulnerabilities_button') }}</a>
        </p>
    @else
        <div class="a-toolbar" data-filter-scope>
            <x-ui.search target="#vex-rows" :placeholder="__('sbom.vex.search')" />
            <div class="a-seg" role="group" aria-label="{{ __('sbom.vex.analysis') }}" data-facet-target="#vex-rows">
                <button type="button" class="a-seg-btn" data-facet="" aria-pressed="true">{{ __('sbom.preview.all') }} <span class="a-seg-count">{{ count($rows) }}</span></button>
                @foreach (['new', 'changed', 'same'] as $state)
                    @if ($counts[$state] ?? 0)
                        <button type="button" class="a-seg-btn" data-facet="{{ $state }}" aria-pressed="false" title="{{ __('sbom.vex.state_help.'.$state) }}">{{ __('sbom.vex.state.'.$state) }} <span class="a-seg-count">{{ $counts[$state] }}</span></button>
                    @endif
                @endforeach
            </div>
            <span class="a-toolbar-count" data-filter-count data-template="{{ __('ui.shown') }}"></span>
        </div>

        <form method="POST" action="{{ route('versions.vex.apply', [$version, $import]) }}" id="vex-apply">@csrf</form>
        @if ($editable)
            <form method="POST" action="{{ route('versions.vex.destroy', [$version, $import]) }}" id="vex-discard"
                  x-data="confirmSubmit" data-confirm="{{ __('sbom.import.discard_confirm') }}" @submit="check">
                @csrf
                @method('DELETE')
            </form>
        @endif
        @error('rows')<p class="a-error mb-2">{{ $message }}</p>@enderror

        <div class="a-table-wrap a-table-scroll">
            <table class="a-table a-table-compact">
                <thead>
                    <tr>
                        <th scope="col" class="is-shrink a-select-cell">
                            @if ($editable)
                                <button type="button" class="a-select-all" data-check-visible="#vex-rows" title="{{ __('sbom.vex.tick_shown') }}" aria-label="{{ __('sbom.vex.tick_shown') }}"><x-lucide-list-checks aria-hidden="true" /></button>
                            @endif
                        </th>
                        <th scope="col">{{ __('sbom.vex.vulnerability') }}</th>
                        <th scope="col">{{ __('sbom.vex.component') }}</th>
                        <th scope="col">{{ __('sbom.vex.analysis') }}</th>
                        <th scope="col">{{ __('sbom.vex.detail') }}</th>
                    </tr>
                </thead>
                <tbody id="vex-rows">
                    @foreach ($rows as $row)
                        @php
                            $s = $row['statement'];
                            $c = $row['component'];
                            $product = $s['product'] ?? [];
                            $more = trim(implode(' ', array_filter([$s['detail'] ?? null, $s['action'] ?? null])));
                        @endphp
                        <tr data-filter-item data-facet-value="{{ $row['status'] }}" data-search="{{ $s['id'] }} {{ implode(' ', $s['aliases'] ?? []) }} {{ $c?->name }} {{ $product['name'] ?? '' }} {{ $product['purl'] ?? '' }}"
                            @class(['is-muted' => $row['status'] === 'same'])>
                            <td class="is-shrink a-select-cell">
                                <input type="checkbox" name="rows[]" value="{{ $row['key'] }}" form="vex-apply" class="a-select-box" @checked($row['status'] !== 'same') @disabled(! $editable || $row['status'] === 'same')
                                       aria-label="{{ __('sbom.preview.include') }} {{ $s['id'] }}">
                            </td>
                            <td class="min-w-48">
                                <span class="a-code font-semibold">{{ $s['id'] }}</span>
                                <span class="a-sbom-tags">
                                    <span class="a-badge {{ $stateBadge[$row['status']] }}" title="{{ __('sbom.vex.state_help.'.$row['status']) }}">{{ __('sbom.vex.state.'.$row['status']) }}</span>
                                    @if ($row['vulnerability'])
                                        <span class="a-code a-muted">{{ __('sbom.preview.existing', ['code' => $row['vulnerability']->code]) }}</span>
                                    @else
                                        <span class="a-muted">{{ __('sbom.vex.creates', ['list' => $text($vulnerabilityList['label'])]) }}</span>
                                    @endif
                                </span>
                                @if ($s['aliases'] ?? [])
                                    <span class="a-row-sub a-code">{{ implode(', ', array_slice($s['aliases'], 0, 4)) }}</span>
                                @endif
                            </td>
                            <td class="min-w-40">
                                @if ($c)
                                    <span class="a-code">{{ $c->code }}</span> {{ $c->name }}
                                    <span class="a-row-sub">{{ __('sbom.vex.match.'.$row['match']) }}</span>
                                @else
                                    <span class="a-muted">{{ __('sbom.vex.product') }}</span>
                                    @if (($product['name'] ?? null) || ($product['purl'] ?? null))
                                        <span class="a-row-sub a-code" title="{{ $product['purl'] ?? '' }}">{{ $product['purl'] ?? trim(($product['name'] ?? '').' '.($product['version'] ?? '')) }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="is-nowrap">
                                <span class="a-badge {{ \App\Sbom\Vex\VexStatus::TONES[$s['status']] }}">{{ __('sbom.vex.status.'.$s['status']) }}</span>
                                @if ($label = \App\Sbom\Vex\Statement::justificationLabel($s['justification'] ?? null))
                                    <span class="a-row-sub">{{ $label }}</span>
                                @endif
                                @if ($row['status'] === 'changed' && $row['previous'] && $row['previous']->status !== $s['status'])
                                    <span class="a-row-sub">{{ __('sbom.vex.was', ['status' => __('sbom.vex.status.'.$row['previous']->status)]) }}</span>
                                @endif
                            </td>
                            <td class="a-muted max-w-96">
                                @if ($more !== '')
                                    <span class="line-clamp-2" title="{{ \Illuminate\Support\Str::limit($more, 800) }}">{{ $more }}</span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="a-help px-4 py-3" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</p>
        </div>

        @if ($editable)
            <div class="a-savebar a-sbom-savebar">
                <span class="a-selectbar-count"><b data-checked-count="#vex-rows">0</b> {{ __('sbom.vex.ticked') }}</span>
                <span class="a-spacer"></span>
                <button type="submit" form="vex-discard" class="a-btn a-btn-ghost"><x-lucide-trash-2 aria-hidden="true" />{{ __('sbom.import.discard') }}</button>
                <button type="submit" form="vex-apply" class="a-btn a-btn-primary" data-needs-checked="#vex-rows"><x-lucide-shield-check aria-hidden="true" />{{ __('sbom.vex.record') }}</button>
            </div>
        @endif
    @endif
</x-layouts.app>
