{{--
    Vulnerability status under a component's name in the component list (inventory/index):
    known exploited, open findings to review, in the vulnerabilities list, set aside (muted), last look-up.
    @includeWhen(\App\Sbom\SbomFeature::isComponentList($list['key']), 'sbom._row-status', ['item' => $item])
--}}
@php
    $vulnStatus = \App\Sbom\SbomFeature::checkable() ? \App\Vulnerabilities\ComponentStatus::of($item->product_version_id, $item->id) : null;
@endphp
@if ($vulnStatus && ($vulnStatus['open'] || $vulnStatus['added'] || $vulnStatus['dismissed'] || $vulnStatus['match'] !== 'none'))
    <a href="{{ route('versions.vulnerabilities', $item->product_version_id) }}#component-{{ $item->id }}" class="a-vuln-status" data-no-row-click title="{{ __('sbom.row.tooltip') }}">
        @if ($vulnStatus['exploited'])
            <span class="a-badge a-badge-danger"><x-lucide-flame aria-hidden="true" />{{ __('sbom.row.exploited') }}</span>
        @endif
        @if ($vulnStatus['open'])
            <span class="a-badge a-badge-warning">{{ __('sbom.row.open', ['count' => $vulnStatus['open']]) }}</span>
        @endif
        @if ($vulnStatus['added'])
            <span class="a-badge a-badge-success">{{ __('sbom.row.added', ['count' => $vulnStatus['added']]) }}</span>
        @endif
        @if ($vulnStatus['dismissed'])
            <span class="a-vuln-status-muted">{{ __('sbom.row.dismissed', ['count' => $vulnStatus['dismissed']]) }}</span>
        @endif
        @if ($vulnStatus['checked_at'] && $vulnStatus['match'] !== 'none')
            <span class="a-vuln-status-date"><x-lucide-shield-check aria-hidden="true" />{{ __('sbom.row.checked', ['date' => $vulnStatus['checked_at']->isoFormat('D MMM YYYY')]) }}@if ($vulnStatus['failed']) · {{ __('sbom.row.failed') }}@endif</span>
        @endif
    </a>
@endif
