<?php

namespace App\Ai\Prompts;

use App\Ai\AiRequest;
use App\Ai\Feature;
use App\Models\Evaluation;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * "Suggest a path": an answer per tree question, with its reason. A person clicks each answer.
 * The task wording comes from the pack (ai/prompts/suggest_path.md) when it has it.
 */
final class SuggestPathPrompt
{
    /** Task wording when the pack has none. */
    public const GENERIC_TASK = <<<'TXT'
    For the questions of one decision tree that the material can answer, propose an answer the person can check before choosing.
    Give for each: the node id, a branch label copied exactly, a brief rationale, and where it comes from (a document with page or section, "object data" or "product description").
    Skip the questions the material cannot answer, and never mention the verdict the answers lead to.
    TXT;

    public static function make(Evaluation $evaluation, string $productText, string $documents): AiRequest
    {
        $system = SystemPrompt::for(PackPrompts::text('suggest_path', self::GENERIC_TASK));

        $prompt = EvaluationContext::describe($evaluation, false)
            ."\n\nProduct description:\n".SystemPrompt::data($productText)
            .($documents !== '' ? "\n\nProduct documents:\n".$documents : '');

        $schema = new ObjectSchema('path_suggestion', 'Suggested answers', [
            new ArraySchema('answers', 'One suggested answer per question', new ObjectSchema('answer', 'Suggested answer', [
                new StringSchema('node', 'Decision node id, e.g. DN-1'),
                new StringSchema('answer', 'One branch label of that node, exactly'),
                new StringSchema('rationale', 'Why the material supports this answer'),
                new StringSchema('source', 'Where it comes from'),
            ], ['node', 'answer', 'rationale', 'source']), maxItems: 40),
        ], ['answers']);

        return new AiRequest(Feature::SuggestPath, $system, $prompt, $schema, 4096);
    }
}
