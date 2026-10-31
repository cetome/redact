{{-- Portfolio | Team | History | Settings of an organisation. Portfolio and History are for its admins;
     members see Team and Settings (they read what answers them). $organisation, $active, $isAdmin (default true). --}}
@php $isAdmin ??= true; @endphp
<nav class="a-tabs" aria-label="{{ __('history.tabs') }}">
    @if ($isAdmin)
        <a href="{{ route('organisations.portfolio', $organisation) }}" class="a-tab" @if ($active === 'portfolio') aria-current="page" @endif>
            <x-lucide-layout-dashboard aria-hidden="true" />{{ __('portfolio.title') }}
        </a>
    @endif
    <a href="{{ route('organisations.team', $organisation) }}" class="a-tab" @if ($active === 'team') aria-current="page" @endif>
        <x-lucide-users aria-hidden="true" />{{ __('orgteam.title') }}
    </a>
    @if ($isAdmin)
        <a href="{{ route('organisations.history', $organisation) }}" class="a-tab" @if ($active === 'history') aria-current="page" @endif>
            <x-lucide-history aria-hidden="true" />{{ __('history.title') }}
        </a>
    @endif
    <a href="{{ route('organisations.settings', $organisation) }}" class="a-tab" @if ($active === 'settings') aria-current="page" @endif>
        <x-lucide-settings aria-hidden="true" />{{ __('org_ai.tab') }}
    </a>
</nav>
