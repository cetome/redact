{{--
    Console: Stripe (keys, the webhook, the way to the plans and prices), sandbox first, then live. Staff read, a
    super-admin changes them. Secrets are never rendered: only their last 4 characters.
--}}
@php
    $mode = $settings->mode();
    $hasSecret = $settings->value('secret') !== '';
    $stored = $settings->stored();
    $webhookUrl = \App\Billing\StripeSettings::webhookUrl();
    $hint = function (string $name, string $variable) use ($settings) {
        return match ($settings->source($name)) {
            'console' => __('stripe_settings.status.hint_console', ['hint' => $settings->hint($name)]),
            'env' => __('stripe_settings.status.hint_env', ['hint' => $settings->hint($name), 'variable' => $variable]),
            default => __('stripe_settings.status.none'),
        };
    };
@endphp
<x-layouts.app :title="__('stripe_settings.title')" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="__('stripe_settings.title')" :subtitle="__('stripe_settings.lead')">
        <x-slot:eyebrow>{{ __('admin.title') }}</x-slot:eyebrow>
    </x-ui.page-header>

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
        {{-- What is in use now: first on phones, a narrow column on the right on wide screens. --}}
        <section class="a-panel xl:sticky xl:top-4 xl:order-last" aria-labelledby="stripe-status-heading">
            <div class="a-panel-head"><h2 id="stripe-status-heading" class="a-h3 flex items-center gap-2"><x-lucide-credit-card class="size-4 text-brand-blue" aria-hidden="true" />{{ __('stripe_settings.status.title') }}</h2></div>
            <dl class="a-panel-body a-dl">
                <dt>{{ __('stripe_settings.status.state') }}</dt>
                <dd>
                    @if ($billing)
                        <span class="a-badge a-badge-success">{{ __('stripe_settings.status.ready') }}</span>
                    @else
                        <span class="a-badge a-badge-neutral">{{ __('stripe_settings.status.not_ready') }}</span> {{ __('stripe_settings.status.not_ready_help') }}
                    @endif
                </dd>
                <dt>{{ __('stripe_settings.status.mode') }}</dt>
                <dd>
                    @if ($mode === 'live')
                        <span class="a-badge a-badge-success">{{ __('stripe_settings.status.mode_live') }}</span>
                    @elseif ($mode === 'test')
                        <span class="a-badge a-badge-warning">{{ __('stripe_settings.status.mode_test') }}</span>
                    @else
                        {{ __('stripe_settings.status.mode_none') }}
                    @endif
                </dd>
                <dt>{{ __('stripe_settings.status.key') }}</dt>
                <dd>{{ $hint('key', 'STRIPE_KEY') }}</dd>
                <dt>{{ __('stripe_settings.status.secret') }}</dt>
                <dd>{{ $hint('secret', 'STRIPE_SECRET') }}</dd>
                <dt>{{ __('stripe_settings.status.prices') }}</dt>
                <dd><a href="{{ route('admin.plans.index') }}" class="a-link">{{ trans_choice('stripe_settings.status.prices_count', $prices, ['count' => $prices]) }}</a></dd>
                <dt>{{ __('stripe_settings.status.webhook_secret') }}</dt>
                <dd>{{ $hint('webhook_secret', 'STRIPE_WEBHOOK_SECRET') }}</dd>
                <dt>{{ __('stripe_settings.status.customers') }}</dt>
                <dd>{{ $linked }}</dd>
                <dt>{{ __('stripe_settings.status.plans') }}</dt>
                <dd>{{ $selfHosted ? __('stripe_settings.status.plans_self') : __('stripe_settings.status.plans_saas') }}</dd>
                @if ($updatedAt)
                    <dt>{{ __('stripe_settings.status.changed') }}</dt>
                    <dd>{{ __('stripe_settings.status.changed_by', ['date' => $updatedAt->isoFormat('D MMMM YYYY, HH:mm'), 'name' => $editor?->name ?? '—']) }}</dd>
                @endif
            </dl>
        </section>

        <div class="a-stack gap-4!">
            @unless ($canEdit)
                <p class="a-help">{{ __('stripe_settings.read_only') }}</p>
            @endunless

            {{-- 1. Keys (and a webhook secret made in the dashboard) --}}
            <form method="POST" action="{{ route('admin.stripe.update') }}" class="a-panel" aria-labelledby="stripe-keys-heading">
                @csrf
                @method('PUT')
                <div class="a-panel-head"><h2 id="stripe-keys-heading" class="a-h3">{{ __('stripe_settings.keys.title') }}</h2></div>
                <fieldset class="a-fieldset a-panel-body a-stack gap-3!" @disabled(! $canEdit)>
                    <p class="a-help">{{ __('stripe_settings.keys.help') }}</p>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="a-field">
                            <label for="stripe-key" class="a-label">{{ __('stripe_settings.keys.key') }}</label>
                            <input id="stripe-key" name="key" type="text" class="a-input font-mono" maxlength="255" autocomplete="off" spellcheck="false"
                                   value="{{ old('key') }}" placeholder="{{ $settings->hint('key') ? __('stripe_settings.keys.saved', ['hint' => $settings->hint('key')]) : 'pk_test_…' }}"
                                   @error('key') aria-invalid="true" aria-describedby="stripe-key-error" @enderror>
                            @error('key')<p id="stripe-key-error" class="a-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="a-field">
                            <label for="stripe-secret" class="a-label">{{ __('stripe_settings.keys.secret') }}</label>
                            {{-- Never pre-filled: the saved key is not sent back to the browser. --}}
                            <input id="stripe-secret" name="secret" type="password" class="a-input font-mono" maxlength="255" autocomplete="new-password" spellcheck="false" value=""
                                   placeholder="{{ $hasSecret ? __('stripe_settings.keys.saved', ['hint' => $settings->hint('secret')]) : 'sk_test_…' }}"
                                   aria-describedby="stripe-secret-help" @error('secret') aria-invalid="true" @enderror>
                            @error('secret')<p class="a-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <p id="stripe-secret-help" class="a-help">{{ __('stripe_settings.keys.secret_help') }}</p>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="a-field">
                            <label for="stripe-whsec" class="a-label">{{ __('stripe_settings.keys.webhook_secret') }}</label>
                            <input id="stripe-whsec" name="webhook_secret" type="password" class="a-input font-mono" maxlength="255" autocomplete="new-password" spellcheck="false" value=""
                                   placeholder="whsec_…" aria-describedby="stripe-whsec-help" @error('webhook_secret') aria-invalid="true" @enderror>
                            <p id="stripe-whsec-help" class="a-help">{{ __('stripe_settings.keys.webhook_secret_help') }}</p>
                            @error('webhook_secret')<p class="a-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    @if ($linked > 0)
                        <div class="a-field">
                            <label class="a-check">
                                <input type="checkbox" name="forget_customers" value="1" @checked(old('forget_customers')) aria-describedby="stripe-forget-help">
                                <span>{{ trans_choice('stripe_settings.keys.forget', $linked, ['count' => $linked]) }}</span>
                            </label>
                            <p id="stripe-forget-help" class="a-help">{{ __('stripe_settings.keys.forget_help') }}</p>
                            @error('forget_customers')<p class="a-error">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </fieldset>
                @if ($canEdit)
                    <div class="a-panel-foot">
                        <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-save aria-hidden="true" />{{ __('stripe_settings.keys.save') }}</button>
                        @if ($stored !== [])
                            <button type="submit" name="action" value="reset" class="a-btn a-btn-outline a-btn-sm a-spacer" formnovalidate
                                    x-data="confirmSubmit" data-confirm="{{ __('stripe_settings.keys.reset_confirm') }}" @click="check"><x-lucide-rotate-ccw aria-hidden="true" />{{ __('stripe_settings.keys.reset') }}</button>
                        @endif
                    </div>
                @endif
            </form>

            <div class="a-stack gap-4!">
                {{-- 2. Webhook --}}
                <form method="POST" action="{{ route('admin.stripe.webhook') }}" class="a-panel" aria-labelledby="stripe-webhook-heading">
                    @csrf
                    <div class="a-panel-head"><h2 id="stripe-webhook-heading" class="a-h3">{{ __('stripe_settings.webhook.title') }}</h2></div>
                    <div class="a-panel-body a-stack gap-3!">
                        <p class="a-help">{{ __('stripe_settings.webhook.help') }}</p>
                        <div class="a-field">
                            <label for="stripe-webhook-url" class="a-label">{{ __('stripe_settings.webhook.url') }}</label>
                            <input id="stripe-webhook-url" type="text" class="a-input font-mono" value="{{ $webhookUrl }}" readonly
                                   @unless (str_starts_with($webhookUrl, 'https://')) aria-describedby="stripe-webhook-public" @endunless>
                            @unless (str_starts_with($webhookUrl, 'https://'))
                                <p id="stripe-webhook-public" class="a-help">{{ __('stripe_settings.webhook.public') }}</p>
                            @endunless
                        </div>
                        <div>
                            <p class="a-label">{{ __('stripe_settings.webhook.events') }}</p>
                            <p class="a-help font-mono break-words">{{ implode(', ', \App\Billing\StripeSettings::webhookEvents()) }}</p>
                        </div>
                        @error('webhook')<p class="a-error">{{ $message }}</p>@enderror
                        @if ($canEdit && ! $hasSecret)
                            <p class="a-help">{{ __('stripe_settings.webhook.needs_keys') }}</p>
                        @endif
                    </div>
                    @if ($canEdit && $hasSecret)
                        <div class="a-panel-foot">
                            <button type="submit" @class(['a-btn a-btn-sm', 'a-btn-primary' => $settings->value('webhook_secret') === '', 'a-btn-outline' => $settings->value('webhook_secret') !== ''])
                                    x-data="confirmSubmit" data-confirm="{{ __('stripe_settings.webhook.create_confirm') }}" @click="check">
                                <x-lucide-webhook aria-hidden="true" />{{ $settings->value('webhook_secret') === '' ? __('stripe_settings.webhook.create') : __('stripe_settings.webhook.recreate') }}</button>
                        </div>
                    @endif
                </form>

                {{-- 3. Plans and prices --}}
                <section class="a-panel" aria-labelledby="stripe-plans-heading">
                    <div class="a-panel-head"><h2 id="stripe-plans-heading" class="a-h3">{{ __('stripe_settings.plans.title') }}</h2></div>
                    <div class="a-panel-body a-stack gap-3!">
                        <p class="a-help">{{ __('stripe_settings.plans.help') }}</p>
                    </div>
                    <div class="a-panel-foot">
                        <a href="{{ route('admin.plans.index') }}" class="a-btn a-btn-outline a-btn-sm"><x-lucide-tags aria-hidden="true" />{{ __('stripe_settings.plans.open') }}</a>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-layouts.app>
