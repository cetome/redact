<x-layouts.app :title="__('team.title')" :product="$product">
    <x-read-only-notice :access="$access" />

    <x-ui.page-header :title="__('team.title')" :subtitle="__('team.intro', ['product' => $product->name, 'organisation' => $product->organisation->name])">
        <x-slot:eyebrow><a href="{{ $product->homeUrl() }}">{{ $product->name }}</a></x-slot:eyebrow>
        @if (($access->orgAdmin && ! auth()->user()->is_guest) || $candidates->isNotEmpty())
            <x-slot:actions>
                @if ($candidates->isNotEmpty())
                    <a href="{{ route('team.transfer.show', $product) }}" class="a-btn a-btn-outline" data-dialog-open="transfer"><x-lucide-arrow-right-left aria-hidden="true" />{{ __('transfer.button') }}</a>
                @endif
                @if ($access->orgAdmin && ! auth()->user()->is_guest)
                    {{-- Organisation admins manage everyone (organisation role, several products at once) on the organisation's Team page. --}}
                    <a href="{{ route('organisations.team', $product->organisation) }}" class="a-btn a-btn-ghost"><x-lucide-users aria-hidden="true" />{{ __('orgteam.manage_all', ['organisation' => $product->organisation->name]) }}</a>
                @endif
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="a-grid-main max-[80rem]:grid-cols-1">
        <div class="a-stack">
            <section class="a-table-wrap self-start" aria-label="{{ __('team.title') }}">
                <table class="a-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('team.member') }}</th>
                            <th scope="col">{{ __('team.role') }}</th>
                            <th scope="col">{{ __('team.kind') }}</th>
                            <th scope="col">{{ __('team.until') }}</th>
                            <th scope="col" class="is-shrink"><span class="a-sr-only">{{ __('common.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row">
                                {{ $product->owner->name }}
                                @unless ($product->owner->is_guest)<span class="a-row-sub">{{ $product->owner->email }}</span>@endunless
                            </th>
                            <td><span class="a-badge" title="{{ __('roles.owner.help') }}">{{ __('roles.owner.label') }}</span></td>
                            <td><span class="a-badge a-badge-neutral">{{ __('roles.internal') }}</span></td>
                            <td class="a-muted">{{ __('team.no_limit') }}</td>
                            <td></td>
                        </tr>
                        @foreach ($product->members as $member)
                            <tr>
                                <th scope="row">
                                    {{ $member->user->name }}
                                    <span class="a-row-sub">{{ $member->user->email }}</span>
                                </th>
                                <td>
                                    @if ($access->canManage())
                                        <form method="POST" action="{{ route('team.update', [$product, $member]) }}" id="member-{{ $member->id }}">
                                            @csrf
                                            @method('PATCH')
                                            <select name="role" class="a-select a-input-sm w-auto!" aria-label="{{ __('team.role') }}">
                                                @foreach ($roles as $role)
                                                    <option value="{{ $role->value }}" @selected($member->role === $role)>{{ $role->label() }}</option>
                                                @endforeach
                                            </select>
                                        </form>
                                    @else
                                        <span class="a-badge a-badge-neutral" title="{{ $member->role->help() }}">{{ $member->role->label() }}</span>
                                    @endif
                                </td>
                                <td>
                                    <span @class(['a-badge', 'a-badge-warning' => $member->external, 'a-badge-neutral' => ! $member->external])
                                          title="{{ $member->external ? __('roles.external_help') : __('roles.internal_help') }}">
                                        {{ $member->external ? __('roles.external') : __('roles.internal') }}
                                    </span>
                                </td>
                                <td>
                                    @if ($access->canManage())
                                        <input type="date" name="expires_at" form="member-{{ $member->id }}" class="a-input a-input-sm w-auto!" value="{{ $member->expires_at?->toDateString() }}" aria-label="{{ __('team.until') }}">
                                    @else
                                        <span class="a-muted">{{ $member->expires_at?->isoFormat('D MMMM YYYY') ?? __('team.no_limit') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($access->canManage())
                                            <button class="a-btn a-btn-outline a-btn-sm" type="submit" form="member-{{ $member->id }}">{{ __('common.save') }}</button>
                                        @endif
                                        @if ($access->canManage() || $member->user_id === auth()->id())
                                            <form method="POST" action="{{ route('team.destroy', [$product, $member]) }}"
                                                  x-data="confirmSubmit" data-confirm="{{ __('common.confirm_delete') }}" @submit="check">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="a-btn a-btn-ghost a-btn-sm">
                                                    {{ $member->user_id === auth()->id() ? __('team.leave') : __('common.remove') }}
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($product->members->isEmpty())
                    <p class="a-help border-t border-line px-3 py-2.5">{{ __('team.alone') }}</p>
                @endif
            </section>

            @if ($access->canManage() && $invitations->isNotEmpty())
                <section aria-labelledby="pending-title">
                    <div class="a-section-head"><h2 class="a-h2" id="pending-title">{{ __('team.pending') }}</h2></div>
                    <div class="a-table-wrap">
                        <table class="a-table a-table-compact">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('team.email') }}</th>
                                    <th scope="col">{{ __('team.role') }}</th>
                                    <th scope="col">{{ __('team.expires_col') }}</th>
                                    <th scope="col" class="is-shrink"><span class="a-sr-only">{{ __('common.actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($invitations as $invitation)
                                    <tr>
                                        <td class="font-semibold">{{ $invitation->email }}</td>
                                        <td><span class="a-badge a-badge-neutral">{{ $invitation->role->label() }}</span></td>
                                        <td class="a-muted">{{ $invitation->expires_at->isoFormat('D MMMM YYYY') }}</td>
                                        <td>
                                            <form method="POST" action="{{ route('team.revoke', [$product, $invitation]) }}">
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
        </div>

        @if (auth()->user()->is_guest)
            @include('guest._team-notice')
        @elseif ($access->canManage())
            <aside class="self-start">
                @if ($teamFull)
                    {{-- The free product's team is full (App\Billing\Plan): say so instead of a form that would be refused. --}}
                    <section class="a-panel" aria-labelledby="team-plan-title">
                        <div class="a-panel-head"><h2 class="a-h3" id="team-plan-title">{{ __('team.invite') }}</h2></div>
                        <div class="a-panel-body a-stack gap-2!">
                            <p class="a-plan-hint"><x-lucide-users aria-hidden="true" /><span>{{ trans_choice('plans.refusal.team', $teamLimit, ['count' => $teamLimit]) }}</span></p>
                            <a href="{{ \App\Billing\Plan::contactUrl() }}" class="a-btn a-btn-outline a-btn-sm self-start" rel="noopener"><x-lucide-message-circle aria-hidden="true" />{{ __('plans.talk') }}</a>
                        </div>
                    </section>
                @else
                <form method="POST" action="{{ route('team.invite', $product) }}" class="a-panel">
                    @csrf
                    <div class="a-panel-head"><h2 class="a-h3">{{ __('team.invite') }}</h2></div>
                    <div class="a-panel-body a-stack gap-3!">
                        <p class="a-help">{{ __('team.invite_help') }}</p>
                        @if ($teamLimit !== null)
                            <p class="a-plan-hint">
                                <x-lucide-users aria-hidden="true" />
                                <span>{{ $suggestReviewer ? trans_choice('plans.team.suggest_reviewer', $teamLimit, ['count' => $teamLimit]) : trans_choice('plans.team.seats', $teamLimit, ['used' => $teamCount, 'count' => $teamLimit]) }}</span>
                            </p>
                        @endif
                        <div class="a-field">
                            <label class="a-label" for="email">{{ __('team.email') }}</label>
                            <input id="email" type="email" name="email" class="a-input" required maxlength="191" autocomplete="off" value="{{ old('email') }}" @error('email') aria-invalid="true" @enderror>
                            @error('email')<p class="a-error">{{ $message }}</p>@enderror
                        </div>
                        <fieldset class="a-fieldset">
                            <legend class="a-label">{{ __('team.role') }}</legend>
                            <div class="a-stack gap-2!">
                                @php
                                    // /team/reviewer, /team/assessor start with that role; a free product without a reviewer suggests one.
                                    $startRole = match (true) {
                                        $panel === 'assessor' => \App\Enums\ProductRole::Assessor,
                                        $panel === 'reviewer' || $suggestReviewer => \App\Enums\ProductRole::Reviewer,
                                        default => \App\Enums\ProductRole::Contributor,
                                    };
                                @endphp
                                @foreach ($roles as $role)
                                    <label class="a-toggle-card">
                                        <input type="radio" name="role" value="{{ $role->value }}" @checked(old('role', $startRole->value) === $role->value)>
                                        <span>
                                            <strong class="block">{{ $role->label() }}</strong>
                                            <span class="a-toggle-card-text">{{ $role->help() }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    </div>
                    <div class="a-panel-foot">
                        <button type="submit" class="a-btn a-btn-primary"><x-lucide-send aria-hidden="true" />{{ __('team.send') }}</button>
                    </div>
                </form>
                @endif
            </aside>
        @endif
    </div>

    @if ($candidates->isNotEmpty())
        {{-- Transfer ownership (App\Actions\TransferOwnership): a member of the organisation; the previous owner stays or leaves. --}}
        <x-ui.drawer id="transfer" :modal="true" :open="$transferring" :close-url="route('team.index', $product)" :title="__('transfer.title', ['product' => $product->name])">
            <form method="POST" action="{{ route('team.transfer', $product) }}" id="transfer-form" class="a-stack gap-4!"
                  x-data="confirmSubmit" data-confirm="{{ __('transfer.confirm', ['product' => $product->name]) }}" x-on:submit="check">
                @csrf
                <div class="a-field">
                    <label class="a-label" for="transfer-owner">{{ __('transfer.new_owner') }}</label>
                    <select id="transfer-owner" name="owner_id" class="a-select" required data-autofocus>
                        <option value="">{{ __('orgteam.choose') }}</option>
                        @foreach ($candidates as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->email }}</option>
                        @endforeach
                    </select>
                </div>
                <fieldset class="a-fieldset">
                    <legend class="a-label">{{ __('transfer.previous', ['name' => $product->owner->name]) }}</legend>
                    <div class="a-orgteam-roles is-three">
                        @foreach ([\App\Enums\ProductRole::Contributor->value, \App\Enums\ProductRole::Reviewer->value, \App\Actions\TransferOwnership::LEAVE] as $choice)
                            <label class="a-toggle-card">
                                <input type="radio" name="previous" value="{{ $choice }}" @checked($choice === \App\Enums\ProductRole::Contributor->value)>
                                <span><strong class="block">{{ __('transfer.previous_as.'.$choice) }}</strong></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </form>
            <x-slot:footer>
                <span class="a-spacer"></span>
                <a href="{{ route('team.index', $product) }}" class="a-btn a-btn-ghost" data-dialog-close>{{ __('common.cancel') }}</a>
                <button type="submit" form="transfer-form" class="a-btn a-btn-primary"><x-lucide-arrow-right-left aria-hidden="true" />{{ __('transfer.submit') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</x-layouts.app>
