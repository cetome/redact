{{--
    VEX status beside a Known vulnerability's name (inventory/index): one badge per status, the
    component and justification on hover (the line below drops the status, see VexStatus::detailLine).
    @includeWhen(\App\Sbom\SbomFeature::isVulnerabilityList($list['key']), 'sbom._vex-status', ['item' => $item])
--}}
@php
    $vexGroups = \App\Sbom\Vex\VexStatus::forVulnerability($item->product_version_id, $item->id)->groupBy('status');
@endphp
@foreach ($vexGroups as $vexStatus => $vexStatements)
    <span class="a-badge {{ \App\Sbom\Vex\VexStatus::TONES[$vexStatus] ?? 'a-badge-neutral' }} ml-1"
          title="{{ $vexStatements->map(fn ($s) => trim(($s->component ? $s->component->code.' '.$s->component->name : __('sbom.vex.product')).': '.($s->explanation() ?: __('sbom.vex.status.'.$s->status))))->implode("\n") }}">
        <x-lucide-shield-check aria-hidden="true" />VEX · {{ __('sbom.vex.status.'.$vexStatus) }}@if ($vexStatements->count() > 1) ({{ $vexStatements->count() }})@endif
    </span>
@endforeach
