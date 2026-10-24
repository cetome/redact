<?php

namespace App\Content;

/**
 * Outcome of the scoping questionnaire, computed by the content pack rules.
 */
final readonly class ScopingResult
{
    /**
     * @param  array<string, bool>  $articles  e.g. ["3.3d" => true, "3.3e" => false, "3.3f" => false]
     * @param  list<string>  $standards  standard codes in scope, e.g. ["EN18031-1"]
     * @param  array<string, bool>  $restrictions  restriction key => satisfied
     * @param  bool|null  $selfAssessment  null while the questionnaire is incomplete
     * @param  list<string>  $messages  explanations to show (already localised)
     */
    public function __construct(
        public array $articles,
        public array $standards,
        public array $restrictions = [],
        public ?bool $selfAssessment = null,
        public array $messages = [],
    ) {}

    public function inScope(string $standard): bool
    {
        return in_array($standard, $this->standards, true);
    }
}
