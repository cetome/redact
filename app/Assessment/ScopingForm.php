<?php

namespace App\Assessment;

use App\Content\ContentPack;
use App\Content\ScopingResult;
use App\Models\ProductVersion;
use Illuminate\Support\Facades\DB;

/**
 * The scoping questionnaire as defined by the content pack: which questions
 * exist, which are visible given the answers, and how to read a submission.
 *
 * Each pack section says in which workflow step it is asked ("step":
 * "scoping" at the very start, "conformity" at the end, before choosing the
 * conformity assessment route). A section without "step" belongs to scoping.
 */
class ScopingForm
{
    public const SCOPING = 'scoping';

    public const CONFORMITY = 'conformity';

    public function __construct(private readonly ContentPack $pack) {}

    /** @return list<array<string, mixed>> the pack sections asked in one step */
    public function sections(string $step): array
    {
        return array_values(array_filter(
            $this->pack->scoping()['sections'] ?? [],
            fn (array $section) => ($section['step'] ?? self::SCOPING) === $step,
        ));
    }

    /**
     * What the browser needs to show the scoping result live: which articles each
     * scoping question brings in, and which article each standard belongs to.
     * (The same mapping cetome.com publishes in its RED scoping tool.)
     *
     * @return array{questions: array<string, list<string>>, standards: list<array{code: string, article: string}>}
     */
    public function liveMap(): array
    {
        $questions = [];
        foreach ($this->questions(self::SCOPING) as $key => $question) {
            $questions[$key] = array_values($question['articles'] ?? []);
        }
        $standards = [];
        foreach ($this->pack->standards() as $code => $standard) {
            $standards[] = ['code' => (string) $code, 'article' => (string) ($standard['article'] ?? '')];
        }

        return ['questions' => $questions, 'standards' => $standards];
    }

    /**
     * @param  string|null  $step  null for every question of every step
     * @return array<string, array<string, mixed>> question key => definition
     */
    public function questions(?string $step = null): array
    {
        $questions = [];
        foreach ($this->pack->scoping()['sections'] ?? [] as $section) {
            if ($step !== null && ($section['step'] ?? self::SCOPING) !== $step) {
                continue;
            }
            foreach ($section['groups'] ?? [] as $group) {
                foreach ($group['questions'] ?? [] as $question) {
                    $questions[$question['key']] = $question;
                }
            }
        }

        return $questions;
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $answers
     */
    public function isVisible(array $question, array $answers, ScopingResult $result): bool
    {
        $condition = $question['show_if'] ?? null;
        if (! $condition) {
            return true;
        }
        if (isset($condition['article'])) {
            return (bool) ($result->articles[$condition['article']] ?? false);
        }
        if (isset($condition['question'])) {
            return ($answers[$condition['question']] ?? null) === ($condition['equals'] ?? true);
        }

        return true;
    }

    /**
     * Turn the submitted form of one step into typed answers, keeping only that
     * step's questions and allowed values. Unticked booleans are false.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function read(array $input, string $step = self::SCOPING): array
    {
        $answers = [];
        foreach ($this->questions($step) as $key => $question) {
            $value = $input[$key] ?? null;
            $answers[$key] = match ($question['type'] ?? 'boolean') {
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'select' => in_array($value, array_column($question['options'] ?? [], 'value'), true) ? $value : null,
                default => is_string($value) ? mb_substr($value, 0, 2000) : null,
            };
        }

        return $answers;
    }

    /**
     * Of one step's answers, keep those whose question is visible given all the
     * answers (the other steps' included), so stale answers never count.
     *
     * @param  array<string, mixed>  $stepAnswers  answers of the step being saved
     * @param  array<string, mixed>  $otherAnswers  stored answers of the other steps
     * @return array<string, mixed>
     */
    public function visibleOnly(array $stepAnswers, array $otherAnswers = [], string $step = self::SCOPING): array
    {
        $all = array_merge($otherAnswers, $stepAnswers);
        $result = $this->pack->rules()->scoping($all, $this->pack);
        $kept = [];
        foreach ($this->questions($step) as $key => $question) {
            if ($this->isVisible($question, $all, $result) && ($stepAnswers[$key] ?? null) !== null) {
                $kept[$key] = $stepAnswers[$key];
            }
        }

        return $kept;
    }

    /**
     * Save one step's answers on a version: replaces that step's answers only.
     *
     * @param  array<string, mixed>  $input  raw form input (answers[...])
     * @param  list<string>  $except  questions not asked (e.g. a restriction the data settles): not stored
     */
    public function save(ProductVersion $version, array $input, string $step, ?int $userId, array $except = []): void
    {
        $stepKeys = array_keys($this->questions($step));
        $others = $version->scopingAnswers()->whereNotIn('question', $stepKeys)->pluck('value', 'question')->all();
        $answers = array_diff_key($this->visibleOnly($this->read($input, $step), $others, $step), array_flip($except));

        DB::transaction(function () use ($version, $answers, $stepKeys, $userId) {
            $version->scopingAnswers()->whereIn('question', $stepKeys)->whereNotIn('question', array_keys($answers))->delete();
            foreach ($answers as $question => $value) {
                $version->scopingAnswers()->updateOrCreate(
                    ['question' => $question],
                    ['value' => $value, 'updated_by' => $userId],
                );
            }
        });
    }
}
