{{--
    An organisation's Settings: the "AI model" card (CRAted's AiModelCard). Admins choose what answers
    the organisation's AI buttons, when they may (self-hosted, or Enterprise on the SaaS); members read
    what answers them. The key is never rendered: only its last 4 characters.
--}}
@php
    $defaultLabel = __('org_ai.choices.'.($selfHosted ? 'default_self' : 'default_saas'));
    $label = fn (string $c) => $c === 'default' ? $defaultLabel : __('org_ai.choices.'.$c);
    $editable = $isAdmin && $allowed;
    $current = old('choice', $choice);
    $card = [];
    foreach ($choices as $key => $info) {
        $card[$key] = [
            'model' => $info['model'],
            'keyLabel' => $key === 'default' ? '' : __('org_ai.key_labels.'.$key),
            'keyPlaceholder' => $info['key'] ? __('org_ai.form.key_saved', ['hint' => $info['key']]) : __('org_ai.form.key_placeholder'),
            'modelHelp' => $key === 'openai_compatible' ? __('org_ai.form.model_compatible') : __('org_ai.form.model_empty', ['model' => $info['model']]),
            'modelPlaceholder' => $key === 'openai_compatible' ? __('org_ai.form.model_placeholder') : $info['model'],
        ];
    }
    $now = $active
        ? __('org_ai.now_own', ['choice' => $label($choice), 'model' => app(\App\Ai\OrganisationAi::class)->model($organisation)])
        : ($selfHosted ? ($installReady ? __('org_ai.now_default_self') : __('org_ai.now_none')) : __('org_ai.now_default_saas'));
    $own = $current !== 'default';
    $custom = $current === 'openai_compatible';
@endphp
<x-layouts.app :title="__('org_ai.title').' · '.$organisation->name">
    <x-ui.page-header :title="__('org_ai.title')" :subtitle="__('org_ai.intro')">
        <x-slot:eyebrow>{{ $organisation->name }}</x-slot:eyebrow>
    </x-ui.page-header>

    @include('organisations._tabs', ['organisation' => $organisation, 'active' => 'settings', 'isAdmin' => $isAdmin])

    @if (is_array($test))
        @if ($test['ok'])
            <div class="a-alert a-alert-success" role="status"><x-lucide-circle-check aria-hidden="true" /><span>{{ __('org_ai.test.ok', ['model' => $test['model'], 'ms' => $test['ms']]) }}</span></div>
        @elseif ($test['error'] === null)
            <div class="a-alert a-alert-warning" role="status"><x-lucide-triangle-alert aria-hidden="true" /><span>{{ __('org_ai.test.not_configured') }}</span></div>
        @else
            <div class="a-alert a-alert-danger" role="alert"><x-lucide-circle-x aria-hidden="true" />
                <span>{{ __('org_ai.test.failed', ['model' => $test['model']]) }} <span class="font-mono break-all">{{ $test['error'] }}</span></span></div>
        @endif
    @endif

    <form method="POST" action="{{ route('organisations.settings.ai', $organisation) }}" class="a-panel" aria-labelledby="ai-card-heading"
          x-data="orgAiModel" data-choices='@json($card)' data-testid="ai-model-card">
        @csrf
        @method('PUT')
        <div class="a-panel-head">
            <h2 id="ai-card-heading" class="a-h3 flex items-center gap-2"><x-lucide-sparkles class="size-4 text-brand-blue" aria-hidden="true" />{{ __('org_ai.card') }}</h2>
        </div>
        <div class="a-panel-body a-stack gap-3!">
            <p class="a-help">{{ $selfHosted ? __('org_ai.lead_self') : __('org_ai.lead_saas') }}</p>
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1" data-testid="ai-model-now">
                <span class="a-micro">{{ __('org_ai.now') }}</span>
                <span class="inline-flex min-w-0 items-center gap-1.5 font-semibold break-all"><x-lucide-sparkles class="size-4 shrink-0 text-brand-blue" aria-hidden="true" />{{ $now }}</span>
            </p>
            @unless ($isAdmin)
                <p class="a-help flex items-center gap-1.5" data-testid="ai-model-readonly"><x-lucide-lock class="size-3.5" aria-hidden="true" />{{ __('org_ai.read_only') }}</p>
            @endunless
            @unless ($allowed)
                {{-- Free plan (or a subscription that ended): no AI at all, not even with an own key. --}}
                <p class="a-help flex flex-wrap items-center gap-1.5" data-testid="ai-model-paid-only"><x-lucide-lock class="size-3.5" aria-hidden="true" />{{ __('plans.ai.paid_only') }}
                    @if ($upgradeUrl)
                        <a href="{{ $upgradeUrl }}" class="a-link-btn">{{ __('plans.ai.add_product') }}</a>
                    @endif
                </p>
            @endunless

            <fieldset class="a-fieldset grid gap-4 md:grid-cols-[21rem_1fr] md:items-start" @disabled(! $editable)>
                <div class="a-field">
                    <label for="ai-choice" class="a-label">{{ __('org_ai.form.answered_by') }}</label>
                    <select id="ai-choice" name="choice" class="a-select" x-ref="choice" @change="pick">
                        @foreach (array_keys($choices) as $key)
                            @if ($key === 'default' || $allowed || $key === $choice)
                                <option value="{{ $key }}" @selected($current === $key)>{{ $label($key) }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('choice')<p class="a-error">{{ $message }}</p>@enderror
                </div>

                <p class="a-help md:pt-7" data-when="default" @if ($own) hidden @endif>{{ __('org_ai.form.nothing') }}</p>

                <div class="grid gap-4 sm:grid-cols-2" data-when="own" @unless ($own) hidden @endunless>
                    <div class="a-field sm:col-span-2" data-when="custom" @unless ($custom) hidden @endunless>
                        <label for="ai-endpoint" class="a-label">{{ __('org_ai.form.endpoint') }}</label>
                        <input id="ai-endpoint" name="endpoint" type="url" class="a-input font-mono" maxlength="300" autocomplete="off" spellcheck="false"
                               value="{{ old('endpoint', $endpoint) }}" placeholder="https://ai.example.com/v1" aria-describedby="ai-endpoint-help" @error('endpoint') aria-invalid="true" @enderror>
                        <p id="ai-endpoint-help" class="a-help">{{ $selfHosted ? __('org_ai.form.endpoint_help_self') : __('org_ai.form.endpoint_help_saas') }}</p>
                        @error('endpoint')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="a-field">
                        <label for="ai-key" class="a-label" x-ref="keyLabel">{{ $own ? $card[$current]['keyLabel'] : __('org_ai.form.key') }}</label>
                        {{-- Never pre-filled: the server never sends a key back. --}}
                        <input id="ai-key" name="api_key" type="password" class="a-input font-mono" maxlength="500" value=""
                               autocomplete="new-password" spellcheck="false" autocapitalize="off" data-lpignore="true" data-1p-ignore data-form-type="other"
                               placeholder="{{ $card[$current]['keyPlaceholder'] ?? '' }}" x-ref="key" aria-describedby="ai-key-help" @error('api_key') aria-invalid="true" @enderror>
                        <p id="ai-key-help" class="a-help">{{ __('org_ai.form.key_help') }}
                            @if ($keySetAt && ($choices[$current]['key'] ?? null))
                                <span data-testid="ai-key-state" data-key-for="{{ $current }}">{{ __('org_ai.form.key_set', ['date' => $keySetAt->isoFormat('D MMMM YYYY')]) }}</span>
                            @endif
                        </p>
                        @error('api_key')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="a-field">
                        <label for="ai-model" class="a-label">{{ __('org_ai.form.model') }}</label>
                        <input id="ai-model" name="model" type="text" class="a-input font-mono" maxlength="120" autocomplete="off" spellcheck="false"
                               value="{{ old('model', $model) }}" placeholder="{{ $card[$current]['modelPlaceholder'] ?? '' }}" x-ref="model"
                               aria-describedby="ai-model-help" @error('model') aria-invalid="true" @enderror>
                        <p id="ai-model-help" class="a-help" x-ref="modelHelp">{{ $card[$current]['modelHelp'] ?? '' }}</p>
                        @error('model')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2" data-when="custom" @unless ($custom) hidden @endunless>
                        <input type="hidden" name="reads_pictures" value="0">
                        <label class="a-check">
                            <input type="checkbox" name="reads_pictures" value="1" aria-describedby="ai-pictures-help" @checked(old('reads_pictures', $readsPictures))>
                            <span>{{ __('org_ai.form.pictures') }}</span>
                        </label>
                        <p id="ai-pictures-help" class="a-help">{{ __('org_ai.form.pictures_help') }}</p>
                    </div>
                </div>
            </fieldset>
            <p class="a-help">{{ __('org_ai.same_rules') }}</p>
        </div>
        @if ($editable)
            <div class="a-panel-foot">
                <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-save aria-hidden="true" />{{ __('org_ai.form.save') }}</button>
                <button type="submit" form="ai-test-form" class="a-btn a-btn-outline a-btn-sm a-spacer" title="{{ __('org_ai.form.test_help') }}"><x-lucide-plug-zap aria-hidden="true" />{{ __('org_ai.form.test') }}</button>
            </div>
        @endif
    </form>
    @if ($editable)
        <form id="ai-test-form" method="POST" action="{{ route('organisations.settings.ai.test', $organisation) }}" hidden>@csrf</form>
    @endif
</x-layouts.app>
