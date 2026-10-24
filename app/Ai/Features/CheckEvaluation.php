<?php

namespace App\Ai\Features;

use App\Ai\AiClient;
use App\Ai\Feature;
use App\Ai\Prompts\CheckEvaluationPrompt;
use App\Ai\Suggestions;
use App\Enums\Origin;
use App\Models\AiSuggestion;
use App\Models\Evaluation;
use App\Models\ProductVersion;
use App\Models\User;

/**
 * "Check this evaluation": consistency findings, stored in the AI channel and
 * shown as an AI Generated panel. It never changes the evaluation.
 */
class CheckEvaluation
{
    public function __construct(private readonly AiClient $client) {}

    public function __invoke(User $user, ProductVersion $version, Evaluation $evaluation): AiSuggestion
    {
        $result = $this->client->run($user, $version, CheckEvaluationPrompt::make($evaluation));

        $findings = collect(is_array($result->data['findings'] ?? null) ? $result->data['findings'] : [])
            ->filter(fn ($f) => is_array($f) && is_string($f['message'] ?? null) && trim($f['message']) !== '')
            ->map(fn (array $f) => [
                'severity' => in_array($f['severity'] ?? null, CheckEvaluationPrompt::SEVERITIES, true) ? $f['severity'] : 'info',
                'message' => mb_substr(trim($f['message']), 0, 1000),
            ])
            ->sortBy(fn (array $f) => array_search($f['severity'], ['problem', 'warning', 'info'], true))
            ->take(8)->values()->all();

        Suggestions::supersede($version, Feature::Check, 'evaluation', $evaluation->getKey());

        return Suggestions::create($version, Feature::Check, 'evaluation', $evaluation->getKey(), 'check',
            Origin::AiGenerated->value, [
                'summary' => mb_substr(trim((string) ($result->data['summary'] ?? '')), 0, 500),
                'findings' => $findings,
                'fingerprint' => $evaluation->fingerprint(),
            ], $result, $user);
    }
}
