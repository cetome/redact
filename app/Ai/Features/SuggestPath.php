<?php

namespace App\Ai\Features;

use App\Ai\AiClient;
use App\Ai\AiException;
use App\Ai\Feature;
use App\Ai\Prompts\EvaluationContext;
use App\Ai\Prompts\SuggestPathPrompt;
use App\Ai\Suggestions;
use App\Content\Text;
use App\Documents\DocumentText;
use App\Enums\Origin;
use App\Models\AiSuggestion;
use App\Models\Evaluation;
use App\Models\ProductVersion;
use App\Models\User;

/**
 * "Suggest a path": a proposed answer per tree question with its reason and
 * source. Only shown: the person clicks every answer themselves, so the AI
 * never decides a path or a verdict.
 */
class SuggestPath
{
    public function __construct(private readonly AiClient $client) {}

    public function __invoke(User $user, ProductVersion $version, Evaluation $evaluation): AiSuggestion
    {
        $tree = EvaluationContext::tree($evaluation);
        if (! $tree) {
            throw AiException::invalid(__('ai.errors.no_tree'));
        }

        $productText = trim($version->product->name."\n".$version->product->description."\n".$version->intended_use);
        $request = SuggestPathPrompt::make($evaluation, $productText, DocumentText::forPrompt($version, 120_000));
        $result = $this->client->run($user, $version, $request);

        $answers = [];
        foreach (is_array($result->data['answers'] ?? null) ? $result->data['answers'] : [] as $raw) {
            $node = is_array($raw) ? (string) ($raw['node'] ?? '') : '';
            if (isset($answers[$node]) || ! $tree->has($node) || $tree->node($node)['kind'] !== 'decision') {
                continue;
            }
            $branch = collect($tree->node($node)['branches'] ?? [])
                ->first(fn (array $b) => mb_strtolower($b['label']) === mb_strtolower(trim((string) ($raw['answer'] ?? ''))));
            if (! $branch) {
                continue;
            }
            $answers[$node] = [
                'node' => $node,
                'question' => Text::of($tree->node($node)['text'] ?? ''),
                'answer' => $branch['label'],
                'rationale' => mb_substr(trim((string) ($raw['rationale'] ?? '')), 0, 1000),
                'source' => mb_substr(trim((string) ($raw['source'] ?? '')), 0, 200),
            ];
        }
        // In the tree's order.
        $ordered = [];
        foreach ($tree->decisionNodes() as $id) {
            if (isset($answers[$id])) {
                $ordered[] = $answers[$id];
            }
        }

        Suggestions::supersede($version, Feature::SuggestPath, 'evaluation', $evaluation->getKey());

        return Suggestions::create($version, Feature::SuggestPath, 'evaluation', $evaluation->getKey(), 'path',
            Origin::AiGenerated->value, ['answers' => $ordered, 'fingerprint' => $evaluation->fingerprint()], $result, $user);
    }
}
