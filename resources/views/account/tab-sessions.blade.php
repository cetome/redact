{{-- Account, Sessions tab: the devices signed in, and signing out the others. --}}
<section class="a-panel" aria-labelledby="sessions-heading">
    <div class="a-panel-head">
        <div>
            <h2 id="sessions-heading" class="a-h3">{{ __('auth.sessions.heading') }}</h2>
            <p class="a-help mt-0.5">{{ __('auth.sessions.lead') }}@if (\App\Auth\Remember::enabled()) {{ __('auth.sessions.remembered') }}@endif</p>
        </div>
        <form method="POST" action="{{ route('account.sessions.revoke') }}"
            x-data="confirmSubmit" data-confirm="{{ __('auth.sessions.revoke_confirm') }}" x-on:submit="check">
            @csrf
            <button type="submit" class="a-btn a-btn-outline a-btn-sm"><x-lucide-log-out aria-hidden="true" />{{ __('auth.sessions.revoke') }}</button>
        </form>
    </div>
    @if ($sessions->isNotEmpty())
        <div class="overflow-x-auto">
            <table class="a-table">
                <tbody>
                    @foreach ($sessions as $session)
                        <tr @class(['is-current' => $session->current])>
                            <th scope="row">{{ $session->device }}
                                @if ($session->current)
                                    <span class="a-badge a-badge-success ml-1">{{ __('auth.sessions.this_device') }}</span>
                                @endif
                            </th>
                            <td class="a-muted a-mono is-nowrap">{{ $session->ip }}</td>
                            <td class="a-muted">{{ __('auth.sessions.last_active', ['date' => $dateTime($session->last_active)]) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
