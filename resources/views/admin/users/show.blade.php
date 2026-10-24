<x-layouts.app :title="$user->name" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="$user->name" :subtitle="$user->email">
        <x-slot:eyebrow><a href="{{ route('admin.users.index') }}">← {{ __('admin.users.back') }}</a></x-slot:eyebrow>
        <div class="mt-2">@include('admin.users.status', ['user' => $user])</div>
    </x-ui.page-header>

    <div class="grid items-start gap-4 xl:grid-cols-3">
        {{-- Profile --}}
        <section class="a-panel xl:col-span-2" aria-labelledby="profile-heading">
            <div class="a-panel-body">
                <h2 id="profile-heading" class="a-h3">{{ __('admin.users.profile') }}</h2>
                <dl class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    @foreach ([
                        'admin.users.organisation' => $user->organisation_name ?: '—',
                        'admin.users.created' => $user->created_at?->isoFormat('D MMMM YYYY, HH:mm'),
                        'admin.users.verified' => $user->email_verified_at?->isoFormat('D MMMM YYYY, HH:mm') ?? __('common.no'),
                        'admin.users.last_sign_in' => $user->last_login_at ? $user->last_login_at->isoFormat('D MMMM YYYY, HH:mm').' · '.\App\Admin\Label::of('methods', $user->last_login_method) : __('admin.never'),
                        'admin.users.locale' => config('redact.locales.'.$user->locale, $user->locale),
                        'admin.users.timezone' => $user->timezone,
                        'admin.users.terms' => $user->terms_accepted_at ? $user->terms_accepted_at->isoFormat('D MMMM YYYY').' ('.$user->terms_version.')' : '—',
                        'admin.users.product_news' => $user->wants_product_news ? __('common.yes') : __('common.no'),
                    ] as $label => $value)
                        <div>
                            <dt class="a-micro">{{ __($label) }}</dt>
                            <dd class="mt-1">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </section>

        {{-- Actions --}}
        <section class="a-panel" aria-labelledby="actions-heading">
            <div class="a-panel-body">
                <h2 id="actions-heading" class="a-h3">{{ __('common.actions') }}</h2>
                @if ($canManage)
                    <div class="mt-4 flex flex-col gap-3">
                        @if ($user->active)
                            <form method="POST" action="{{ route('admin.users.disable', $user) }}" x-data="confirmSubmit"
                                data-confirm="{{ __('admin.users.confirm.disable', ['name' => $user->name]) }}" @submit="check">
                                @csrf
                                <button type="submit" class="a-btn a-btn-danger a-btn-sm"><x-lucide-ban aria-hidden="true" />{{ __('admin.users.actions.disable') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.users.enable', $user) }}" x-data="confirmSubmit"
                                data-confirm="{{ __('admin.users.confirm.enable', ['name' => $user->name]) }}" @submit="check">
                                @csrf
                                <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-check aria-hidden="true" />{{ __('admin.users.actions.enable') }}</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('admin.users.sessions.revoke', $user) }}" x-data="confirmSubmit"
                            data-confirm="{{ __('admin.users.confirm.revoke_sessions', ['name' => $user->name]) }}" @submit="check">
                            @csrf
                            <button type="submit" class="a-btn a-btn-outline a-btn-sm"><x-lucide-log-out aria-hidden="true" />{{ __('admin.users.actions.revoke_sessions') }}</button>
                        </form>
                    </div>
                @else
                    <p class="a-help mt-4">{{ $isSelf ? __('admin.users.self_note') : __('admin.users.staff_note') }}</p>
                @endif

                @if ($isSuperAdmin)
                    <h3 class="a-micro mt-5">{{ __('admin.users.platform_roles') }}</h3>
                    <ul class="mt-2 flex flex-col gap-2">
                        @foreach ($roles as $role)
                            @php($has = $user->hasRole($role))
                            @php($locked = $has && $role === 'super-admin' && $isSelf)
                            <li class="flex items-center justify-between gap-3">
                                <span>{{ \App\Admin\Label::of('roles', $role) }}</span>
                                @if ($locked)
                                    <span class="a-help">{{ __('admin.users.own_super_admin') }}</span>
                                @else
                                    <form method="POST" action="{{ route('admin.users.roles', $user) }}" x-data="confirmSubmit"
                                        data-confirm="{{ __($has ? 'admin.users.confirm.revoke_role' : 'admin.users.confirm.grant_role', ['role' => \App\Admin\Label::of('roles', $role), 'name' => $user->name]) }}"
                                        @submit="check">
                                        @csrf
                                        <input type="hidden" name="role" value="{{ $role }}">
                                        <input type="hidden" name="action" value="{{ $has ? 'revoke' : 'grant' }}">
                                        <button type="submit" class="a-btn {{ $has ? 'a-btn-ghost' : 'a-btn-outline' }} a-btn-sm">
                                            {{ $has ? __('admin.users.actions.revoke_role') : __('admin.users.actions.grant_role') }}
                                        </button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    </div>

    <div class="mt-4 grid items-start gap-4 xl:grid-cols-2">
        {{-- Organisations and products --}}
        <section class="a-panel" aria-labelledby="orgs-heading">
            <div class="a-panel-body">
                <h2 id="orgs-heading" class="a-h3">{{ __('admin.users.organisations') }}</h2>
                @if ($user->organisations->isEmpty())
                    <p class="a-help mt-3">{{ __('common.none') }}</p>
                @else
                    <ul class="divide-y divide-line mt-2">
                        @foreach ($user->organisations as $organisation)
                            <li class="flex justify-between gap-4 py-2">
                                <a href="{{ route('admin.organisations.show', $organisation) }}" class="a-link-btn">{{ $organisation->name }}</a>
                                <span class="a-help">{{ \App\Admin\Label::of('org_roles', $organisation->pivot->role) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <h2 class="a-h3 mt-6">{{ __('admin.users.memberships') }}</h2>
                @if ($memberships->isEmpty())
                    <p class="a-help mt-3">{{ __('common.none') }}</p>
                @else
                    <ul class="divide-y divide-line mt-2">
                        @foreach ($memberships as $membership)
                            <li class="flex flex-wrap justify-between gap-4 py-2">
                                <span>
                                    <a href="{{ route('products.show', $membership['product']) }}" class="a-link-btn">{{ $membership['product']->name }}</a>
                                    <span class="block a-help">{{ $membership['product']->organisation?->name }}</span>
                                </span>
                                <span class="a-help text-right">
                                    {{ \App\Admin\Label::of('product_roles', $membership['role']) }}
                                    @if ($membership['external'])
                                        · {{ __('admin.users.external') }}
                                    @endif
                                    @if ($membership['expires_at'])
                                        <span class="block">{{ __('admin.users.expires', ['date' => $membership['expires_at']->isoFormat('D MMMM YYYY')]) }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        {{-- Passkeys and sessions --}}
        <section class="a-panel" aria-labelledby="passkeys-heading">
            <div class="a-panel-body">
                <h2 id="passkeys-heading" class="a-h3">{{ __('admin.users.passkeys') }}</h2>
                @if ($user->passkeys->isEmpty())
                    <p class="a-help mt-3">{{ __('common.none') }}</p>
                @else
                    <ul class="divide-y divide-line mt-2">
                        @foreach ($user->passkeys as $passkey)
                            <li class="flex flex-wrap justify-between gap-4 py-2">
                                <span>{{ $passkey->name }}</span>
                                <span class="a-help text-right">
                                    {{ __('admin.users.passkey_added', ['date' => $passkey->created_at?->isoFormat('D MMMM YYYY')]) }}
                                    <span class="block">{{ $passkey->last_used_at ? __('admin.users.passkey_used', ['date' => $passkey->last_used_at->isoFormat('D MMMM YYYY')]) : __('admin.users.passkey_never_used') }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <h2 class="a-h3 mt-6">{{ __('admin.users.sessions') }}</h2>
                @if ($sessions->isEmpty())
                    <p class="a-help mt-3">{{ __('admin.users.no_sessions') }}</p>
                @else
                    <ul class="divide-y divide-line mt-2">
                        @foreach ($sessions as $session)
                            <li class="py-2">
                                <span class="block">{{ \App\Auth\Sessions::device((string) $session->user_agent) }}</span>
                                <span class="block a-help break-all">{{ \Illuminate\Support\Str::limit((string) $session->user_agent, 120) }}</span>
                                <span class="a-help">
                                    {{ __('admin.users.session_active', ['date' => $session->last_activity->isoFormat('D MMMM YYYY, HH:mm')]) }}
                                    @if ($isSuperAdmin && $session->ip_address)
                                        · {{ __('admin.users.ip', ['ip' => $session->ip_address]) }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    </div>

    {{-- Security events --}}
    <section class="a-section" aria-labelledby="security-heading">
        <h2 id="security-heading" class="a-h2 mb-2">{{ __('admin.users.security') }}</h2>
        @if ($security->isEmpty())
            <p class="a-help mt-4">{{ __('admin.users.no_security') }}</p>
        @else
            <div class="a-table-wrap mt-2">
                <table class="a-table a-table-compact">
                    <caption class="sr-only">{{ __('admin.users.security') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.activity.date') }}</th>
                            <th scope="col">{{ __('admin.activity.description') }}</th>
                            <th scope="col">{{ __('admin.activity.details') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($security as $event)
                            <tr>
                                <td class="whitespace-nowrap">{{ $event->created_at?->isoFormat('D MMMM YYYY, HH:mm') }}</td>
                                <td>{{ $event->description }}</td>
                                <td class="text-label">
                                    @foreach (\App\Admin\ActivityExplorer::context($event) as $key => $value)
                                        @continue($key === 'ip' && ! $isSuperAdmin)
                                        <span class="block"><span class="text-ink-muted">{{ $key }}</span> {{ $value }}</span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Timeline --}}
    <section class="a-section" aria-labelledby="timeline-heading">
        <h2 id="timeline-heading" class="a-h2 mb-2">{{ __('admin.users.timeline') }}</h2>
        <form method="POST" action="{{ route('admin.users.timeline', $user) }}" class="a-toolbar items-end!">
            @csrf
            <div class="a-field">
                <label for="source" class="a-label">{{ __('admin.users.source') }}</label>
                <select id="source" name="source" class="a-select a-input-sm">
                    @foreach ($sources as $source)
                        <option value="{{ $source }}" @selected($filters['source'] === $source)>{{ __('admin.users.sources.'.$source) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="a-field">
                <label for="from" class="a-label">{{ __('admin.from') }}</label>
                <input id="from" name="from" type="date" class="a-input a-input-sm" value="{{ $filters['from'] }}">
            </div>
            <div class="a-field">
                <label for="to" class="a-label">{{ __('admin.to') }}</label>
                <input id="to" name="to" type="date" class="a-input a-input-sm" value="{{ $filters['to'] }}">
            </div>
            <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-filter aria-hidden="true" />{{ __('admin.filter') }}</button>
            @if (($filters['source'] ?? 'all') !== 'all' || filled($filters['from']) || filled($filters['to']))<button type="submit" name="reset" value="1" class="a-btn a-btn-ghost a-btn-sm"><x-lucide-rotate-ccw aria-hidden="true" />{{ __('admin.reset') }}</button>@endif
        </form>
        <p class="a-help mt-4">{{ __('admin.users.timeline_limit', ['count' => \App\Admin\UserInsight::TIMELINE_LIMIT]) }}</p>

        @if ($timeline->isEmpty())
            <p class="mt-4">{{ __('admin.users.no_timeline') }}</p>
        @else
            <ol class="divide-y divide-line mt-2">
                @foreach ($timeline as $entry)
                    <li class="py-3 grid gap-2 md:grid-cols-[12rem_1fr]">
                        <time class="a-help whitespace-nowrap" datetime="{{ $entry['at']->toIso8601String() }}">{{ $entry['at']->isoFormat('D MMMM YYYY, HH:mm:ss') }}</time>
                        <div>
                            @if ($entry['kind'] === 'activity')
                                <span class="a-badge a-badge-neutral">{{ \App\Admin\Label::of('logs', $entry['log']) }}</span>
                                <span class="font-semibold">{{ $entry['label'] }}</span>
                                @if ($entry['detail'])
                                    <span class="a-help">{{ $entry['detail'] }}</span>
                                @endif
                                @include('admin.partials.changes', ['changes' => $entry['changes']])
                            @else
                                <span class="a-badge {{ $entry['status'] >= 500 ? 'a-badge-danger' : ($entry['status'] >= 400 ? 'a-badge-warning' : 'a-badge-neutral') }}">{{ $entry['status'] }}</span>
                                <span class="font-mono text-label">{{ $entry['label'] }}</span>
                                @if ($entry['product_id'] && isset($timelineProducts[$entry['product_id']]))
                                    @php($product = $timelineProducts[$entry['product_id']])
                                    <span class="a-help">·
                                        @if ($product->deleted_at)
                                            {{ $product->name }}
                                        @else
                                            <a href="{{ route('products.show', $product) }}" class="a-link-btn">{{ $product->name }}</a>
                                        @endif
                                    </span>
                                @endif
                                <span class="block a-help font-mono">{{ $entry['detail'] }}</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
</x-layouts.app>
