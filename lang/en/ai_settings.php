<?php

// Console: AI settings (App\Http\Controllers\Admin\AiSettingsController). Bring your own key and model.
return [
    'nav' => 'AI settings',
    'title' => 'AI settings',
    'lead' => 'The AI provider, model and key this install uses. Settings saved here override the .env values.',
    'read_only' => 'Only a super-admin can change these settings.',

    'status' => [
        'title' => 'In use now',
        'state' => 'State',
        'ready' => 'Ready',
        'not_ready' => 'Not configured',
        'disabled' => 'Switched off (REDACT_AI_ENABLED=false)',
        'provider' => 'Provider',
        'model' => 'Model',
        'base_url' => 'API base URL',
        'base_url_default' => 'The provider’s own',
        'key' => 'API key',
        'key_none' => 'No key',
        'key_console' => ':hint, saved here',
        'key_env' => ':hint, from :variable',
        'source' => 'Settings from',
        'source_console' => 'This page',
        'source_env' => 'The .env file',
        'changed' => 'Last change',
        'changed_by' => ':date by :name',
        'plans' => 'AI allowance',
        'plans_self' => 'Self-hosted: not limited by a plan (per-person quotas apply)',
        'plans_saas' => 'Limited by the organisations’ plans',
        'prompts' => 'Instructions',
        'prompts_pack' => 'From the content pack',
        'prompts_generic' => 'The engine’s generic wording (the content pack has none)',
    ],

    'providers' => [
        'anthropic' => 'Claude (Anthropic)',
        'openai' => 'ChatGPT (OpenAI)',
        'gemini' => 'Gemini (Google)',
        'mistral' => 'Mistral',
        'openai_compatible' => 'OpenAI-compatible API',
    ],

    'form' => [
        'title' => 'Change',
        'provider' => 'Provider',
        'model' => 'Model id',
        'model_help' => 'Free text, as the provider names it. Suggested: :models. Empty: :default.',
        'model_help_compatible' => 'Required: the model id as the API names it.',
        'base_url' => 'API base URL',
        'base_url_help' => 'Required for an OpenAI-compatible API (for example https://llm.example.com/v1). Optional for the others: a gateway in front of the provider.',
        'api_key' => 'API key',
        'api_key_help' => 'Stored encrypted, never shown again. Leave empty to keep the saved key.',
        'api_key_saved' => 'Saved key :hint',
        'clear_key' => 'Remove the saved key (use the .env key, if any)',
        'save' => 'Save',
        'reset' => 'Back to the .env values',
        'reset_confirm' => 'Remove the settings saved here and use the .env values again?',
    ],

    'test' => [
        'title' => 'Test the connection',
        'help' => 'Sends one short request to the model with the settings in use. It does not count in the quotas.',
        'button' => 'Test connection',
        'ok' => 'Connected: :model answered in :ms ms.',
        'failed' => 'The test failed with :model.',
        'not_configured' => 'Nothing to test: set a provider, a model and a key first.',
    ],

    'saved' => 'AI settings saved.',
    'reset_done' => 'AI settings removed: the .env values apply.',

    'errors' => [
        'model' => 'Letters, digits and . _ : / @ + - only.',
        'model_required' => 'An OpenAI-compatible API needs the model id.',
        'key' => 'The key has no spaces.',
    ],
];
