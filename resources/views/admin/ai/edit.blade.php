{{--
    Console: AI settings (bring your own key and model). Staff read, a super-admin changes them.
    The key is never rendered: only its last 4 characters ($settings->keyHint()).
--}}
@php
    $provider = old('provider', $settings->provider());
    $stored = $settings->stored();
    $storedModel = (string) ($stored['model'] ?? '');
    $storedUrl = (string) ($stored['base_url'] ?? '');
    $keySource = $settings->keySource();
    $suggestions = collect($providers)->map(fn (array $p) => $p['models'])->all();
    $modelsOf = fn (string $key) => $suggestions[$key] ?? [];
    $packPrompts = \App\Ai\Prompts\PackPrompts::fromPack('system') !== null;
@endphp
<x-layouts.app :title="__('ai_settings.title')" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="__('ai_settings.title')" :subtitle="__('ai_settings.lead')">
        <x-slot:eyebrow>{{ __('admin.title') }}</x-slot:eyebrow>
    </x-ui.page-header>

    @if (is_array($test))
        @if ($test['ok'])
            <div class="a-alert a-alert-success" role="status"><x-lucide-circle-check aria-hidden="true" /><span>{{ __('ai_settings.test.ok', ['model' => $test['model'], 'ms' => $test['ms']]) }}</span></div>
        @elseif ($test['error'] === null)
            <div class="a-alert a-alert-warning" role="status"><x-lucide-triangle-alert aria-hidden="true" /><span>{{ __('ai_settings.test.not_configured') }}</span></div>
        @else
            <div class="a-alert a-alert-danger" role="alert"><x-lucide-circle-x aria-hidden="true" />
                <span>{{ __('ai_settings.test.failed', ['model' => $test['model']]) }} <span class="font-mono">{{ $test['error'] }}</span></span></div>
        @endif
    @endif

    <div class="grid items-start gap-4 xl:grid-cols-3">
        {{-- What is in use now --}}
        <section class="a-panel" aria-labelledby="ai-status-heading">
            <div class="a-panel-head"><h2 id="ai-status-heading" class="a-h3 flex items-center gap-2"><x-lucide-sparkles class="size-4 text-brand-blue" aria-hidden="true" />{{ __('ai_settings.status.title') }}</h2></div>
            <dl class="a-panel-body a-dl">
                <dt>{{ __('ai_settings.status.state') }}</dt>
                <dd>
                    @if (! config('redact.ai.enabled'))
                        <span class="a-badge a-badge-neutral">{{ __('ai_settings.status.disabled') }}</span>
                    @elseif ($settings->configured())
                        <span class="a-badge a-badge-success">{{ __('ai_settings.status.ready') }}</span>
                    @else
                        <span class="a-badge a-badge-warning">{{ __('ai_settings.status.not_ready') }}</span>
                    @endif
                </dd>
                <dt>{{ __('ai_settings.status.provider') }}</dt>
                <dd>{{ __('ai_settings.providers.'.$settings->provider()) }}</dd>
                <dt>{{ __('ai_settings.status.model') }}</dt>
                <dd class="font-mono">{{ $settings->model() !== '' ? $settings->model() : '—' }}</dd>
                <dt>{{ __('ai_settings.status.base_url') }}</dt>
                <dd @class(['font-mono break-all' => $settings->baseUrl() !== ''])>{{ $settings->baseUrl() !== '' ? $settings->baseUrl() : __('ai_settings.status.base_url_default') }}</dd>
                <dt>{{ __('ai_settings.status.key') }}</dt>
                <dd>
                    @if ($keySource === 'console')
                        {{ __('ai_settings.status.key_console', ['hint' => $settings->keyHint()]) }}
                    @elseif ($keySource === 'env')
                        {{ __('ai_settings.status.key_env', ['hint' => $settings->keyHint(), 'variable' => $providers[$settings->provider()]['key_env']]) }}
                    @else
                        {{ __('ai_settings.status.key_none') }}
                    @endif
                </dd>
                <dt>{{ __('ai_settings.status.source') }}</dt>
                <dd>{{ __('ai_settings.status.source_'.$settings->source()) }}</dd>
                @if ($updatedAt)
                    <dt>{{ __('ai_settings.status.changed') }}</dt>
                    <dd>{{ __('ai_settings.status.changed_by', ['date' => $updatedAt->isoFormat('D MMMM YYYY, HH:mm'), 'name' => $editor?->name ?? '—']) }}</dd>
                @endif
                <dt>{{ __('ai_settings.status.plans') }}</dt>
                <dd>{{ $selfHosted ? __('ai_settings.status.plans_self') : __('ai_settings.status.plans_saas') }}</dd>
                <dt>{{ __('ai_settings.status.prompts') }}</dt>
                <dd>{{ $packPrompts ? __('ai_settings.status.prompts_pack') : __('ai_settings.status.prompts_generic') }}</dd>
            </dl>
            @if ($canEdit)
                <form method="POST" action="{{ route('admin.ai.test') }}" class="a-panel-foot">
                    @csrf
                    <button type="submit" class="a-btn a-btn-outline a-btn-sm" title="{{ __('ai_settings.test.help') }}"><x-lucide-plug-zap aria-hidden="true" />{{ __('ai_settings.test.button') }}</button>
                </form>
            @endif
        </section>

        {{-- Change (super-admin) --}}
        <form method="POST" action="{{ route('admin.ai.update') }}" class="a-panel xl:col-span-2" aria-labelledby="ai-form-heading"
              x-data="aiSettings" data-suggestions='@json($suggestions)'>
            @csrf
            @method('PUT')
            <div class="a-panel-head"><h2 id="ai-form-heading" class="a-h3">{{ __('ai_settings.form.title') }}</h2></div>
            <fieldset class="a-fieldset a-panel-body a-stack gap-3!" @disabled(! $canEdit)>
                @unless ($canEdit)
                    <p class="a-help">{{ __('ai_settings.read_only') }}</p>
                @endunless
                <div class="a-field">
                    <label for="ai-provider" class="a-label">{{ __('ai_settings.form.provider') }}</label>
                    <select id="ai-provider" name="provider" class="a-select" x-ref="provider" @change="pick">
                        @foreach (array_keys($providers) as $key)
                            <option value="{{ $key }}" @selected($provider === $key)>{{ __('ai_settings.providers.'.$key) }}</option>
                        @endforeach
                    </select>
                    @error('provider')<p class="a-error">{{ $message }}</p>@enderror
                </div>
                <div class="a-field">
                    <label for="ai-model" class="a-label">{{ __('ai_settings.form.model') }}</label>
                    <input id="ai-model" name="model" type="text" class="a-input font-mono" list="ai-models" maxlength="120" autocomplete="off" spellcheck="false"
                           value="{{ old('model', $storedModel) }}" placeholder="{{ $modelsOf($provider)[0] ?? '' }}" x-ref="model"
                           aria-describedby="ai-model-help" @error('model') aria-invalid="true" @enderror>
                    <datalist id="ai-models" x-ref="list">
                        @foreach ($modelsOf($provider) as $model)
                            <option value="{{ $model }}"></option>
                        @endforeach
                    </datalist>
                    @foreach ($providers as $key => $definition)
                        <p id="{{ $provider === $key ? 'ai-model-help' : 'ai-model-help-'.$key }}" class="a-help" data-help-for="{{ $key }}" @if ($provider !== $key) hidden @endif>
                            {{ $definition['models'] === []
                                ? __('ai_settings.form.model_help_compatible')
                                : __('ai_settings.form.model_help', ['models' => implode(', ', $definition['models']), 'default' => $definition['models'][0]]) }}
                        </p>
                    @endforeach
                    @error('model')<p class="a-error">{{ $message }}</p>@enderror
                </div>
                <div class="a-field">
                    <label for="ai-base-url" class="a-label">{{ __('ai_settings.form.base_url') }}</label>
                    <input id="ai-base-url" name="base_url" type="url" class="a-input font-mono" maxlength="300" autocomplete="off" spellcheck="false"
                           value="{{ old('base_url', $storedUrl) }}" placeholder="https://llm.example.com/v1"
                           aria-describedby="ai-base-url-help" @error('base_url') aria-invalid="true" @enderror>
                    <p id="ai-base-url-help" class="a-help">{{ __('ai_settings.form.base_url_help') }}</p>
                    @error('base_url')<p class="a-error">{{ $message }}</p>@enderror
                </div>
                <div class="a-field">
                    <label for="ai-key" class="a-label">{{ __('ai_settings.form.api_key') }}</label>
                    {{-- Never pre-filled: the saved key is not sent back to the browser. --}}
                    <input id="ai-key" name="api_key" type="password" class="a-input font-mono" maxlength="500" autocomplete="new-password" spellcheck="false" value=""
                           placeholder="{{ $keySource === 'console' ? __('ai_settings.form.api_key_saved', ['hint' => $settings->keyHint()]) : '' }}"
                           aria-describedby="ai-key-help" @error('api_key') aria-invalid="true" @enderror>
                    <p id="ai-key-help" class="a-help">{{ __('ai_settings.form.api_key_help') }}</p>
                    @error('api_key')<p class="a-error">{{ $message }}</p>@enderror
                    @if ($keySource === 'console')
                        <label class="a-check">
                            <input type="checkbox" name="clear_key" value="1" @checked(old('clear_key'))>
                            <span>{{ __('ai_settings.form.clear_key') }}</span>
                        </label>
                    @endif
                </div>
            </fieldset>
            @if ($canEdit)
                <div class="a-panel-foot">
                    <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-save aria-hidden="true" />{{ __('ai_settings.form.save') }}</button>
                    @if ($settings->source() === 'console')
                        <button type="submit" name="action" value="reset" class="a-btn a-btn-outline a-btn-sm a-spacer" formnovalidate
                                x-data="confirmSubmit" data-confirm="{{ __('ai_settings.form.reset_confirm') }}" @click="check"><x-lucide-rotate-ccw aria-hidden="true" />{{ __('ai_settings.form.reset') }}</button>
                    @endif
                </div>
            @endif
        </form>
    </div>
</x-layouts.app>
