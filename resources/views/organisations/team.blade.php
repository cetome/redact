{{--
    Organisation Team (OrganisationTeamController): everyone in the organisation and the outsiders on
    its products, one row each with their organisation role and their products (chips to each
    product's Team page). Admins: Invite (modal), Manage one person (dialog fetched in place,
    /team/{person} without script), revoke pending invitations. Members read it. Block O of app-ui.css.
--}}
@php
    $roleBadge = ['admin' => 'a-badge', 'member' => 'a-badge a-badge-neutral', 'external' => 'a-badge a-badge-warning'];
    $here = route('organisations.team', $organisation);
@endphp
<x-layouts.app :title="__('orgteam.title').' · '.$organisation->name">
    <x-ui.page-header :title="__('orgteam.title')" :subtitle="__('orgteam.intro')">
        <x-slot:eyebrow>{{ $organisation->name }}</x-slot:eyebrow>
        @if ($organisations->count() > 1 || $isAdmin)
            <x-slot:actions>
                @if ($organisations->count() > 1)
                    <details class="a-menu">
                        <summary class="a-btn a-btn-ghost"><x-lucide-building-2 aria-hidden="true" />{{ __('orgteam.switch') }}<x-lucide-chevron-down class="size-3.5" aria-hidden="true" /></summary>
                        <div class="a-menu-panel">
                            @foreach ($organisations as $other)
                                <a class="a-menu-item" href="{{ route('organisations.team', $other) }}" @if ($other->is($organisation)) aria-current="page" @endif>
                                    <x-lucide-building-2 aria-hidden="true" /><span class="min-w-0 truncate">{{ $other->name }}</span>
                                </a>
                            @endforeach
                        </div>
                    </details>
                @endif
                @if ($isAdmin)
                    <a href="{{ route('organisations.team.invite.show', $organisation) }}" class="a-btn a-btn-primary" data-dialog-open="invite"><x-lucide-user-plus aria-hidden="true" />{{ __('orgteam.invite') }}</a>
                @endif
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('organisations._tabs', ['organisation' => $organisation, 'active' => 'team', 'isAdmin' => $isAdmin])

    <section class="a-table-wrap" aria-label="{{ __('orgteam.title') }}">
        <table class="a-table a-orgteam">
            <thead>
                <tr>
                    <th scope="col">{{ __('orgteam.person') }}</th>
                    <th scope="col">{{ __('orgteam.organisation') }}</th>
                    <th scope="col">{{ __('orgteam.products') }}</th>
                    @if ($isAdmin)
                        <th scope="col" class="is-shrink"><span class="a-sr-only">{{ __('common.actions') }}</span></th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($people as $person)
                    <tr>
                        <th scope="row">
                            {{ $person['user']->name }}
                            @if ($person['user']->is(auth()->user()))<span class="a-badge a-badge-outline ml-1">{{ __('orgteam.you') }}</span>@endif
                            <span class="a-row-sub">{{ $person['user']->email }}</span>
                        </th>
                        <td class="is-nowrap">
                            <span class="{{ $roleBadge[$person['role']] }}" title="{{ __('orgteam.role_help.'.$person['role']) }}">{{ __('orgteam.role.'.$person['role']) }}</span>
                        </td>
                        <td>
                            @if ($person['products']->isEmpty())
                                <span class="a-muted">{{ __('orgteam.no_product') }}</span>
                            @else
                                <ul class="a-chips">
                                    @foreach ($person['products'] as $item)
                                        <li>
                                            @if (isset($viewerProducts[$item['product']->id]))
                                                <a href="{{ route('team.index', $item['product']) }}" @class(['a-chip a-orgteam-chip', 'is-expired' => $item['expired']]) title="{{ $item['role']->help() }}">
                                                    {{ $item['product']->name }}<span class="a-orgteam-role">{{ $item['role']->label() }}@if ($item['expired']) · {{ __('orgteam.expired') }}@endif</span>
                                                </a>
                                            @else
                                                <span @class(['a-chip a-orgteam-chip', 'is-expired' => $item['expired']]) title="{{ $item['role']->help() }}">
                                                    {{ $item['product']->name }}<span class="a-orgteam-role">{{ $item['role']->label() }}@if ($item['expired']) · {{ __('orgteam.expired') }}@endif</span>
                                                </span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                        @if ($isAdmin)
                            <td>
                                <a href="{{ route('organisations.team.person', [$organisation, $person['user']->id]) }}" class="a-btn a-btn-outline a-btn-sm" data-dialog-fetch="person"
                                   aria-label="{{ __('orgteam.manage') }}: {{ $person['user']->name }}">{{ __('orgteam.manage') }}</a>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    @if ($invitations->isNotEmpty())
        <section class="mt-6" aria-labelledby="pending-title">
            <div class="a-section-head"><h2 class="a-h2" id="pending-title">{{ __('orgteam.pending') }}</h2></div>
            <div class="a-table-wrap">
                <table class="a-table a-table-compact a-orgteam-pending">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('team.email') }}</th>
                            <th scope="col">{{ __('orgteam.organisation') }}</th>
                            <th scope="col">{{ __('orgteam.products') }}</th>
                            <th scope="col">{{ __('team.expires_col') }}</th>
                            <th scope="col" class="is-shrink"><span class="a-sr-only">{{ __('common.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invitations as $invitation)
                            @php
                                $items = $invitation->isForOrganisation()
                                    ? $invitation->children->filter(fn ($c) => $c->product && $c->role)
                                    : collect([$invitation]);
                                $orgRole = $invitation->isForOrganisation() ? ($invitation->organisation_role === 'admin' ? 'admin' : 'member') : ($invitation->external ? 'external' : null);
                            @endphp
                            <tr>
                                <td class="font-semibold">{{ $invitation->email }}</td>
                                <td class="is-nowrap">
                                    @if ($orgRole)<span class="{{ $roleBadge[$orgRole] }}">{{ __('orgteam.role.'.$orgRole) }}</span>@endif
                                </td>
                                <td>
                                    @if ($items->isEmpty())
                                        <span class="a-muted">{{ __('orgteam.no_product') }}</span>
                                    @else
                                        <ul class="a-chips">
                                            @foreach ($items as $item)
                                                <li><a href="{{ route('team.index', $item->product) }}" class="a-chip a-orgteam-chip">{{ $item->product->name }}<span class="a-orgteam-role">{{ $item->role->label() }}</span></a></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                                <td class="a-muted is-nowrap">{{ $invitation->expires_at->isoFormat('D MMM YYYY') }}</td>
                                <td>
                                    <form method="POST" action="{{ route('organisations.team.revoke', [$organisation, $invitation]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="a-btn a-btn-ghost a-btn-sm" type="submit">{{ __('team.revoke') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($isAdmin)
        {{-- Invite: e-mail, organisation role, and optionally products with a role each (one e-mail). --}}
        <x-ui.drawer id="invite" :modal="true" :wide="true" :open="$inviting" :close-url="$here" :title="__('orgteam.invite_title', ['organisation' => $organisation->name])">
            <form method="POST" action="{{ route('organisations.team.invite', $organisation) }}" id="invite-form" class="a-stack gap-4!">
                @csrf
                <div class="a-field">
                    <label class="a-label" for="invite_email">{{ __('team.email') }}</label>
                    <input id="invite_email" type="email" name="invite_email" class="a-input" required maxlength="191" autocomplete="off" value="{{ old('invite_email') }}" data-autofocus @error('invite_email') aria-invalid="true" @enderror>
                    @error('invite_email')<p class="a-error">{{ $message }}</p>@enderror
                </div>
                @include('organisations._org-role', ['name' => 'organisation_role', 'current' => old('organisation_role', 'member'), 'prefix' => 'invite', 'locked' => false])
                @if ($products->isNotEmpty())
                    @include('organisations._products', ['legend' => __('orgteam.products_optional'), 'person' => null, 'current' => old('products', [])])
                @endif
            </form>
            <x-slot:footer>
                <span class="a-spacer"></span>
                <a href="{{ $here }}" class="a-btn a-btn-ghost" data-dialog-close>{{ __('common.cancel') }}</a>
                <button type="submit" form="invite-form" class="a-btn a-btn-primary"><x-lucide-send aria-hidden="true" />{{ __('orgteam.send') }}</button>
            </x-slot:footer>
        </x-ui.drawer>

        {{-- One person: organisation role, products (add, change, remove), removal from the organisation. --}}
        <x-ui.drawer id="person" :modal="true" :wide="true" :open="$editing !== null" :close-url="$here">
            @if ($editing)
                @php
                    $user = $editing['user'];
                    $isMember = $editing['role'] !== 'external';
                    $lastAdmin = $editing['role'] === 'admin' && $adminCount <= 1;
                    $owned = $editing['products']->filter(fn ($i) => $i['role'] === \App\Enums\ProductRole::Owner);
                    $others = $people->filter(fn ($p) => $p['role'] !== 'external' && ! $p['user']->is($user));
                    $self = $user->is(auth()->user());
                @endphp
                <x-slot:eyebrow>{{ __('orgteam.role.'.$editing['role']) }}</x-slot:eyebrow>
                <x-slot:heading>{{ $user->name }} <span class="a-orgteam-email">{{ $user->email }}</span></x-slot:heading>

                <form method="POST" action="{{ route('organisations.team.update', [$organisation, $user]) }}" id="person-form" class="a-stack gap-4!">
                    @csrf
                    @method('PATCH')
                    @if ($isMember)
                        @include('organisations._org-role', ['name' => 'organisation_role', 'current' => $editing['role'], 'prefix' => 'person', 'locked' => $lastAdmin])
                    @endif
                    @if ($products->isNotEmpty())
                        @include('organisations._products', ['legend' => __('orgteam.products'), 'person' => $editing, 'current' => []])
                    @endif
                </form>

                <form method="POST" action="{{ route('organisations.team.destroy', [$organisation, $user]) }}" class="a-orgteam-remove"
                      x-data="confirmSubmit" data-confirm="{{ __('orgteam.remove_confirm', ['name' => $user->name, 'organisation' => $organisation->name]) }}" x-on:submit="check">
                    @csrf
                    @method('DELETE')
                    @if ($lastAdmin)
                        <p class="a-help"><span class="a-badge a-badge-neutral">{{ __('orgteam.only_admin') }}</span> {{ __('orgteam.last_admin', ['name' => $user->name]) }}</p>
                    @else
                        @php
                            $removeLabel = $self ? __('orgteam.leave', ['organisation' => $organisation->name]) : __('orgteam.remove', ['organisation' => $organisation->name]);
                        @endphp
                        @if ($owned->isEmpty())
                            <div class="flex flex-wrap items-center gap-3">
                                <button type="submit" class="a-btn a-btn-danger a-btn-sm"><x-lucide-user-minus aria-hidden="true" />{{ $removeLabel }}</button>
                                <span class="a-help">{{ __('orgteam.remove_help', ['organisation' => $organisation->name]) }}</span>
                            </div>
                        @else
                            {{-- They own products: each needs a new owner first, asked only once removal is opened. --}}
                            <details class="a-orgteam-handover">
                                <summary class="a-btn a-btn-outline a-btn-sm"><x-lucide-user-minus aria-hidden="true" />{{ $removeLabel }}</summary>
                                <div class="a-orgteam-owners">
                                    @foreach ($owned as $item)
                                        <div class="a-field">
                                            <label class="a-label" for="owner-{{ $item['product']->id }}">{{ __('orgteam.new_owner', ['product' => $item['product']->name]) }}</label>
                                            <select id="owner-{{ $item['product']->id }}" name="new_owner[{{ $item['product']->id }}]" class="a-select a-input-sm" required>
                                                <option value="">{{ __('orgteam.choose') }}</option>
                                                @foreach ($others as $other)
                                                    <option value="{{ $other['user']->id }}">{{ $other['user']->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <button type="submit" class="a-btn a-btn-danger a-btn-sm"><x-lucide-user-minus aria-hidden="true" />{{ $removeLabel }}</button>
                                    <span class="a-help">{{ __('orgteam.remove_help', ['organisation' => $organisation->name]) }}</span>
                                </div>
                            </details>
                        @endif
                    @endif
                </form>

                <x-slot:footer>
                    <span class="a-spacer"></span>
                    <a href="{{ $here }}" class="a-btn a-btn-ghost" data-dialog-close>{{ __('common.cancel') }}</a>
                    <button type="submit" form="person-form" class="a-btn a-btn-primary"><x-lucide-check aria-hidden="true" />{{ __('common.save') }}</button>
                </x-slot:footer>
            @endif
        </x-ui.drawer>
    @endif
</x-layouts.app>
