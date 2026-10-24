<?php

namespace App\Ai;

use App\Models\AiUsage;

/**
 * The structured answer of a call, with what is recorded on the suggestions
 * it produces (provider and model stay internal: never shown to customers).
 */
final readonly class AiResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public string $provider,
        public string $model,
        public AiUsage $usage,
    ) {}
}
