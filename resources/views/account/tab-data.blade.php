{{-- Account, Your data tab: what we hold, and the JSON export (GDPR art. 15 and 20). --}}
<div class="a-account a-account-split">
    <section class="a-panel" aria-labelledby="data-heading">
        <div class="a-panel-head"><h2 id="data-heading" class="a-h3">{{ __('auth.account.held') }}</h2></div>
        <div class="a-panel-body">
            <dl class="a-dl">
                <dt>{{ __('auth.fields.name') }}</dt>
                <dd>{{ $user->name }}</dd>
                <dt>{{ __('auth.account.email') }}</dt>
                <dd>{{ $user->email }}</dd>
                <dt>{{ __('auth.account.created') }}</dt>
                <dd>{{ $date($user->created_at) }}</dd>
                <dt>{{ __('auth.account.last_login') }}</dt>
                <dd>{{ $user->last_login_at ? $dateTime($user->last_login_at) : __('auth.account.never') }}</dd>
                <dt>{{ __('auth.account.terms') }}</dt>
                <dd>{{ $user->terms_accepted_at ? $date($user->terms_accepted_at) : __('auth.account.never') }}</dd>
                <dt>{{ __('auth.passkeys.heading') }}</dt>
                <dd>{{ $passkeys->count() }}</dd>
                <dt>{{ __('auth.account.organisations') }}</dt>
                <dd>{{ $organisations->pluck('name')->implode(', ') ?: __('common.none') }}</dd>
            </dl>
        </div>
    </section>

    <section class="a-panel" aria-labelledby="export-heading">
        <div class="a-panel-head"><h2 id="export-heading" class="a-h3">{{ __('auth.account.export') }}</h2></div>
        <div class="a-panel-body a-stack gap-3!">
            <p class="text-sm a-muted">{{ __('auth.account.export_help') }}</p>
            <div><a href="{{ route('account.export') }}" class="a-btn a-btn-outline"><x-lucide-download aria-hidden="true" />{{ __('auth.account.export') }}</a></div>
        </div>
    </section>
</div>
