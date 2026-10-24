<?php

namespace App\Ai;

use App\Billing\Plan;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Which AI answers an organisation (CRAted's "AI model" card): the install's default, or the
 * organisation's own key or model: Google Gemini, Anthropic Claude, OpenAI ChatGPT or Mistral with
 * its own key, or its own endpoint speaking the OpenAI chat protocol (Ollama, vLLM, LM Studio, Azure
 * OpenAI, a gateway: the standard way to bring your own model).
 *
 * - Who may: every organisation of a self-hosted install; on cetome's SaaS paid and Enterprise
 *   organisations (allowed()). Otherwise the stored choice is kept but the default answers (on the
 *   free plan: no AI at all).
 * - The key is stored encrypted (Laravel's encrypter), never shown again (last 4 characters only),
 *   and serves only the choice it was given for: another provider needs its own key.
 * - An own model never consumes the plan's AI units (ai_usage rows "own:<choice>", Plan::aiAllowance).
 * - The prompts and schemas are the same whatever answers.
 *
 * Stored in organisations.settings["ai"]: choice, model, endpoint, reads_pictures, api_key
 * (encrypted), key_choice, key_set_at, updated_by, updated_at.
 */
class OrganisationAi
{
    /** Choice => AiSettings provider ("default": the install's AI). */
    public const CHOICES = [
        'default' => null,
        'gemini_own' => 'gemini',
        'claude' => 'anthropic',
        'openai' => 'openai',
        'mistral' => 'mistral',
        'openai_compatible' => 'openai_compatible',
    ];

    public function __construct(private readonly AiSettings $settings, private readonly EndpointGuard $guard) {}

    /** @return array<string, mixed> */
    public function stored(Organisation $organisation): array
    {
        $settings = is_array($organisation->settings) ? $organisation->settings : [];

        return is_array($settings['ai'] ?? null) ? $settings['ai'] : [];
    }

    public function choice(Organisation $organisation): string
    {
        $choice = (string) ($this->stored($organisation)['choice'] ?? 'default');

        return array_key_exists($choice, self::CHOICES) ? $choice : 'default';
    }

    /**
     * May this organisation bring its own key or model? Self-hosted: always. On the SaaS: a paid
     * organisation (at least one paid or granted slot in force) or Enterprise. Never the free plan
     * (no AI at all), nor an organisation whose subscription ended with no other slot left: its
     * choice is kept, the AI stops like the rest of the paid features.
     */
    public function allowed(Organisation $organisation, ?Plan $plan = null): bool
    {
        return Plan::selfHosted() || ($plan ?? Plan::for($organisation))->hasExtras();
    }

    /** The model a choice runs when none is named ("" for an own endpoint: it must be named). */
    public static function defaultModel(string $choice): string
    {
        $provider = self::CHOICES[$choice] ?? null;

        return $provider === null ? '' : (AiSettings::PROVIDERS[$provider]['models'][0] ?? '');
    }

    public function model(Organisation $organisation): string
    {
        $model = trim((string) ($this->stored($organisation)['model'] ?? ''));

        return $model !== '' ? $model : self::defaultModel($this->choice($organisation));
    }

    public function endpoint(Organisation $organisation): string
    {
        return rtrim(trim((string) ($this->stored($organisation)['endpoint'] ?? '')), '/');
    }

    public function readsPictures(Organisation $organisation): bool
    {
        return (bool) ($this->stored($organisation)['reads_pictures'] ?? false);
    }

    /** The key of the current choice (decrypted), "" when none was given for this very choice. */
    public function apiKey(Organisation $organisation): string
    {
        return $this->keyFor($organisation, $this->choice($organisation));
    }

    /** "…abcd" of the key stored for $choice (default: the current one), null without one. */
    public function keyHint(Organisation $organisation, ?string $choice = null): ?string
    {
        $key = $this->keyFor($organisation, $choice ?? $this->choice($organisation));

        return $key === '' ? null : '…'.mb_substr($key, -4);
    }

    /** Is an own key or model chosen and complete (key, or endpoint and model)? */
    public function complete(Organisation $organisation): bool
    {
        $choice = $this->choice($organisation);
        if ($choice === 'default') {
            return false;
        }
        if ($choice === 'openai_compatible') {
            return $this->endpoint($organisation) !== '' && $this->model($organisation) !== '';
        }

        return $this->apiKey($organisation) !== '';
    }

    /** Does the organisation's own model answer it now (allowed, chosen, complete, AI switched on)? */
    public function active(Organisation $organisation, ?Plan $plan = null): bool
    {
        return (bool) config('redact.ai.enabled') && $this->complete($organisation) && $this->allowed($organisation, $plan);
    }

    /** What answers this organisation: its own model when active, else the install's default. */
    public function runtime(?Organisation $organisation): AiRuntime
    {
        if ($organisation === null || ! $this->active($organisation)) {
            return $this->settings->runtime();
        }

        $choice = $this->choice($organisation);
        $provider = (string) self::CHOICES[$choice];
        $definition = AiSettings::PROVIDERS[$provider];
        $config = ['api_key' => $this->apiKey($organisation)];
        $endpoint = null;
        $options = ['allow_redirects' => false];
        if ($choice === 'openai_compatible') {
            $endpoint = $this->endpoint($organisation);
            $config += ['url' => $endpoint, 'site' => ['http_referer' => null, 'x_title' => null]];
        }

        return new AiRuntime(
            provider: $provider,
            driver: $definition['driver'],
            models: [$this->model($organisation)],
            providerConfig: $config,
            temperature: $definition['temperature'],
            configured: true,
            own: true,
            choice: $choice,
            readsPictures: $choice === 'openai_compatible' ? $this->readsPictures($organisation) : true,
            clientOptions: $options,
            endpoint: $endpoint,
        );
    }

    /**
     * Save the card. A new key replaces the stored one (and belongs to this choice); without a new
     * key, the stored key stays, serving only the choice it was given for.
     */
    public function save(Organisation $organisation, string $choice, string $model, string $endpoint, ?string $apiKey, bool $readsPictures, User $by): void
    {
        $stored = $this->stored($organisation);
        $ai = [
            'choice' => $choice,
            'model' => $choice === 'default' ? '' : trim($model),
            'endpoint' => $choice === 'openai_compatible' ? rtrim(trim($endpoint), '/') : '',
            'reads_pictures' => $choice === 'openai_compatible' && $readsPictures,
            'api_key' => (string) ($stored['api_key'] ?? ''),
            'key_choice' => (string) ($stored['key_choice'] ?? ''),
            'key_set_at' => $stored['key_set_at'] ?? null,
            'updated_by' => $by->getKey(),
            'updated_at' => now()->toIso8601String(),
        ];
        if ($apiKey !== null && trim($apiKey) !== '' && $choice !== 'default') {
            $ai['api_key'] = Crypt::encryptString(trim($apiKey));
            $ai['key_choice'] = $choice;
            $ai['key_set_at'] = now()->toIso8601String();
        }

        $settings = is_array($organisation->settings) ? $organisation->settings : [];
        $settings['ai'] = $ai;
        $organisation->forceFill(['settings' => $settings])->save();
    }

    /** Does a key wait for this choice (so saving it needs no new key)? */
    public function hasKeyFor(Organisation $organisation, string $choice): bool
    {
        return $this->keyFor($organisation, $choice) !== '';
    }

    /** Is the endpoint refused by the install's rules (EndpointGuard)? The reason, or null. */
    public function endpointRefusal(string $endpoint): ?string
    {
        return $this->guard->refusal($endpoint);
    }

    private function keyFor(Organisation $organisation, string $choice): string
    {
        $stored = $this->stored($organisation);
        $encrypted = (string) ($stored['api_key'] ?? '');
        if ($encrypted === '' || ($stored['key_choice'] ?? null) !== $choice || $choice === 'default') {
            return '';
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return '';
        }
    }
}
