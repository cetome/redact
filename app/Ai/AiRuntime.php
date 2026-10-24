<?php

namespace App\Ai;

/**
 * What answers one AI call: the install's default (App\Ai\AiSettings) or an organisation's own
 * key or model (App\Ai\OrganisationAi). The prompts, schemas and material are the same whatever
 * answers, so a product is judged by the same rules by any model.
 */
final readonly class AiRuntime
{
    /**
     * @param  string  $provider  AiSettings::PROVIDERS key
     * @param  string  $driver  the Prism driver
     * @param  list<string>  $models  tried in turn
     * @param  array<string, mixed>  $providerConfig  merged by Prism over config/prism.php
     * @param  bool  $own  the organisation's own key or model (never consumes plan AI units)
     * @param  string|null  $choice  the organisation's choice (OrganisationAi::CHOICES) when own
     * @param  bool  $readsPictures  an own endpoint that reads pictures (the others all do)
     * @param  array<string, mixed>  $clientOptions  HTTP client options (redirects, pinned address)
     * @param  string|null  $endpoint  an own endpoint, checked again before each call (EndpointGuard)
     */
    public function __construct(
        public string $provider,
        public string $driver,
        public array $models,
        public array $providerConfig,
        public bool $temperature,
        public bool $configured,
        public bool $own = false,
        public ?string $choice = null,
        public bool $readsPictures = true,
        public array $clientOptions = [],
        public ?string $endpoint = null,
    ) {}

    /** How ai_usage records it: the provider, or "own:<choice>" for an organisation's own model. */
    public function usageProvider(): string
    {
        return $this->own ? 'own:'.$this->choice : $this->provider;
    }
}
