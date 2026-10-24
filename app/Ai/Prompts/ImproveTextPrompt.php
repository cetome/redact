<?php

namespace App\Ai\Prompts;

use App\Ai\AiRequest;
use App\Ai\Feature;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * "Improve text with AI": same facts, clearer sentences (CRAted's wording proposal). The task
 * wording comes from the pack (ai/prompts/improve_text.md) when it has it.
 */
final class ImproveTextPrompt
{
    /** Task wording when the pack has none. */
    public const GENERIC_TASK = <<<'TXT'
    Make the text clearer and more precise without changing what it says.
    Do not add, remove or alter any fact, name, number or reference.
    Answer in the language of the text. When nothing needs improving, give the text back as it is with no changes listed.
    List up to 5 short notes on what you changed in "changes".
    TXT;

    public static function make(string $fieldLabel, string $text): AiRequest
    {
        $system = SystemPrompt::for(PackPrompts::text('improve_text', self::GENERIC_TASK));

        $prompt = 'Field: '.$fieldLabel."\n\nText to improve:\n".SystemPrompt::data($text);

        $schema = new ObjectSchema('improved_text', 'The improved wording', [
            new StringSchema('improved', 'The rewritten text, or the original text when nothing needs to change'),
            new ArraySchema('changes', 'Short descriptions of what changed', new StringSchema('change', 'One change'), maxItems: 5),
        ], ['improved', 'changes']);

        return new AiRequest(Feature::ImproveText, $system, $prompt, $schema, 4096);
    }
}
