{{--
    Registers the product version of the page, so that <x-layouts.app> shows the workspace: the
    breadcrumb in the top bar and the left sidebar with the workflow steps (<x-ui.sidebar>, built
    from Assessment::steps()). It prints nothing itself. Slots are rendered before their layout,
    so it can sit in the `subnav` slot:

    <x-layouts.app :title="…">
        <x-slot:subnav><x-version-nav :version="$version" active="scoping" /></x-slot:subnav>
        …
    </x-layouts.app>

    `active` is a step key of Assessment::steps() (scoping, description, inventory, evaluate,
    einfo, risks, conformity, review), or overview, ai, reports, team.
--}}
@props(['version', 'active' => 'overview'])
@php(request()->attributes->set('redact.workspace', ['version' => $version, 'active' => $active]))
