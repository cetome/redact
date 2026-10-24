<?php

namespace App\Ai\Prompts;

use App\Ai\AiRequest;
use App\Ai\Feature;
use App\Models\Evaluation;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * "Check this evaluation": a consistency review. It never gives a verdict. The task wording
 * comes from the pack (ai/prompts/check_evaluation.md) when it has it.
 */
final class CheckEvaluationPrompt
{
    public const SEVERITIES = ['info', 'warning', 'problem'];

    /** Task wording when the pack has none. */
    public const GENERIC_TASK = <<<'TXT'
    Review one recorded walk of a decision tree for internal consistency.
    Look at the recorded answers, the questions of the tree, the data of the object, the last node reached, the verdict and the justification.
    Point out a verdict that seems not to follow from the answers, but do not say which verdict would be right: that is the person's decision.
    Use the severities "problem", "warning" and "info". Give no findings when nothing is wrong.
    Write at most 8 findings of one or two short sentences each, and a one-sentence "summary".
    TXT;

    public static function make(Evaluation $evaluation): AiRequest
    {
        $system = SystemPrompt::for(PackPrompts::text('check_evaluation', self::GENERIC_TASK));

        $prompt = "Evaluation to check:\n".EvaluationContext::describe($evaluation);

        $schema = new ObjectSchema('evaluation_check', 'Consistency findings', [
            new StringSchema('summary', 'One sentence overall'),
            new ArraySchema('findings', 'Findings', new ObjectSchema('finding', 'One finding', [
                new EnumSchema('severity', 'How serious', self::SEVERITIES),
                new StringSchema('message', 'What looks wrong or weak, and why'),
            ], ['severity', 'message']), maxItems: 8),
        ], ['summary', 'findings']);

        return new AiRequest(Feature::Check, $system, $prompt, $schema, 2048);
    }
}
