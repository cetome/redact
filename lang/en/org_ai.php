<?php

// An organisation's Settings page: the AI model card (App\Http\Controllers\Ai\OrganisationAiController), as in CRAted.
return [
    'tab' => 'Settings',
    'title' => 'Settings',
    'intro' => 'How your organisation works in REDact.',

    'card' => 'AI model',
    'lead_saas' => 'cetome’s AI answers every AI button, included in your paid plan. Your organisation can bring its own key or its own model instead: its calls then never use your AI units. The key is stored encrypted and never shown again.',
    'lead_self' => 'The default AI of this install answers every AI button. Your organisation can bring its own key or its own model instead. The key is stored encrypted and never shown again.',
    'read_only' => 'The administrators of your organisation choose it. This is what answers you.',
    'same_rules' => 'Whatever answers, the AI gets the same instructions and the same material, and a person decides.',

    'now' => 'Answering now',
    'now_default_saas' => 'cetome default',
    'now_default_self' => 'The default AI of this install',
    'now_none' => 'No AI: this install has no default AI, choose your own key or model',
    'now_own' => ':choice · :model',

    'choices' => [
        'default_saas' => 'cetome default (included)',
        'default_self' => 'The default of this install',
        'gemini_own' => 'Google Gemini (your own key)',
        'claude' => 'Anthropic Claude (your own key)',
        'openai' => 'OpenAI ChatGPT (your own key)',
        'mistral' => 'Mistral (your own key)',
        'openai_compatible' => 'Your own endpoint (OpenAI-compatible)',
    ],
    'key_labels' => [
        'gemini_own' => 'Google AI API key',
        'claude' => 'Anthropic API key',
        'openai' => 'OpenAI API key',
        'mistral' => 'Mistral API key',
        'openai_compatible' => 'API key (optional)',
    ],

    'form' => [
        'answered_by' => 'Answered by',
        'nothing' => 'Nothing to set up.',
        'endpoint' => 'Endpoint',
        'endpoint_help_saas' => 'The base address of a server speaking the OpenAI chat protocol: REDact calls /chat/completions under it. Public https only.',
        'endpoint_help_self' => 'The base address of a server speaking the OpenAI chat protocol (Ollama, vLLM, LM Studio, Azure OpenAI, a gateway): REDact calls /chat/completions under it. http is allowed on this self-hosted install, for example http://localhost:11434/v1.',
        'key' => 'API key',
        'key_placeholder' => 'YOUR_API_KEY',
        'key_saved' => 'Saved key :hint',
        'key_set' => 'Set on :date. Typing a new key replaces it.',
        'key_help' => 'Stored encrypted, never shown again. A key serves only the provider it was given for.',
        'model' => 'Model',
        'model_empty' => 'Empty means :model.',
        'model_compatible' => 'The name your server knows the model by.',
        'model_placeholder' => 'model-name',
        'pictures' => 'This model reads pictures',
        'pictures_help' => 'REDact sends the text of your documents. Pictures go only to models that read them.',
        'save' => 'Save the AI settings',
        'test' => 'Test connection',
        'test_help' => 'Sends one short request to what answers your organisation. It does not count in any allowance.',
    ],

    'answers' => [
        'default' => 'The default AI answers your organisation again.',
        'gemini_own' => 'Gemini answers your organisation with your own key.',
        'claude' => 'Claude answers your organisation with your own key.',
        'openai' => 'OpenAI answers your organisation with your own key.',
        'mistral' => 'Mistral answers your organisation with your own key.',
        'openai_compatible' => 'Your own model answers your organisation.',
    ],

    'test' => [
        'ok' => 'Connected: :model answered in :ms ms.',
        'failed' => 'The test failed with :model.',
        'not_configured' => 'Nothing to test: no AI answers your organisation yet.',
    ],

    'errors' => [
        'model' => 'Letters, digits and . _ : / @ + - only.',
        'model_required' => 'Enter the name your server knows the model by.',
        'key' => 'The key has no spaces.',
        'key_required' => 'Enter the API key of your organisation for this provider.',
        'endpoint_invalid' => 'Enter the address of the server, for example https://ai.example.com/v1.',
        'endpoint_https_only' => 'Use an https address.',
        'endpoint_unresolved' => 'This address cannot be found.',
        'endpoint_private' => 'This address is on a private or local network: use a public address.',
    ],
];
