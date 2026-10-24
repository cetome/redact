{{-- Account, Security tab: passkeys (main) and the e-mail address used to sign in (aside). --}}
<div class="a-account a-account-split">
    <section class="a-panel" aria-labelledby="passkeys-heading">
        <div class="a-panel-head">
            <div>
                <h2 id="passkeys-heading" class="a-h3">{{ __('auth.passkeys.heading') }}</h2>
                <p class="a-help mt-0.5">{{ __('auth.passkeys.lead') }}</p>
            </div>
        </div>
        @if ($passkeys->isEmpty())
            <div class="a-panel-body pb-0!">
                <div class="a-alert a-alert-warning mb-0!" role="note"><x-lucide-key-round aria-hidden="true" /><span>{{ __('auth.passkeys.none') }}</span></div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="a-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('auth.passkeys.name') }}</th>
                            <th scope="col">{{ __('auth.passkeys.created') }}</th>
                            <th scope="col">{{ __('auth.passkeys.last_used') }}</th>
                            <th scope="col" class="is-shrink"><span class="a-sr-only">{{ __('common.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($passkeys as $passkey)
                            <tr>
                                <td>
                                    <form method="POST" action="{{ route('account.passkeys.update', ['key' => $passkey->id]) }}" class="flex items-center gap-2">
                                        @csrf
                                        @method('PATCH')
                                        <label for="passkey-{{ $passkey->id }}" class="a-sr-only">{{ __('auth.passkeys.name') }}</label>
                                        <input id="passkey-{{ $passkey->id }}" name="name" type="text" class="a-input a-input-sm max-w-64" required maxlength="60" value="{{ $passkey->name }}">
                                        <button type="submit" class="a-btn a-btn-ghost a-btn-sm"><x-lucide-pencil aria-hidden="true" />{{ __('auth.passkeys.rename') }}</button>
                                    </form>
                                    @if ($passkey->authenticator)
                                        <span class="a-row-sub">{{ $passkey->authenticator }}</span>
                                    @endif
                                </td>
                                <td class="is-nowrap">{{ $date($passkey->created_at) }}</td>
                                <td class="is-nowrap">{{ $date($passkey->last_used_at) }}</td>
                                <td>
                                    @if ($recent)
                                        <form method="POST" action="{{ route('account.passkeys.destroy', ['key' => $passkey->id]) }}"
                                            x-data="confirmSubmit" data-confirm="{{ __('auth.passkeys.remove_confirm') }}" x-on:submit="check">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="a-btn a-btn-danger a-btn-sm"><x-lucide-trash-2 aria-hidden="true" />{{ __('common.remove') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="a-panel-body">
            @if ($recent)
                <div class="a-account-addkey" x-data="passkeyRegister"
                    data-default-name="{{ \App\Auth\Sessions::device((string) request()->userAgent()) }}"
                    data-redirect="{{ \App\Http\Controllers\AccountController::tabUrl('security') }}"
                    data-error="{{ __('auth.passkeys.error') }}"
                    data-exists="{{ __('auth.passkeys.exists') }}">
                    <div class="a-field">
                        <label for="new-passkey-name" class="a-label">{{ __('auth.passkeys.new_name') }}</label>
                        <div class="a-account-addkey-row">
                            <input id="new-passkey-name" type="text" class="a-input" maxlength="60" x-model="name" aria-describedby="new-passkey-help">
                            <button type="button" class="a-btn a-btn-primary" x-on:click="add" x-bind:disabled="busy"><x-lucide-key-round aria-hidden="true" />{{ __('auth.passkeys.add') }}</button>
                        </div>
                        <p id="new-passkey-help" class="a-help">{{ __('auth.passkeys.name_help') }}</p>
                    </div>
                    <p class="a-error" role="alert" x-show="error" x-text="error"></p>
                    <p class="a-help" x-show="unsupported">{{ __('auth.passkeys.unsupported') }}</p>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-3">
                    <p class="a-help">{{ __('auth.passkeys.confirm_to_manage') }}</p>
                    <a href="{{ route('account.reconfirm', ['tab' => 'security']) }}" class="a-btn a-btn-outline"><x-lucide-shield-check aria-hidden="true" />{{ __('auth.account.confirm_button') }}</a>
                </div>
            @endif
        </div>
    </section>

    <section class="a-panel" aria-labelledby="email-heading">
        <div class="a-panel-head"><h2 id="email-heading" class="a-h3">{{ __('auth.account.email') }}</h2></div>
        <div class="a-panel-body a-stack gap-3!">
            <p class="text-sm">{{ __('auth.account.email_current', ['email' => $user->email]) }}</p>
            @if ($emailChangePending)
                <p><a href="{{ route('account.email.confirm') }}" class="a-link-btn">{{ __('auth.email_change.enter_code') }}</a></p>
            @endif
            @if ($recent)
                <form method="POST" action="{{ route('account.email') }}" class="a-stack gap-3!">
                    @csrf
                    <div class="a-field">
                        <label for="new-email" class="a-label">{{ __('auth.fields.new_email') }}</label>
                        <input id="new-email" name="email" type="email" class="a-input" required maxlength="191" autocomplete="email" value="{{ old('email') }}"
                            @error('email') aria-invalid="true" aria-describedby="new-email-error" @else aria-describedby="new-email-help" @enderror>
                        @error('email')
                            <p id="new-email-error" class="a-error">{{ $message }}</p>
                        @else
                            <p id="new-email-help" class="a-help">{{ __('auth.email_change.help') }}</p>
                        @enderror
                    </div>
                    <div><button type="submit" class="a-btn a-btn-outline"><x-lucide-send aria-hidden="true" />{{ __('auth.email_change.send') }}</button></div>
                </form>
            @else
                <p class="a-help">{{ __('auth.account.confirm_to_change') }}</p>
                <div><a href="{{ route('account.reconfirm', ['tab' => 'security']) }}" class="a-btn a-btn-outline"><x-lucide-shield-check aria-hidden="true" />{{ __('auth.account.confirm_button') }}</a></div>
            @endif
        </div>
    </section>
</div>
