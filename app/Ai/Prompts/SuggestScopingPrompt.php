<?php

namespace App\Ai\Prompts;

use App\Ai\AiRequest;
use App\Ai\Feature;
use App\Content\Text;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * "Suggest with AI" on the scoping: for each scoping question, whether it looks true for the
 * product, with a one-sentence reason and a confidence. Only the questions are sent (key and
 * wording), never the rules that turn answers into articles and standards. A person ticks.
 * The task wording comes from the pack (ai/prompts/suggest_scoping.md) when it has it.
 */
final class SuggestScopingPrompt
{
    public const CONFIDENCE = ['high', 'medium', 'low'];

    /** Task wording when the pack has none. */
    public const GENERIC_TASK = <<<'TXT'
    For every scoping question, decide from the material alone whether its statement holds for the product. The person will review your proposal and tick the answers.
    Use each question key once, exactly as given. Set "applies" to true only when the material states or clearly implies it.
    Give in "reason" one short sentence drawn from the material. If the material is silent, set "applies" to false and the confidence to low.
    Set "confidence" to high, medium or low.
    TXT;

    /**
     * @param  array<string, array<string, mixed>>  $questions  boolean scoping questions, key => definition
     */
    public static function make(array $questions, string $productText, string $documents = ''): AiRequest
    {
        $system = SystemPrompt::for(PackPrompts::text('suggest_scoping', self::GENERIC_TASK));

        $lines = [];
        foreach ($questions as $key => $question) {
            $line = '- '.$key.': '.Text::of($question['label'] ?? $key, 'en');
            if (! empty($question['statement'])) {
                $line .= ' ('.Text::of($question['statement'], 'en').')';
            }
            if (! empty($question['example'])) {
                $line .= ' Example: '.Text::of($question['example'], 'en');
            }
            $lines[] = $line;
        }

        $prompt = "Scoping questions (key: statement):\n".implode("\n", $lines)
            ."\n\nWhat the person wrote about the product:\n".SystemPrompt::data($productText)
            .($documents !== '' ? "\n\nProduct documents:\n".$documents : '');

        $schema = new ObjectSchema('scoping_suggestion', 'Suggested scoping answers', [
            new ArraySchema('answers', 'One answer per scoping question', new ObjectSchema('answer', 'Suggested answer', [
                new StringSchema('key', 'The question key, exactly as given'),
                new BooleanSchema('applies', 'Whether the statement is true for the product'),
                new StringSchema('reason', 'One sentence quoting or paraphrasing the material'),
                new EnumSchema('confidence', 'How sure the material makes you', self::CONFIDENCE),
            ], ['key', 'applies', 'reason', 'confidence']), maxItems: 40),
        ], ['answers']);

        return new AiRequest(Feature::SuggestScoping, $system, $prompt, $schema, 2048);
    }
}
