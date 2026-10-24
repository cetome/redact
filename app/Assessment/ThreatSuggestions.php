<?php

namespace App\Assessment;

use App\Content\Text;

/**
 * Which threats of the pack's library to propose for a version, from its scoping:
 *  - "answer": one of the threat's "suggest_when" questions was answered yes (pre-ticked);
 *  - "standard": no such link, but one of its articles is in scope (listed, not ticked);
 *  - threats already in the register (same name) are flagged, never suggested.
 * Rows come back in that order (more matching answers first), keeping the library index.
 */
class ThreatSuggestions
{
    public function __construct(private readonly Assessment $assessment) {}

    /**
     * @return list<array{index: int, threat: array<string, mixed>, name: string, suggested: bool, strength: 'answer'|'standard'|null, reasons: list<string>, added: bool}>
     */
    public function rows(): array
    {
        $library = $this->assessment->pack->risk()['threats'] ?? [];
        $articles = array_keys(array_filter($this->assessment->scoping()->articles));
        $answers = $this->assessment->version->scopingAnswers()->pluck('value', 'question')->all();
        $questions = app(ScopingForm::class)->questions();
        $taken = $this->assessment->version->riskEntries()->pluck('threat')
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))->flip();

        $rows = [];
        foreach ($library as $index => $threat) {
            $names = is_array($threat['threat'] ?? null) ? array_values($threat['threat']) : [(string) ($threat['threat'] ?? '')];
            $added = collect($names)->contains(fn ($n) => isset($taken[mb_strtolower(trim((string) $n))]));
            $byArticle = array_intersect($threat['articles'] ?? [], $articles) !== [];
            $yes = array_values(array_filter($threat['suggest_when'] ?? [], fn ($q) => ($answers[$q] ?? false) === true));

            $strength = null;
            if (! $added && $byArticle) {
                $strength = $yes !== [] ? 'answer' : (($threat['suggest_when'] ?? []) === [] ? 'standard' : null);
            }
            $rows[] = [
                'index' => $index,
                'threat' => $threat,
                'name' => Text::of($threat['threat'] ?? ''),
                'suggested' => $strength !== null,
                'strength' => $strength,
                'reasons' => array_map(fn ($q) => __('inventory.reason_answer', ['answer' => Text::of($questions[$q]['label'] ?? $q)]), $yes),
                'added' => $added,
                'matches' => count($yes),
            ];
        }

        $tier = fn (array $r) => match ($r['strength']) {
            'answer' => 0, 'standard' => 1, default => $r['added'] ? 3 : 2
        };
        usort($rows, fn ($a, $b) => [$tier($a), -$a['matches'], $a['index']] <=> [$tier($b), -$b['matches'], $b['index']]);

        return array_map(function (array $r) {
            unset($r['matches']);

            return $r;
        }, $rows);
    }
}
