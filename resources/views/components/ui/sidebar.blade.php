{{--
    Left sidebar of a product version: the product, then the workflow steps in the order of
    Assessment::steps() (through App\Support\WorkflowSummary), each with its completion. Steps that
    exist once per standard (decision trees, E.Info) show one sub-line per standard with a bar.
    Rendered by <x-layouts.app> when the page registered its version (see <x-version-nav>).

    <x-ui.sidebar :version="$version" active="risks" />
--}}
@props(['version', 'active' => 'overview'])
@php
    $summary = \App\Support\WorkflowSummary::for($version);
    $product = $version->product;
    $currentStandard = request()->route('standard');
    $currentLabel = $active === 'overview' ? __('workflow.overview') : (\Illuminate\Support\Facades\Lang::has('workflow.steps.'.$active) ? __('workflow.steps.'.$active) : '');

    $tools = [];
    if (Route::has('versions.ai')) {
        $tools['ai'] = [route('versions.ai', $version), __('workflow.steps.ai'), 'sparkles'];
    }
    if (Route::has('reports.index')) {
        $tools['reports'] = [route('reports.index', $version), __('workflow.steps.reports'), 'file-down'];
    }
    $tools['team'] = [route('team.index', $product), __('products.team'), 'users'];
    // History (App\History): the owner, the organisation's admins, contributors, staff on support access; never reviewers.
    if (auth()->user() && ! auth()->user()->is_guest && \App\Support\ProductAccess::for(auth()->user(), $product)->canSeeHistory()) {
        $tools['history'] = [route('products.history', $product), __('history.link'), 'history'];
    }

    // Keyboard shortcuts "g + letter" (APP-UI.md "Keyboard shortcuts").
    $keys = ['overview' => 'o', 'scoping' => 's', 'description' => 'd', 'inventory' => 'i', 'evaluate' => 'e',
        'einfo' => 'f', 'units' => 'u', 'risks' => 'r', 'conformity' => 'c', 'review' => 'v', 'declaration' => 'l', 'reports' => 'x'];
@endphp
<aside class="a-sidebar" aria-label="{{ __('ui.workflow') }}">
    <button type="button" class="a-sidebar-toggle" data-disclosure="workspace-steps" aria-expanded="false" aria-controls="workspace-steps">
        <x-ui.thumb :product="$product" size="sm" />
        <span class="min-w-0 truncate">{{ $product->name }} {{ $version->label }}@if ($currentLabel) · <span class="a-muted">{{ $currentLabel }}</span>@endif</span>
        <x-ui.progress :value="$summary->doneSteps()" :max="$summary->totalSteps()" :label="__('ui.steps_done', ['done' => $summary->doneSteps(), 'total' => $summary->totalSteps()])" />
    </button>
    <div class="a-sidebar-content" id="workspace-steps">
        <a href="{{ $product->homeUrl() }}" class="a-sidebar-product">
            <x-ui.thumb :product="$product" />
            <span class="min-w-0">
                <span class="a-sidebar-product-name">{{ $product->name }}</span>
                <span class="a-sidebar-product-meta">{{ __('versions.version') }} {{ $version->label }} <x-version-status :status="$version->status" /></span>
            </span>
        </a>

        <nav aria-label="{{ __('ui.workflow') }}">
            <a href="{{ route('versions.show', $version) }}" class="a-step" @if ($active === 'overview') aria-current="page" @endif
               data-shortcut="g o" data-shortcut-label="{{ __('shortcuts.go.overview') }}">
                <span class="a-step-no"><x-lucide-layout-grid aria-hidden="true" /></span>
                <span class="a-step-label">{{ __('workflow.overview') }}</span>
                <span class="a-step-count">{{ $summary->doneSteps() }}/{{ $summary->totalSteps() }}</span>
            </a>

            <p class="a-sidebar-label">{{ __('ui.steps') }}</p>
            <ol class="a-steps">
                @foreach ($summary->groups as $group)
                    @php($isActive = $active === $group['key'])
                    <li>
                        <a href="{{ $group['href'] }}" @class(['a-step', 'is-done' => $group['done']])
                           @if ($isActive && ($group['subs'] === [] || $currentStandard === null) && ($group['lists'] === [] || request()->route('list') === null || request()->route('panel') === 'mappings')) aria-current="page" @endif
                           @if ($group['detail']) title="{{ $group['detail'] }}" @endif
                           @isset($keys[$group['key']]) data-shortcut="g {{ $keys[$group['key']] }}" data-shortcut-label="{{ __('shortcuts.go.'.$group['key']) }}" @endisset>
                            <span class="a-step-no">
                                <x-dynamic-component :component="'lucide-'.\App\Support\WorkflowSummary::icon($group['key'])" aria-hidden="true" />
                                @if ($group['done'])<span class="a-sr-only">{{ __('workflow.done') }}</span>@endif
                            </span>
                            <span class="min-w-0">
                                <span class="a-step-label">{{ $group['label'] }}</span>
                                @if ($group['subs'] === [] && $group['detail'] && $group['total'] === null)
                                    <span class="a-step-sub">{{ $group['detail'] }}</span>
                                @endif
                            </span>
                            @if ($group['total'] !== null)
                                <span class="a-step-count">{{ $group['doneCount'] }}/{{ $group['total'] }}</span>
                            @endif
                        </a>
                        @if ($group['subs'] !== [])
                            <ul class="a-steps a-substeps">
                                @foreach ($group['subs'] as $sub)
                                    <li>
                                        <a href="{{ $sub['href'] }}" @class(['a-step a-substep', 'is-done' => $sub['total'] > 0 && $sub['done'] === $sub['total']])
                                           @if ($isActive && $currentStandard === $sub['standard']) aria-current="page" @endif>
                                            <span class="a-substep-label">{{ $sub['name'] }}</span>
                                            @if (($sub['unjustified'] ?? 0) > 0)
                                                <span class="a-step-warn" title="{{ $sub['unjustified'] }} · {{ __('evaluations.status.unjustified') }}"><x-lucide-scale aria-hidden="true" /><span class="a-sr-only">{{ $sub['unjustified'] }} · {{ __('evaluations.status.unjustified') }}</span></span>
                                            @endif
                                            <span class="a-step-count">{{ $sub['done'] }}/{{ $sub['total'] }}</span>
                                            <x-ui.progress :value="$sub['done']" :max="$sub['total']" />
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($isActive && $group['lists'] !== [])
                            {{-- Assets & interfaces, while on it: one line per list of the guided sequence. --}}
                            <ul class="a-steps a-substeps a-guide-substeps">
                                @foreach ($group['lists'] as $sub)
                                    <li>
                                        <a href="{{ $sub['href'] }}" @class(['a-step a-substep', 'is-done' => $sub['done']])
                                           @if (request()->route('list') === $sub['key'] && request()->route('panel') !== 'mappings') aria-current="page" @endif>
                                            <span class="a-substep-label">{{ $sub['name'] }}</span>
                                            <span class="a-step-count">@if ($sub['done'])<x-lucide-check class="a-guide-tick" aria-hidden="true" /><span class="a-sr-only">{{ __('workflow.done') }} · </span>@endif{{ $sub['none'] ? __('inventory.none_short') : $sub['count'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ol>

            <p class="a-sidebar-label">{{ __('ui.tools') }}</p>
            <ul class="a-steps">
                @foreach ($tools as $key => [$href, $label, $icon])
                    <li>
                        <a href="{{ $href }}" class="a-step" @if ($active === $key) aria-current="page" @endif
                           @isset($keys[$key]) data-shortcut="g {{ $keys[$key] }}" data-shortcut-label="{{ __('shortcuts.go.'.$key) }}" @endisset>
                            <span class="a-step-no"><x-dynamic-component :component="'lucide-'.$icon" aria-hidden="true" /></span>
                            <span class="a-step-label">{{ $label }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>
</aside>
