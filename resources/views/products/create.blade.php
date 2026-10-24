<x-layouts.app :title="__('scoping.heading')">
    <x-ui.page-header :title="__('scoping.heading')" :subtitle="__('scoping.lead')" />

    @include('products._scope-form', ['organisations' => $organisations])
</x-layouts.app>
