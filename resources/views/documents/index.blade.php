<x-layouts.app :title="__('ai.documents.title')">
    <x-slot:subnav><x-version-nav :version="$version" active="description" /></x-slot:subnav>

    <x-ui.page-header :title="__('ai.documents.title')" :subtitle="__('ai.documents.intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
    </x-ui.page-header>

    <section class="a-panel max-w-3xl">
        <div class="a-panel-body">
            @include('documents._panel', ['version' => $version])
        </div>
    </section>
</x-layouts.app>
