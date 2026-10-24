<x-layouts.app :title="__('reports.title').' · '.$version->displayName()">
    <x-slot:subnav><x-version-nav :version="$version" active="reports" /></x-slot:subnav>
    <x-read-only-notice :access="$access" />

    <x-ui.page-header :title="__('reports.title')" :subtitle="__('reports.intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
    </x-ui.page-header>

    <div class="a-table-wrap">
        <table class="a-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('reports.report') }}</th>
                    <th scope="col" class="is-shrink">{{ __('common.download') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($kinds as $kind)
                    <tr>
                        <th scope="row" class="py-3!">
                            <span class="flex items-start gap-3">
                                <span class="a-empty-icon size-9! shrink-0"><x-lucide-file-text class="size-4.5!" aria-hidden="true" /></span>
                                <span class="min-w-0">
                                    {{ $kind->label() }}
                                    <span class="a-row-sub max-w-2xl">{{ $kind->description() }}</span>
                                </span>
                            </span>
                        </th>
                        <td class="is-nowrap">
                            <span class="flex justify-end gap-1.5">
                                @if ($isGuest)
                                    @include('reports._locked', ['label' => __('workbooks.locked'), 'tooltip' => __('workbooks.locked_tooltip')])
                                @else
                                    @foreach ($kind->formats() as $format)
                                        <a href="{{ route('reports.download', ['version' => $version, 'kind' => $kind->value, 'format' => $format]) }}"
                                           class="a-btn {{ $loop->first ? 'a-btn-primary' : 'a-btn-outline' }} a-btn-sm" download>
                                            <x-lucide-download aria-hidden="true" />{{ __('reports.formats.'.$format) }}</a>
                                    @endforeach
                                @endif
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="a-help mt-3 flex max-w-3xl items-start gap-1.5"><x-lucide-user-check class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ __('reports.human_only') }}</p>
    {{-- The declaration and the technical file have their own step (declaration/show). --}}
    <p class="a-help mt-1.5 flex max-w-3xl items-start gap-1.5"><x-lucide-stamp class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" /><span>{{ __('declaration.reports_hint') }} <a href="{{ route('versions.declaration', $version) }}" class="a-link-btn">{{ __('workflow.steps.declaration') }}</a></span></p>

    @if ($workbooks !== [])
        <section class="a-section mt-8" aria-labelledby="workbooks-title">
            <div class="a-section-head flex flex-wrap items-end justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="a-h2" id="workbooks-title">{{ __('workbooks.title') }}</h2>
                    <p class="a-muted mt-1 max-w-3xl text-sm">{{ __('workbooks.intro') }}</p>
                </div>
                @if ($isGuest)
                    @include('reports._locked', ['label' => __('workbooks.locked'), 'tooltip' => __('workbooks.locked_tooltip')])
                @else
                    <a href="{{ route('versions.workbooks.zip', $version) }}" class="a-btn a-btn-outline a-btn-sm" download
                       title="{{ __('workbooks.download_all_tooltip') }}"><x-lucide-file-archive aria-hidden="true" />{{ __('workbooks.download_all') }}</a>
                @endif
            </div>

            <div class="a-table-wrap">
                <table class="a-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('workbooks.workbook') }}</th>
                            <th scope="col" class="hidden md:table-cell">{{ __('workbooks.fixes_title') }}</th>
                            <th scope="col" class="is-shrink">{{ __('common.download') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($workbooks as $workbook)
                            <tr>
                                <th scope="row" class="py-3! align-top">
                                    <span class="flex items-start gap-3">
                                        <span class="a-empty-icon size-9! shrink-0"><x-lucide-file-spreadsheet class="size-4.5!" aria-hidden="true" /></span>
                                        <span class="min-w-0">
                                            cetome REDact · {{ $workbook['label'] }}
                                            <span class="a-row-sub max-w-xl">{{ $workbook['description'] }}</span>
                                            @if ($workbook['fixes'] !== [])
                                                <span class="a-row-sub md:hidden">{{ trans_choice('workbooks.fixes_made', count($workbook['fixes']), ['count' => count($workbook['fixes'])]) }}</span>
                                            @endif
                                        </span>
                                    </span>
                                </th>
                                <td class="hidden align-top text-sm md:table-cell">
                                    @if ($workbook['fixes'] === [])
                                        <span class="a-muted">{{ __('workbooks.fixes_none') }}</span>
                                    @else
                                        <details class="max-w-xl">
                                            <summary class="a-link-btn cursor-pointer">{{ trans_choice('workbooks.fixes_count', count($workbook['fixes']), ['count' => count($workbook['fixes'])]) }}</summary>
                                            <ul class="a-stack gap-1! mt-2 list-none">
                                                @foreach ($workbook['fixes'] as $fix)
                                                    <li class="flex items-start gap-1.5"><x-lucide-wrench class="mt-1 size-3 shrink-0 text-ink-subtle" aria-hidden="true" /><span>{{ $fix }}</span></li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </td>
                                <td class="is-nowrap align-top">
                                    <span class="flex justify-end">
                                        @if ($isGuest)
                                            @include('reports._locked', ['label' => __('workbooks.locked'), 'tooltip' => __('workbooks.locked_tooltip')])
                                        @else
                                            <a href="{{ route('versions.workbooks.download', ['version' => $version, 'workbook' => $workbook['key']]) }}"
                                               class="a-btn a-btn-primary a-btn-sm" download title="{{ __('workbooks.download_tooltip') }}">
                                                <x-lucide-download aria-hidden="true" />{{ __('reports.formats.xlsx') }}</a>
                                        @endif
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                <p class="a-help flex items-start gap-1.5"><x-lucide-calculator class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ __('workbooks.recalculate') }}</p>
                @if ($left !== [])
                    <details class="a-collapse">
                        <summary class="a-help cursor-pointer">{{ __('workbooks.left_title') }}</summary>
                        <ul class="a-stack gap-1! mt-2 list-disc pl-5 text-sm">
                            @foreach ($left as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        </section>
    @endif
</x-layouts.app>
