<?php

namespace App\Ai;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Which AI provider, model, endpoint and key the install uses: bring your own key and model.
 *
 * From .env (REDACT_AI_PROVIDER, REDACT_AI_MODEL, REDACT_AI_BASE_URL, the provider's key variable,
 * see config/redact.php "ai"), overridden by the console's "AI settings" page (stored in
 * app_settings "ai", the key encrypted with the app key, never shown back except its last 4
 * characters). A stored value only overrides when it is set: an empty model falls back to .env or
 * the provider's suggested default, a missing key to the .env key of the same provider.
 *
 * Providers (Prism drivers): anthropic (Claude), openai (ChatGPT, Responses API), gemini (Google),
 * openai_compatible (any OpenAI-compatible chat-completions API or gateway: vLLM, LiteLLM, Ollama,
 * Azure OpenAI proxies, OpenRouter...; base URL required, through Prism's OpenRouter driver, which
 * speaks the plain chat/completions API with a JSON schema response format).
 */
class AiSettings
{
    public const KEY = 'ai';

    /**
     * Providers: Prism driver, .env key variable (config path), suggested models (the first is the
     * default; free text in any case), whether the API accepts a temperature for these models.
     */
    public const PROVIDERS = [
        'anthropic' => [
            'driver' => 'anthropic',
            'key_config' => 'prism.providers.anthropic.api_key',
            'key_env' => 'ANTHROPIC_API_KEY',
            'models' => ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'],
            // Current Claude models reject a non-default temperature.
            'temperature' => false,
            'prefixes' => ['claude'],
        ],
        'openai' => [
            'driver' => 'openai',
            'key_config' => 'prism.providers.openai.api_key',
            'key_env' => 'OPENAI_API_KEY',
            'models' => ['gpt-5-mini', 'gpt-6.1-sol', 'gpt-5.5'],
            // gpt-5 / gpt-6.x reasoning models accept no temperature but the default (Prism's OpenAI notes).
            'temperature' => false,
            'prefixes' => ['gpt', 'o1', 'o3', 'o4', 'chatgpt'],
        ],
        'gemini' => [
            'driver' => 'gemini',
            'key_config' => 'prism.providers.gemini.api_key',
            'key_env' => 'GEMINI_API_KEY',
            'models' => ['gemini-3.5-flash-lite', 'gemini-3-flash-preview'],
            'temperature' => true,
            'prefixes' => ['gemini'],
        ],
        'mistral' => [
            'driver' => 'mistral',
            'key_config' => 'prism.providers.mistral.api_key',
            'key_env' => 'MISTRAL_API_KEY',
            'models' => ['mistral-medium-latest', 'mistral-large-latest'],
            'temperature' => true,
            'prefixes' => ['mistral', 'magistral', 'codestral'],
        ],
        'openai_compatible' => [
            'driver' => 'openrouter',
            'key_config' => 'redact.ai.api_key',
            'key_env' => 'REDACT_AI_API_KEY',
            'models' => [],
            'temperature' => true,
            'prefixes' => [],
        ],
    ];

    public const DEFAULT_PROVIDER = 'gemini';

    /** @return array<string, mixed> what the console stored ([] when nothing) */
    public function stored(): array
    {
        return AppSetting::get(self::KEY);
    }

    /** Where the settings come from: "console" (stored, overriding .env) or "env". */
    public function source(): string
    {
        return array_filter(array_intersect_key($this->stored(), array_flip(['provider', 'model', 'base_url', 'api_key']))) !== [] ? 'console' : 'env';
    }

    public function provider(): string
    {
        $stored = (string) ($this->stored()['provider'] ?? '');
        if (isset(self::PROVIDERS[$stored])) {
            return $stored;
        }
        $env = (string) config('redact.ai.provider', self::DEFAULT_PROVIDER);

        return isset(self::PROVIDERS[$env]) ? $env : self::DEFAULT_PROVIDER;
    }

    /** The Prism driver of the provider. */
    public function driver(): string
    {
        return self::PROVIDERS[$this->provider()]['driver'];
    }

    /** The model id: stored, else .env (when it is not another provider's model), else the provider's default. */
    public function model(): string
    {
        $provider = $this->provider();
        $stored = $this->stored();
        $model = trim((string) ($stored['model'] ?? ''));
        $storedProvider = (string) ($stored['provider'] ?? '');
        if ($model === '' && (! isset(self::PROVIDERS[$storedProvider]) || $storedProvider === (string) config('redact.ai.provider'))) {
            $model = trim((string) config('redact.ai.model'));
        }
        if ($model !== '' && $this->belongsToAnother($model, $provider)) {
            $model = '';
        }

        return $model !== '' ? $model : (self::PROVIDERS[$provider]['models'][0] ?? '');
    }

    /** Base URL of the API ("" = the provider's own); required for openai_compatible. */
    public function baseUrl(): string
    {
        $stored = trim((string) ($this->stored()['base_url'] ?? ''));

        return rtrim($stored !== '' ? $stored : trim((string) config('redact.ai.base_url')), '/');
    }

    /** The API key: the stored one (decrypted) when set, else the provider's .env variable. */
    public function apiKey(): string
    {
        $stored = $this->storedKey();
        if ($stored !== '') {
            return $stored;
        }

        return trim((string) config(self::PROVIDERS[$this->provider()]['key_config']));
    }

    /** "console", "env" or null: where the key in use comes from. */
    public function keySource(): ?string
    {
        if ($this->storedKey() !== '') {
            return 'console';
        }

        return $this->apiKey() !== '' ? 'env' : null;
    }

    /** The last 4 characters of the key in use ("…a1b2"), null without a key. Never more. */
    public function keyHint(): ?string
    {
        $key = $this->apiKey();

        return $key === '' ? null : '…'.mb_substr($key, -4);
    }

    /** Everything needed to call the model is there (switch, key, model, base URL when required). */
    public function configured(): bool
    {
        return (bool) config('redact.ai.enabled')
            && $this->apiKey() !== ''
            && $this->model() !== ''
            && ($this->provider() !== 'openai_compatible' || $this->baseUrl() !== '');
    }

    /** Does this provider's API accept the temperature REDact asks for? */
    public function acceptsTemperature(): bool
    {
        return self::PROVIDERS[$this->provider()]['temperature'];
    }

    /**
     * Prism provider config of each call (merged by Prism over config/prism.php).
     *
     * @return array<string, mixed>
     */
    public function providerConfig(): array
    {
        $config = ['api_key' => $this->apiKey()];
        if ($this->baseUrl() !== '') {
            $config['url'] = $this->baseUrl();
        }
        if ($this->provider() === 'openai_compatible') {
            // Prism's OpenRouter driver adds these headers when set: none for a generic API.
            $config['site'] = ['http_referer' => null, 'x_title' => null];
        }

        return $config;
    }

    /**
     * Store the console's settings. The key is kept unless a new one is given ($apiKey) or
     * $clearKey; it is encrypted with the app key (Laravel's encrypter) and never stored in clear.
     */
    public function save(string $provider, string $model, string $baseUrl, ?string $apiKey, bool $clearKey, User $by): void
    {
        $current = $this->stored();
        $encrypted = (string) ($current['api_key'] ?? '');
        // A key belongs to its provider: switching provider without a new key drops the stored one.
        if ($clearKey || ($apiKey === null && ($current['provider'] ?? null) !== $provider)) {
            $encrypted = '';
        }
        if ($apiKey !== null && trim($apiKey) !== '') {
            $encrypted = Crypt::encryptString(trim($apiKey));
        }

        AppSetting::put(self::KEY, array_filter([
            'provider' => $provider,
            'model' => trim($model),
            'base_url' => rtrim(trim($baseUrl), '/'),
            'api_key' => $encrypted,
        ], fn ($v) => $v !== ''), $by);
    }

    /** What answers by default (the install's provider, model and key), as one runtime. */
    public function runtime(): AiRuntime
    {
        $models = [$this->model()];
        foreach ((array) config('redact.ai.fallback_models', []) as $model) {
            $models[] = trim((string) $model);
        }

        return new AiRuntime(
            provider: $this->provider(),
            driver: $this->driver(),
            models: array_values(array_unique(array_filter($models, fn (string $m) => $m !== ''))),
            providerConfig: $this->providerConfig(),
            temperature: $this->acceptsTemperature(),
            configured: $this->configured(),
        );
    }

    /** Back to the .env values. */
    public function reset(): void
    {
        AppSetting::forget(self::KEY);
    }

    private function storedKey(): string
    {
        $stored = $this->stored();
        $encrypted = (string) ($stored['api_key'] ?? '');
        if ($encrypted === '' || ($stored['provider'] ?? self::DEFAULT_PROVIDER) !== $this->provider()) {
            return '';
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            // The app key changed: the stored key is unusable (the page asks for it again).
            return '';
        }
    }

    private function belongsToAnother(string $model, string $provider): bool
    {
        foreach (self::PROVIDERS as $name => $definition) {
            if ($name === $provider) {
                continue;
            }
            foreach ($definition['prefixes'] as $prefix) {
                if (str_starts_with(strtolower($model), $prefix)) {
                    return ! in_array($provider, ['openai_compatible'], true);
                }
            }
        }

        return false;
    }
}
