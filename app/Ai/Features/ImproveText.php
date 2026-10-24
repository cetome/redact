<?php

namespace App\Ai\Features;

use App\Ai\AiClient;
use App\Ai\AiException;
use App\Ai\Feature;
use App\Ai\Prompts\ImproveTextPrompt;
use App\Ai\Suggestions;
use App\Ai\Targets;
use App\Enums\Origin;
use App\Models\AiSuggestion;
use App\Models\ProductVersion;
use App\Models\User;

/**
 * "Improve text with AI" on one field: a rewording proposal, shown with a word
 * diff. Nothing is written until the person accepts; an accepted rewording is
 * "AI Enhanced" (the meaning stays the person's).
 */
class ImproveText
{
    public function __construct(private readonly AiClient $client) {}

    public function __invoke(User $user, ProductVersion $version, string $type, string $key, string $field, string $text): AiSuggestion
    {
        $target = Targets::resolve($version, $type, $key, $field);
        if (! $target->isFreeText()) {
            throw AiException::invalid(__('ai.errors.target_not_allowed'));
        }
        $text = trim($text);
        if ($text === '') {
            throw AiException::invalid(__('ai.improve.empty'));
        }

        $result = $this->client->run($user, $version, ImproveTextPrompt::make($target->label(), $text));

        $improved = trim((string) ($result->data['improved'] ?? ''));
        if ($improved === '' || mb_strlen($improved) > $target->maxLength()) {
            $improved = $text;
        }
        $changes = collect(is_array($result->data['changes'] ?? null) ? $result->data['changes'] : [])
            ->filter(fn ($c) => is_string($c) && trim($c) !== '')
            ->map(fn (string $c) => mb_substr(trim($c), 0, 300))
            ->take(5)->values()->all();
        $unchanged = preg_replace('/\s+/u', ' ', $improved) === preg_replace('/\s+/u', ' ', $text);

        Suggestions::supersede($version, Feature::ImproveText, $target->type, $target->key, $target->field);

        return Suggestions::create($version, Feature::ImproveText, $target->type, $target->key, $target->field,
            Origin::AiEnhanced->value,
            ['value' => $improved, 'original' => $text, 'changes' => $unchanged ? [] : $changes, 'unchanged' => $unchanged],
            $result, $user, $unchanged ? 'superseded' : 'pending');
    }
}
