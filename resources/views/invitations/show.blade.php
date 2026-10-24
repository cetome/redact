<x-layouts.app :title="__('team.accept_title')">
    <div class="mx-auto max-w-2xl">
        <x-ui.page-header :title="__('team.accept_title')" />
        @if (! $invitation)
            <div class="a-alert a-alert-warning"><x-lucide-triangle-alert aria-hidden="true" /><span>{{ __('team.invalid') }}</span></div>
        @elseif (! $matches)
            <div class="a-alert a-alert-warning"><x-lucide-triangle-alert aria-hidden="true" /><span>{{ __('team.wrong_account') }}</span></div>
        @elseif ($invitation->isForOrganisation())
            {{-- Organisation invitation: the organisation, the role in it, the products offered with it. --}}
            @php $orgRole = $invitation->organisation_role === 'admin' ? 'admin' : 'member'; @endphp
            <section class="a-panel">
                <div class="a-panel-body a-stack gap-2!">
                    <p class="text-base">{{ __('orgteam.accept.intro', ['organisation' => $invitation->organisation->name, 'role' => __('orgteam.role.'.$orgRole)]) }}</p>
                    <p class="a-help">{{ __('orgteam.role_help.'.$orgRole) }}</p>
                    @if ($offered->isNotEmpty())
                        <ul class="a-chips" aria-label="{{ __('orgteam.products') }}">
                            @foreach ($offered as $child)
                                <li class="a-chip">{{ $child->product->name }} <span class="a-muted">· {{ $child->role->label() }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div class="a-panel-foot flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ $acceptUrl }}">
                        @csrf
                        <button type="submit" class="a-btn a-btn-primary"><x-lucide-check aria-hidden="true" />{{ __('orgteam.accept.button', ['organisation' => $invitation->organisation->name]) }}</button>
                    </form>
                    <form method="POST" action="{{ route('invitations.decline', $invitation) }}">
                        @csrf
                        <button type="submit" class="a-btn a-btn-ghost">{{ __('orgteam.decline') }}</button>
                    </form>
                </div>
            </section>
        @else
            <section class="a-panel">
                <div class="a-panel-body">
                    <p class="text-base">{{ __('team.accept_intro', ['product' => $invitation->product->name, 'role' => $invitation->role->label()]) }}</p>
                    <p class="a-help mt-2">{{ $invitation->role->help() }}</p>
                </div>
                <form method="POST" action="{{ $acceptUrl }}" class="a-panel-foot">
                    @csrf
                    <button type="submit" class="a-btn a-btn-primary"><x-lucide-check aria-hidden="true" />{{ __('team.accept_button') }}</button>
                </form>
            </section>
        @endif
    </div>
</x-layouts.app>
