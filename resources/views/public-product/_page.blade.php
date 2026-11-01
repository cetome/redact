{{--
    The public product page body (App\PublicPage\PublicPageData): rendered at /verify/{code} and in
    the app's preview. Only the sections the manufacturer switched on are read.
    $page PublicPageData, $product, $published (published versions, newest first; empty in a
    preview of a version not published yet), $latest (this is the newest one), $preview (no live
    links: files and versions are not public yet).
--}}
@php
    $version = $page->version;
    $settings = $page->settings;
    $code = $product->public_code;
    $live = ! $preview && $code;
    $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->locale(app()->getLocale())->isoFormat('D MMMM YYYY') : null;
    $levels = $page->shows('risks') ? $page->riskLevels() : collect();
    $counts = $page->shows('risks') ? $page->riskCounts() : [];
    $listed = $page->shows('risks') ? $page->listedRisks() : collect();
    $maker = $page->manufacturer();
    $allMet = $page->shows('assessment') ? $page->allMet() : null;
    $contactHref = $settings->contact ? \App\PublicPage\PublicSettings::contactHref($settings->contact) : null;
@endphp

@if ($levels->isNotEmpty())
    {{-- Level colours are the version's own (risk matrix settings): one nonce-tagged style, no style attributes (CSP). --}}
    <style nonce="{{ app('csp-nonce') }}">
        @foreach ($levels as $rank => $level)
            @if (preg_match('/^#[0-9A-Fa-f]{6}$/', $level->color))
                @php
                    [$r, $g, $b] = array_map(fn ($h) => (($c = hexdec($h) / 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4), str_split(substr($level->color, 1), 2));
                    $ink = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) > 0.18 ? '#0f172a' : '#fff';
                @endphp
                .pp-level-{{ (int) $rank }} { background-color: {{ $level->color }}; color: {{ $ink }}; }
                .pp-fill-{{ (int) $rank }} { background-color: {{ $level->color }}; }
            @endif
        @endforeach
        @foreach ($counts as $rank => $n)
            .pp-bar .pp-fill-{{ (int) $rank }} { flex: {{ (int) $n }}; }
        @endforeach
    </style>
@endif

<div class="pp-page">
    @if (! $latest && $live)
        <div class="a-alert a-alert-neutral pp-older">
            <x-lucide-history aria-hidden="true" />
            <span>{{ __('public_page.page.older') }}</span>
            <a href="{{ route('verify.show', $code) }}" class="a-btn a-btn-outline a-btn-sm">{{ __('public_page.page.see_latest') }}</a>
        </div>
    @endif

    <section class="pp-hero" aria-labelledby="pp-title">
        @if ($product->image_path)
            @if ($live)
                <img class="pp-picture" src="{{ route('verify.picture', $code) }}" alt="{{ $product->name }}" width="256" height="256">
            @else
                <img class="pp-picture" src="{{ route('products.image', $product) }}" alt="{{ $product->name }}" width="256" height="256">
            @endif
        @endif
        <div class="pp-hero-body">
            <p class="pp-kicker">{{ __('public_page.page.kicker') }}</p>
            <h1 class="pp-title" id="pp-title">{{ $product->name }}</h1>
            <p class="pp-ids">
                @foreach ($page->models() as $model)<span>{{ __('public_page.page.model') }} <b>{{ $model }}</b></span>@endforeach
                @if ($version->hardware_version)<span>{{ __('public_page.page.hardware') }} <b>{{ $version->hardware_version }}</b></span>@endif
                @if ($version->software_version)<span>{{ __('public_page.page.firmware') }} <b>{{ $version->software_version }}</b></span>@endif
            </p>

            @if ($live && $published->count() > 1)
                <nav class="pp-versions" aria-label="{{ __('public_page.page.version') }}">
                    <span class="a-micro">{{ __('public_page.page.version') }}</span>
                    <span class="a-seg">
                        @foreach ($published as $v)
                            <a href="{{ $loop->first ? route('verify.show', $code) : route('verify.version', [$code, $v->id]) }}" class="a-seg-btn"
                               @if ($v->is($version)) aria-current="page" @endif>{{ $v->label }}@if ($loop->first) · {{ __('public_page.page.latest') }}@endif</a>
                        @endforeach
                    </span>
                </nav>
            @else
                <p class="pp-muted">{{ __('public_page.page.version') }} <b>{{ $version->label }}</b></p>
            @endif

            @if ($page->shows('assessment') || $page->shows('support'))
                <dl class="pp-facts">
                    @if ($page->shows('assessment'))
                        @if ($page->assessedOn())
                            <div class="pp-fact"><dt class="a-micro">{{ __('public_page.page.assessed') }}</dt><dd class="pp-fact-value">{{ $date($page->assessedOn()) }}</dd></div>
                        @endif
                        @if ($allMet !== null)
                            <div class="pp-fact"><dt class="a-micro">{{ __('public_page.page.result') }}</dt><dd>
                                @if ($allMet)
                                    <span class="a-badge a-badge-success"><x-lucide-check aria-hidden="true" />{{ __('public_page.page.all_met') }}</span>
                                @else
                                    <span class="a-badge a-badge-danger"><x-lucide-x aria-hidden="true" />{{ __('public_page.page.not_all_met') }}</span>
                                @endif
                            </dd></div>
                        @endif
                        @if ($version->conformity_route)
                            <div class="pp-fact"><dt class="a-micro">{{ __('public_page.page.route') }}</dt><dd class="pp-fact-value">{{ $version->conformity_route->label() }}</dd></div>
                        @endif
                    @endif
                    @if ($page->shows('support') && $settings->supportUntil)
                        <div class="pp-fact"><dt class="a-micro">{{ __('public_page.page.support_until') }}</dt><dd class="pp-fact-value">{{ $date($settings->supportUntil) }}</dd></div>
                    @endif
                </dl>
            @endif
        </div>
    </section>

    <div class="pp-columns">
        <div class="pp-main">
            @if ($page->shows('assessment'))
                <section class="pp-section" aria-labelledby="pp-assessment">
                    <h2 class="pp-h2" id="pp-assessment">{{ __('public_page.sections.assessment') }}</h2>
                    <div class="a-table-wrap">
                        <table class="a-table">
                            <thead><tr>
                                <th scope="col">{{ __('public_page.page.standard') }}</th>
                                <th scope="col" class="is-shrink">{{ __('public_page.page.result') }}</th>
                                <th scope="col" class="is-shrink">{{ __('public_page.page.requirements') }}</th>
                                <th scope="col">{{ __('public_page.page.covers') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($page->standards() as $row)
                                    <tr>
                                        <th scope="row" class="is-nowrap">{{ $row['reference'] }}</th>
                                        <td class="is-nowrap">
                                            @if (! $row['applicable'])
                                                <span class="a-badge a-badge-neutral">{{ __('public_page.page.not_applicable') }}</span>
                                            @elseif ($row['failed'] === 0)
                                                <span class="a-badge a-badge-success">{{ __('public_page.page.met') }}</span>
                                            @else
                                                <span class="a-badge a-badge-danger">{{ __('public_page.page.failed', ['count' => $row['failed']]) }}</span>
                                            @endif
                                        </td>
                                        <td class="is-nowrap">{{ $row['applicable'] ? __('public_page.page.applicable', ['count' => $row['requirements']]) : '—' }}</td>
                                        <td>{{ $row['article'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if ($page->shows('risks'))
                @php($total = array_sum($counts))
                <section class="pp-section" aria-labelledby="pp-risks">
                    <h2 class="pp-h2" id="pp-risks">{{ __('public_page.sections.risks') }}</h2>
                    <p class="pp-muted">{{ trans_choice('public_page.page.risks_lead', $total, ['count' => $total]) }}</p>
                    @if ($total > 0)
                        <div class="pp-bar" role="img" aria-label="{{ $levels->filter(fn ($l, $rank) => isset($counts[$rank]))->map(fn ($l, $rank) => $l->name.' '.$counts[$rank])->implode(', ') }}">
                            @foreach ($levels->sortKeysDesc() as $rank => $level)
                                @if ($counts[$rank] ?? 0)<span class="pp-fill-{{ (int) $rank }}"></span>@endif
                            @endforeach
                        </div>
                        <p class="pp-legend">
                            @foreach ($levels->sortKeysDesc() as $rank => $level)
                                <span><i class="pp-fill-{{ (int) $rank }}" aria-hidden="true"></i>{{ $level->name }} <b>{{ $counts[$rank] ?? 0 }}</b></span>
                            @endforeach
                        </p>
                    @endif
                    @foreach ($listed as $risk)
                        <div class="pp-risk">
                            @if ($risk->residual_rank && $levels->has($risk->residual_rank))
                                <span class="a-level pp-level-{{ (int) $risk->residual_rank }}">{{ $levels[$risk->residual_rank]->name }}</span>
                            @endif
                            <div class="min-w-0">
                                <p class="pp-risk-title">{{ $settings->riskTitle($risk) }}</p>
                                @if ($settings->riskAdvice($risk) !== '')
                                    <p class="pp-risk-advice"><span class="a-sr-only">{{ __('public_page.page.advice') }}: </span>{{ $settings->riskAdvice($risk) }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </section>
            @endif

            @if ($page->shows('vex'))
                @php($statements = $page->vexStatements())
                @php($vexCounts = $page->vexCounts())
                <section class="pp-section" aria-labelledby="pp-vex">
                    <div class="pp-section-head">
                        <h2 class="pp-h2" id="pp-vex">{{ __('public_page.sections.vex') }}</h2>
                        @if ($page->lastChecked())
                            <span class="pp-muted">{{ __('public_page.page.checked', ['date' => $date($page->lastChecked())]) }}</span>
                        @endif
                    </div>
                    @if ($statements->isEmpty())
                        <p class="pp-muted">{{ __('public_page.page.no_vex') }}</p>
                    @else
                        <div class="pp-tiles">
                            @foreach (['affected', 'under_investigation', 'fixed', 'not_affected'] as $status)
                                @if (($vexCounts[$status] ?? 0) > 0 || in_array($status, ['affected', 'fixed', 'not_affected'], true))
                                    <div class="pp-tile"><div class="pp-tile-value">{{ $vexCounts[$status] ?? 0 }}</div><div class="a-micro">{{ __('sbom.vex.status.'.$status) }}</div></div>
                                @endif
                            @endforeach
                        </div>
                        <div class="a-table-wrap">
                            <table class="a-table a-table-pin-2">
                                <thead><tr>
                                    <th scope="col" class="is-shrink"><span class="a-sr-only">#</span></th>
                                    <th scope="col">{{ __('public_page.page.vulnerability') }}</th>
                                    <th scope="col">{{ __('public_page.page.component') }}</th>
                                    <th scope="col" class="is-shrink">{{ __('public_page.page.status') }}</th>
                                    <th scope="col">{{ __('public_page.page.why') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($statements as $s)
                                        <tr>
                                            <td class="is-shrink a-muted">{{ $loop->iteration }}</td>
                                            <td class="is-nowrap">{{ $s->vulnerability_key }}</td>
                                            <td>{{ trim(($s->component->name ?? '').' '.($s->component->code ?? '')) ?: '—' }}</td>
                                            <td class="is-nowrap"><span class="a-badge {{ \App\Sbom\Vex\VexStatus::TONES[$s->status] ?? 'a-badge-neutral' }}">{{ __('sbom.vex.status.'.$s->status) }}</span></td>
                                            <td>{{ $s->explanation() ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @foreach ($page->vexImports() as $import)
                        @foreach ((array) $import->files as $n => $file)
                            <div class="pp-file">
                                <span class="pp-file-name">{{ $file['name'] }}</span>
                                @if ($live)
                                    <a href="{{ route('verify.vex', [$code, $version->id, $import->id, $n]) }}" class="a-btn a-btn-outline a-btn-sm"><x-lucide-download aria-hidden="true" />{{ __('public_page.page.download') }}</a>
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                </section>
            @endif

            @foreach (['sbom', 'hbom'] as $bomSection)
                @if ($page->shows($bomSection))
                    @php($boms = $page->boms($bomSection))
                    <section class="pp-section" aria-labelledby="pp-{{ $bomSection }}">
                        <h2 class="pp-h2" id="pp-{{ $bomSection }}">{{ __('public_page.sections.'.$bomSection) }}</h2>
                        @php($components = (int) $boms->sum('component_count'))
                        @if ($boms->isEmpty())
                            <p class="pp-muted">{{ __('public_page.page.no_bom') }}</p>
                        @else
                            <p class="pp-bom-count">{{ trans_choice('public_page.page.components', $components, ['count' => $components]) }}</p>
                        @endif
                        @foreach ($boms as $import)
                            @foreach ((array) $import->files as $n => $file)
                                <div class="pp-file">
                                    <span class="pp-file-name">{{ $file['name'] }}</span>
                                    @if ($live)
                                        <a href="{{ route('verify.bom', [$code, $version->id, $import->id, $n]) }}" class="a-btn a-btn-outline a-btn-sm"><x-lucide-download aria-hidden="true" />{{ __('public_page.page.download') }}</a>
                                    @endif
                                </div>
                            @endforeach
                        @endforeach
                    </section>
                @endif
            @endforeach
        </div>

        <aside class="pp-side">
            @php($documents = $page->documents())
            @if ($documents->isNotEmpty())
                <section class="pp-section" aria-labelledby="pp-documents">
                    <h2 class="pp-h2" id="pp-documents">{{ __('public_page.sections.documents') }}</h2>
                    <div class="pp-files">
                        @foreach ($documents as $document)
                            <div class="pp-file">
                                <span>
                                    <span class="pp-file-name">{{ \Illuminate\Support\Facades\Lang::has('public_page.documents.kinds.'.$document->category) ? __('public_page.documents.kinds.'.$document->category) : pathinfo($document->original_name, PATHINFO_FILENAME) }}</span>
                                    <span class="pp-muted block">{{ strtoupper(pathinfo($document->original_name, PATHINFO_EXTENSION)) }} · {{ \App\Support\FileSize::format((int) $document->size) }}</span>
                                </span>
                                @if ($live)
                                    <a href="{{ route('verify.document', [$code, $version->id, $document->id]) }}" class="a-btn a-btn-outline a-btn-icon" aria-label="{{ __('public_page.page.download') }}: {{ $document->original_name }}" title="{{ __('public_page.page.download') }}"><x-lucide-download aria-hidden="true" /></a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="pp-section" aria-labelledby="pp-maker">
                <h2 class="pp-h2" id="pp-maker">{{ __('public_page.page.manufacturer') }}</h2>
                <p class="pp-address"><b>{{ $maker['name'] }}</b>{{ collect([$maker['address'], $maker['country']])->filter()->map(fn ($l) => "
".$l)->implode('') }}</p>
                @if ($maker['rep_name'])
                    <p class="pp-address"><span class="a-micro block">{{ __('public_page.page.authorised_rep') }}</span><b>{{ $maker['rep_name'] }}</b>{{ $maker['rep_address'] ? "
".$maker['rep_address'] : '' }}</p>
                @endif
            </section>

            @if ($page->shows('contact') && $settings->contact)
                <section class="pp-contact" aria-labelledby="pp-contact">
                    <h2 class="pp-h2" id="pp-contact">{{ __('public_page.sections.contact') }}</h2>
                    <p class="pp-contact-value">{{ $settings->contact }}</p>
                    @if ($contactHref && ! $preview)
                        <a href="{{ $contactHref }}" class="a-btn a-btn-primary" rel="noopener">{{ __('public_page.page.report') }}</a>
                    @elseif ($contactHref)
                        <span class="a-btn a-btn-primary" aria-disabled="true">{{ __('public_page.page.report') }}</span>
                    @endif
                </section>
            @endif
        </aside>
    </div>
</div>
