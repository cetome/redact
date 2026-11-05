{{-- An organisation's Settings (its admins): the Organisation card, logo, name and manufacturer (organisations/_card). --}}
<x-layouts.app :title="__('org_ai.title').' · '.$organisation->name">
    {{-- As the other tabs (Portfolio, Team, History): the organisation above, the tab's name, one line. --}}
    <x-ui.page-header :title="__('org_ai.tab')" :subtitle="__('organisation.lead')">
        <x-slot:eyebrow>{{ $organisation->name }}</x-slot:eyebrow>
    </x-ui.page-header>

    @include('organisations._tabs', ['organisation' => $organisation, 'active' => 'settings', 'isAdmin' => true])

    {{-- Named after an e-mail address at sign-up (only the address is asked): the company's name is better. --}}
    @if (str_contains($organisation->name, '@'))
        <div class="a-alert" role="status"><x-lucide-info aria-hidden="true" /><span>{{ __('organisation.unnamed') }}</span></div>
    @endif

    @include('organisations._card', ['organisation' => $organisation])
</x-layouts.app>
