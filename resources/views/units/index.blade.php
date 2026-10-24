{{-- The "Assessment units" step: in REDact, the manufacturer provides the result of every assessment unit of the standard, with its evidence;
     outside REDact, an independent test laboratory performs them (its report in Documents) and only the results recorded before stay, read-only. --}}
<x-layouts.app :title="__('workflow.steps.units')" :wide="true">
    <x-slot:subnav><x-version-nav :version="$version" active="units" /></x-slot:subnav>
    {{-- In review the units stay open to the people who provide them (AssessmentUnitController::canRecord): no "locked" notice for them. --}}
    <x-read-only-notice :access="$access" :version="$canChoose || $canRecord ? null : $version" />

    <x-ui.page-header :title="__('workflow.steps.units')" :subtitle="__('assessment.step_intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
    </x-ui.page-header>

    @if ($canChoose)
        {{-- Where they are performed (product_versions.units_external), whatever the conformity route. --}}
        <form method="POST" action="{{ route('versions.units.where', $version) }}" class="a-units-where">
            @csrf
            <fieldset class="a-fieldset">
                <legend class="a-label">{{ __('assessment.where.legend') }}</legend>
                <div class="a-orgteam-roles">
                    @foreach (['redact' => ! $external, 'external' => $external] as $where => $checked)
                        <label class="a-toggle-card">
                            <input type="radio" name="where" value="{{ $where }}" @checked($checked)>
                            <span>
                                <strong class="block">{{ __('assessment.where.'.$where) }}</strong>
                                <span class="a-toggle-card-text">{{ __('assessment.where.'.$where.'_help') }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div class="a-units-where-foot">
                @if ($external && Route::has('versions.documents'))
                    <a href="{{ route('versions.documents', $version) }}" class="a-btn a-btn-ghost"><x-lucide-paperclip aria-hidden="true" />{{ __('assessment.where.documents') }}</a>
                @elseif ($canInvite)
                    {{-- An external assessor (ProductRole::Assessor) records the units: the Team page's invite panel. --}}
                    <a href="{{ route('team.assessor', $version->product) }}" class="a-btn a-btn-ghost"><x-lucide-user-plus aria-hidden="true" />{{ __('assessment.invite_assessor') }}</a>
                @endif
                <button type="submit" class="a-btn a-btn-outline"><x-lucide-check aria-hidden="true" />{{ __('common.save') }}</button>
            </div>
        </form>
    @elseif ($external)
        <x-ui.alert icon="flask-conical">{{ __('assessment.external_line') }}</x-ui.alert>
    @endif

    @if (! $external)
        @include('units._stats', ['counts' => $cases['counts']])
        @include('units._cases', ['cases' => $cases, 'mode' => 'provide', 'canRecord' => $canRecord, 'canUpload' => $canUpload, 'documents' => $documents, 'version' => $version, 'me' => $me])
    @elseif ($cases['counts']['total'] > 0)
        {{-- Recorded before the choice: kept, read-only. --}}
        <div class="a-section-head"><h2 class="a-h2">{{ __('assessment.where.recorded_before') }}</h2></div>
        @include('units._cases', ['cases' => $cases, 'mode' => 'provide', 'canRecord' => false, 'external' => true, 'version' => $version, 'me' => $me])
    @endif
</x-layouts.app>
