{{-- cetome console sub-navigation: the tabs at the top of every console page. --}}
<nav class="a-tabs" aria-label="{{ __('admin.nav.label') }}">
    @foreach ([
        'admin.dashboard' => ['admin.dashboard', 'admin.nav.dashboard', 'layout-dashboard'],
        'admin.users.index' => ['admin.users.*', 'admin.nav.users', 'users'],
        'admin.products.index' => ['admin.products.*', 'admin.nav.products', 'package'],
        'admin.organisations.index' => ['admin.organisations.*', 'plans.admin.nav', 'building-2'],
        'admin.activity.index' => ['admin.activity.*', 'admin.nav.activity', 'history'],
        'admin.usage.index' => ['admin.usage.*', 'admin.nav.usage', 'chart-column'],
        'admin.contact.index' => ['admin.contact.*', 'contact.admin.nav', 'inbox'],
        'admin.ai.edit' => ['admin.ai.*', 'ai_settings.nav', 'sparkles'],
    ] as $route => [$pattern, $label, $icon])
        <a href="{{ route($route) }}" class="a-tab" @if (request()->routeIs($pattern)) aria-current="page" @endif>
            <x-dynamic-component :component="'lucide-'.$icon" class="size-4" aria-hidden="true" />{{ __($label) }}</a>
    @endforeach
</nav>
