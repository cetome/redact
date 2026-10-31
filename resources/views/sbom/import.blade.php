<x-layouts.app :title="__('sbom.import.title')">
    <x-slot:subnav><x-version-nav :version="$version" active="inventory" /></x-slot:subnav>
    @php
        $listUrl = route('versions.inventory', [$version, $list['key']]);
    @endphp

    <x-ui.page-header :title="__('sbom.import.title')" :subtitle="__('sbom.import.intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
        <x-slot:actions>
            <a href="{{ $listUrl }}" class="a-btn a-btn-ghost"><x-lucide-arrow-left aria-hidden="true" />{{ __('sbom.import.back') }}</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="a-grid-main">
        <div class="a-stack">
            <section class="a-panel">
                <form method="POST" action="{{ route('versions.sbom.store', $version) }}" enctype="multipart/form-data" class="a-panel-body a-stack gap-3!">
                    @csrf
                    <div class="a-field">
                        <label class="a-label" for="sbom-files">{{ __('sbom.import.choose') }}</label>
                        <label class="a-sbom-drop" for="sbom-files">
                            <x-lucide-file-up aria-hidden="true" />
                            <span class="a-sbom-drop-text">{{ __('sbom.import.choose_help') }}</span>
                            <input id="sbom-files" type="file" name="files[]" multiple required accept="{{ $accept }}"
                                   @error('files') aria-invalid="true" @enderror @error('files.*') aria-invalid="true" @enderror
                                   class="a-sbom-file">
                        </label>
                        <p class="a-help">{{ __('sbom.import.limits', ['mb' => $maxMb, 'files' => $maxFiles, 'max' => \Illuminate\Support\Number::format($maxComponents)]) }}</p>
                        @error('files')<p class="a-error">{{ $message }}</p>@enderror
                        @foreach ($errors->get('files.*') as $messages)
                            <p class="a-error">{{ $messages[0] }}</p>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" class="a-btn a-btn-primary"><x-lucide-scan-search aria-hidden="true" />{{ __('sbom.import.upload') }}</button>
                        <a href="{{ route('sbom.template') }}" class="a-btn a-btn-ghost" title="{{ __('sbom.import.template_help') }}"><x-lucide-file-spreadsheet aria-hidden="true" />{{ __('sbom.import.template') }}</a>
                    </div>
                    <p class="a-help flex items-start gap-1.5"><x-lucide-lock class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ __('sbom.import.private') }}</p>
                </form>
            </section>

            <section class="a-section" aria-labelledby="sbom-recent">
                <div class="a-section-head"><h2 class="a-h2" id="sbom-recent">{{ __('sbom.import.recent') }}</h2></div>
                @if ($imports->isEmpty())
                    <p class="a-help">{{ __('sbom.import.none') }}</p>
                @else
                    <div class="a-table-wrap">
                        <table class="a-table a-table-compact">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('sbom.import.when') }}</th>
                                    <th scope="col">{{ __('sbom.import.format') }}</th>
                                    <th scope="col" class="is-num">{{ __('sbom.import.components') }}</th>
                                    <th scope="col">{{ __('sbom.import.files') }}</th>
                                    <th scope="col"><span class="a-sr-only">{{ __('common.actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($imports as $import)
                                    <tr>
                                        <td class="is-nowrap">{{ $import->created_at->isoFormat('D MMM YYYY, HH:mm') }}
                                            <span class="a-row-sub">{{ __('sbom.import.by') }} {{ $import->user?->name ?? '—' }}</span></td>
                                        <td class="is-nowrap">{{ __('sbom.format.'.$import->format) }}
                                            <span class="a-row-sub">{{ __('sbom.kind.'.$import->kind) }}</span></td>
                                        <td class="is-num">{{ $import->component_count }}</td>
                                        <td class="min-w-0">
                                            @foreach ($import->files as $n => $file)
                                                <a href="{{ route('versions.sbom.download', [$version, $import, $n]) }}" class="a-sbom-file-link">{{ $file['name'] }}</a>
                                            @endforeach
                                        </td>
                                        <td class="is-nowrap is-shrink">
                                            @if ($import->status === 'preview')
                                                <a href="{{ route('versions.sbom.show', [$version, $import]) }}" class="a-btn a-btn-outline a-btn-sm">{{ __('sbom.import.open_preview') }}</a>
                                            @else
                                                <a href="{{ route('versions.sbom.show', [$version, $import]) }}" class="a-badge a-badge-success no-underline">{{ __('sbom.import.state.imported') }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            @if ($vexImports->isNotEmpty())
                <section class="a-section" aria-labelledby="vex-recent">
                    <div class="a-section-head"><h2 class="a-h2" id="vex-recent">{{ __('sbom.vex.recent') }}</h2></div>
                    <div class="a-table-wrap">
                        <table class="a-table a-table-compact">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('sbom.import.when') }}</th>
                                    <th scope="col">{{ __('sbom.import.format') }}</th>
                                    <th scope="col" class="is-num">{{ __('sbom.vex.statements') }}</th>
                                    <th scope="col">{{ __('sbom.import.files') }}</th>
                                    <th scope="col"><span class="a-sr-only">{{ __('common.actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($vexImports as $vex)
                                    <tr>
                                        <td class="is-nowrap">{{ $vex->created_at->isoFormat('D MMM YYYY, HH:mm') }}
                                            <span class="a-row-sub">{{ __('sbom.import.by') }} {{ $vex->user?->name ?? '—' }}</span></td>
                                        <td class="is-nowrap">{{ __('sbom.format.'.$vex->format) }}</td>
                                        <td class="is-num">{{ $vex->statement_count }}</td>
                                        <td class="min-w-0">
                                            @foreach ($vex->files as $n => $file)
                                                <a href="{{ $vex->sbom_import_id ? route('versions.sbom.download', [$version, $vex->sbom_import_id, $n]) : route('versions.vex.download', [$version, $vex, $n]) }}" class="a-sbom-file-link">{{ $file['name'] }}</a>
                                            @endforeach
                                        </td>
                                        <td class="is-nowrap is-shrink">
                                            @if ($vex->status === 'preview')
                                                <a href="{{ route('versions.vex.show', [$version, $vex]) }}" class="a-btn a-btn-outline a-btn-sm">{{ __('sbom.import.open_preview') }}</a>
                                            @else
                                                <a href="{{ route('versions.vex.show', [$version, $vex]) }}" class="a-badge a-badge-success no-underline">{{ __('sbom.import.state.imported') }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        <aside class="a-stack">
            <section class="a-panel">
                <div class="a-panel-head"><h2 class="a-h3">{{ __('sbom.import.formats_title') }}</h2></div>
                <ul class="a-panel-body a-sbom-formats">
                    @foreach (['cyclonedx' => 'boxes', 'spdx' => 'file-text', 'swid' => 'tag', 'spreadsheet' => 'sheet', 'vex_cyclonedx' => 'shield-check', 'openvex' => 'shield-check', 'csaf' => 'shield-check'] as $key => $icon)
                        <li><x-dynamic-component :component="'lucide-'.$icon" aria-hidden="true" /><span>{{ __('sbom.import.formats.'.$key) }}</span></li>
                    @endforeach
                </ul>
            </section>
            <section class="a-panel">
                <div class="a-panel-head"><h2 class="a-h3">{{ __('sbom.import.next_title') }}</h2></div>
                <ol class="a-panel-body a-sbom-steps">
                    @foreach (['preview', 'tick', 'check'] as $step)
                        <li>{{ __('sbom.import.next.'.$step) }}</li>
                    @endforeach
                </ol>
            </section>
        </aside>
    </div>
</x-layouts.app>
