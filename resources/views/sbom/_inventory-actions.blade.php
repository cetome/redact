{{--
    Header buttons of the component list (inventory/index): import a bill of materials, known vulnerabilities.
    @includeWhen(\App\Sbom\SbomFeature::isComponentList($list['key']), 'sbom._inventory-actions')
--}}
@if (\App\Sbom\SbomFeature::checkable())
    <a href="{{ route('versions.vulnerabilities', $version) }}" class="a-btn a-btn-outline" title="{{ __('sbom.vulnerabilities_tooltip') }}"><x-lucide-shield-alert aria-hidden="true" />{{ __('sbom.vulnerabilities_button') }}</a>
@endif
<a href="{{ route('versions.sbom', $version) }}" class="a-btn a-btn-outline" title="{{ __('sbom.import_tooltip') }}"><x-lucide-file-up aria-hidden="true" />{{ __('sbom.import_button') }}</a>
