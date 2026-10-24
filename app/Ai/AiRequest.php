<?php

namespace App\Ai;

use Prism\Prism\Contracts\Schema;

/**
 * One call to the model, always with a JSON schema (CRAted's single request shape).
 * The system part is fixed engine wording (plus the content pack's optional context);
 * the prompt carries the data, documents included, as untrusted material.
 */
final readonly class AiRequest
{
    public function __construct(
        public Feature $feature,
        public string $system,
        public string $prompt,
        public Schema $schema,
        public int $maxTokens = 4096,
    ) {}
}
