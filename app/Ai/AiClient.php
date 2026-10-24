<?php

namespace App\Ai;

use App\Models\AiUsage;
use App\Models\Organisation;
use App\Models\ProductVersion;
use App\Models\User;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Structured\PendingRequest;
use Throwable;

/**
 * The only door to the model (CRAted's provider.ts): provider, model, key and
 * endpoint from App\Ai\AiSettings (bring your own key and model: console
 * settings, else .env), ordered fallback models from config('redact.ai'), a
 * timeout, a JSON schema on every call, temperature 0 for checks where the
 * provider accepts one, and one ai_usage row per call reserved before the
 * model is reached. Provider and model names never leave the server: people
 * see "cetome default".
 */
class AiClient
{
    public function __construct(
        private readonly Quota $quota,
        private readonly AiSettings $settings,
        private readonly OrganisationAi $organisations,
        private readonly EndpointGuard $guard,
    ) {}

    /** What answers this scope: the organisation's own model when it has one, else the install's default. */
    public function runtime(ProductVersion|Organisation|null $scope = null): AiRuntime
    {
        $organisation = $scope instanceof ProductVersion ? $scope->product->organisation : $scope;

        return $this->organisations->runtime($organisation);
    }

    /** The provider in use (anthropic, openai, gemini, openai_compatible). */
    public function provider(): string
    {
        return $this->settings->provider();
    }

    /** @return list<string> the configured model first, then the fallbacks, in order */
    public function models(): array
    {
        $models = [$this->settings->model()];
        foreach ((array) config('redact.ai.fallback_models', []) as $model) {
            $model = trim((string) $model);
            if ($model !== '') {
                $models[] = $model;
            }
        }

        return array_values(array_unique(array_filter($models, fn (string $m) => $m !== '')));
    }

    /** Is the AI switched on, with a key, a model and (for a compatible API) its URL, for this scope? */
    public function configured(ProductVersion|Organisation|null $scope = null): bool
    {
        return (bool) config('redact.ai.enabled') && $this->runtime($scope)->configured;
    }

    /**
     * Reserve the units of a call (fails closed). Separate from call() so queued jobs pay at dispatch.
     * The scope is the version, or the organisation when the product does not exist yet.
     */
    public function reserve(User $user, ProductVersion|Organisation $version, Feature $feature, ?int $units = null): AiUsage
    {
        $runtime = $this->runtime($version);
        if (! (bool) config('redact.ai.enabled') || ! $runtime->configured || $runtime->models === []) {
            throw AiException::unavailable();
        }

        return $this->quota->reserve($user, $version, $feature, $units ?? $feature->units(), $runtime->usageProvider(), $runtime->models[0]);
    }

    /** Reserve, then call. */
    public function run(User $user, ProductVersion|Organisation $version, AiRequest $request, ?int $units = null): AiResult
    {
        return $this->call($request, $this->reserve($user, $version, $request->feature, $units));
    }

    /** Call the model on an already reserved usage row, trying each model in turn. */
    public function call(AiRequest $request, AiUsage $usage): AiResult
    {
        // The same runtime as at reservation: the usage row's organisation.
        $runtime = $this->runtime($usage->organisation_id ? Organisation::query()->find($usage->organisation_id) : null);
        $provider = $runtime->usageProvider();
        $started = hrtime(true);
        $tokensIn = 0;
        $tokensOut = 0;

        foreach ($runtime->models as $model) {
            try {
                $response = $this->pending($runtime, $model, $request->system, $request->prompt, $request->schema, $request->maxTokens, $request->feature->temperature())
                    ->asStructured();

                $tokensIn += $response->usage->promptTokens;
                $tokensOut += $response->usage->completionTokens;

                if (! is_array($response->structured) || $response->structured === []) {
                    continue;
                }

                $usage->forceFill([
                    'model' => mb_substr($model, 0, 80),
                    'tokens_in' => $tokensIn,
                    'tokens_out' => $tokensOut,
                    'duration_ms' => $this->elapsed($started),
                    'outcome' => 'ok',
                ])->save();

                return new AiResult($response->structured, $provider, $model, $usage);
            } catch (Throwable $e) {
                // Never surface the provider's wording: log it and try the next model.
                report($e);
            }
        }

        $usage->forceFill([
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'duration_ms' => $this->elapsed($started),
            'outcome' => 'error',
        ])->save();

        throw AiException::unavailable();
    }

    /**
     * "Test connection" (staff console, an organisation's AI model card): one tiny structured call
     * with what answers the scope, outside the quotas and the ai_usage log (the caller logs it).
     * Never throws.
     *
     * @return array{ok: bool, model: string, ms: int, error: ?string}
     */
    public function test(?Organisation $organisation = null): array
    {
        $runtime = $this->runtime($organisation);
        $model = $runtime->models[0] ?? '';
        if (! (bool) config('redact.ai.enabled') || ! $runtime->configured || $model === '') {
            return ['ok' => false, 'model' => $model, 'ms' => 0, 'error' => null];
        }
        $started = hrtime(true);
        try {
            $schema = new ObjectSchema('connection_test', 'Connection test', [new BooleanSchema('ok', 'Always true')], ['ok']);
            $response = $this->pending($runtime, $model, 'Answer with JSON only.', 'Reply with {"ok": true}.', $schema, 256, 0.0)->asStructured();
            $ok = ($response->structured['ok'] ?? null) === true;

            return ['ok' => $ok, 'model' => $model, 'ms' => $this->elapsed($started), 'error' => $ok ? null : 'The model answered, but not with the expected JSON.'];
        } catch (Throwable $e) {
            report($e);
            // The provider's message helps the operator (wrong model, key or URL); the key never appears in it.
            $message = mb_substr(trim((string) preg_replace('/\s+/', ' ', $e->getMessage())), 0, 300);
            $key = (string) ($runtime->providerConfig['api_key'] ?? '');
            if ($key !== '') {
                $message = str_replace($key, '[key]', $message);
            }

            return ['ok' => false, 'model' => $model, 'ms' => $this->elapsed($started), 'error' => $message !== '' ? $message : class_basename($e)];
        }
    }

    /**
     * One structured request on a runtime: its driver, model and provider config, a timeout, no
     * redirects for an own model, an own endpoint checked again and pinned (EndpointGuard), and a
     * temperature only where the provider accepts one.
     */
    private function pending(AiRuntime $runtime, string $model, string $system, string $prompt, Schema $schema, int $maxTokens, float $temperature): PendingRequest
    {
        $options = ['timeout' => max(5, (int) config('redact.ai.timeout', 90)), 'connect_timeout' => 10] + $runtime->clientOptions;
        if ($runtime->endpoint !== null) {
            $options = $this->guard->clientOptions($runtime->endpoint) + $options;
        }

        $pending = Prism::structured()
            ->using($runtime->driver, $model, $runtime->providerConfig)
            ->withSystemPrompt($system)
            ->withPrompt($prompt)
            ->withSchema($schema)
            ->withMaxTokens($maxTokens)
            ->withClientOptions($options);

        return $runtime->temperature ? $pending->usingTemperature($temperature) : $pending;
    }

    private function elapsed(int $started): int
    {
        return (int) min(4294967295, intdiv(hrtime(true) - $started, 1_000_000));
    }
}
