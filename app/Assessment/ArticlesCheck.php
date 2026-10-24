<?php

namespace App\Assessment;

use App\Content\Text;
use App\Models\ProductVersion;
use App\Models\RiskEntry;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "Essential requirements" step (route key "scoping"): the essential requirements (articles 3.3
 * d/e/f) and the parts of EN 18031 the scoping answers bring in, confirmed by a person. The
 * confirmation keeps a fingerprint of the scoping only (articles and parts in scope), so a later
 * scoping change that alters them puts the step back to "to re-confirm"; the risks, assessed after
 * this step, never do.
 *
 * The risk assessment page shows the risks against them (articles(), mismatches(), coverage(),
 * namedOutsideScope()): each risk names the articles it concerns and the EN 18031 mechanisms,
 * families or requirements, that cover it. Never blocking, informative.
 */
class ArticlesCheck
{
    /** @var Collection<int, RiskEntry>|null */
    private ?Collection $risks = null;

    public function __construct(private readonly Assessment $assessment) {}

    public static function for(ProductVersion $version): self
    {
        return new self(Assessment::for($version));
    }

    public function answered(): bool
    {
        return $this->assessment->version->scopingAnswers()
            ->whereIn('question', array_keys(app(ScopingForm::class)->questions(ScopingForm::SCOPING)))->exists();
    }

    /**
     * The register, numbered as on the risk page (1, 2, …).
     *
     * @return Collection<int, RiskEntry>
     */
    public function risks(): Collection
    {
        return $this->risks ??= $this->assessment->version->riskEntries()->get(['id', 'position', 'threat', 'articles', 'requirements'])->values();
    }

    /**
     * One row per article of the pack: in scope by the answers (null before any answer), the risks
     * that concern it, the scoping cards that bring it in, and the mismatch if any:
     * "risks_out_of_scope" (risks concern it, the answers leave it out) or "no_risk" (in scope, no risk).
     *
     * @return list<array{code: string, name: string, in_scope: bool|null, risks: list<array{id: string, n: int, threat: string}>, questions: list<array{key: string, label: string}>, issue: string|null}>
     */
    public function articles(): array
    {
        $answered = $this->answered();
        $scoped = $this->assessment->scoping()->articles;
        $questions = app(ScopingForm::class)->questions(ScopingForm::SCOPING);

        $rows = [];
        foreach ($this->assessment->pack->articles() as $code => $name) {
            $code = (string) $code;
            $inScope = $answered ? (bool) ($scoped[$code] ?? false) : null;
            $risks = $this->risks()
                ->filter(fn (RiskEntry $r) => in_array($code, (array) $r->articles, true))
                ->map(fn (RiskEntry $r, int $i) => ['id' => $r->id, 'n' => $i + 1, 'threat' => (string) $r->threat])
                ->values()->all();
            $cards = [];
            foreach ($questions as $key => $question) {
                if (($question['type'] ?? 'boolean') === 'boolean' && in_array($code, $question['articles'] ?? [], true)) {
                    $cards[] = ['key' => (string) $key, 'label' => Text::of($question['label'] ?? $key)];
                }
            }
            $issue = match (true) {
                $inScope === false && $risks !== [] => 'risks_out_of_scope',
                $inScope === true && $risks === [] => 'no_risk',
                default => null,
            };
            $rows[] = ['code' => $code, 'name' => $name, 'in_scope' => $inScope, 'risks' => $risks, 'questions' => $cards, 'issue' => $issue];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> the rows of articles() that have a mismatch */
    public function mismatches(): array
    {
        return array_values(array_filter($this->articles(), fn (array $row) => $row['issue'] !== null));
    }

    /**
     * Per EN 18031 part in scope: its requirements the risks name as mitigations (directly, or
     * through their family) with those risks, and the ones no risk names (informative).
     *
     * @return list<array{code: string, name: string, article: string, total: int,
     *     covered: list<array{code: string, title: string, risks: list<array{id: string, n: int, threat: string, via: string|null}>}>,
     *     uncovered: list<array{code: string, title: string}>}>
     */
    public function coverage(): array
    {
        $pack = $this->assessment->pack;
        $parts = [];
        foreach ($this->assessment->standards() as $standard) {
            $covered = [];
            $uncovered = [];
            $requirements = $pack->requirementsFor($standard);
            foreach ($requirements as $code => $requirement) {
                $code = (string) $code;
                $family = (string) ($requirement['family'] ?? '');
                $risks = [];
                foreach ($this->risks() as $i => $risk) {
                    $named = (array) $risk->requirements;
                    if (in_array($code, $named, true)) {
                        $risks[] = ['id' => $risk->id, 'n' => $i + 1, 'threat' => (string) $risk->threat, 'via' => null];
                    } elseif ($family !== '' && in_array($family, $named, true)) {
                        $risks[] = ['id' => $risk->id, 'n' => $i + 1, 'threat' => (string) $risk->threat, 'via' => $family];
                    }
                }
                $title = Text::of($requirement['title'] ?? '');
                if ($risks !== []) {
                    $covered[] = ['code' => $code, 'title' => $title, 'risks' => $risks];
                } else {
                    $uncovered[] = ['code' => $code, 'title' => $title];
                }
            }
            $parts[] = [
                'code' => $standard,
                'name' => $pack->standardName($standard),
                'article' => (string) ($pack->standards()[$standard]['article'] ?? ''),
                'total' => count($requirements),
                'covered' => $covered,
                'uncovered' => $uncovered,
            ];
        }

        return $parts;
    }

    /**
     * Mechanisms the risks name that belong to no part in scope (e.g. a requirement of EN 18031-3
     * while 3.3 f is out): informative, they cover nothing assessed in this version.
     *
     * @return list<string>
     */
    public function namedOutsideScope(): array
    {
        $pack = $this->assessment->pack;
        $inScope = $this->assessment->standards();
        $families = [];
        foreach ($pack->requirements() as $requirement) {
            if (array_intersect($requirement['standards'] ?? [], $inScope) !== []) {
                $families[(string) ($requirement['family'] ?? '')] = true;
            }
        }
        $outside = [];
        foreach ($this->risks() as $risk) {
            foreach ((array) $risk->requirements as $code) {
                $requirement = $pack->requirement((string) $code);
                $in = $requirement !== null
                    ? array_intersect($requirement['standards'] ?? [], $inScope) !== []
                    : isset($families[(string) $code]);
                if (! $in) {
                    $outside[(string) $code] = true;
                }
            }
        }

        return array_keys($outside);
    }

    /**
     * What a confirmation vouches for: the articles and parts in scope by the scoping answers. The
     * risks come after this step and are left out: editing them never asks for a new confirmation.
     */
    public function fingerprint(): string
    {
        $answered = $this->answered();
        $scoped = $this->assessment->scoping()->articles;
        $articles = [];
        foreach (array_keys($this->assessment->pack->articles()) as $code) {
            $articles[(string) $code] = $answered ? (bool) ($scoped[(string) $code] ?? false) : null;
        }
        ksort($articles);

        return hash('sha256', (string) json_encode(['articles' => $articles, 'standards' => $this->assessment->standards()]));
    }

    /** unanswered | unconfirmed | stale (confirmed, then the comparison changed) | confirmed */
    public function state(): string
    {
        $version = $this->assessment->version;
        if (! $this->answered()) {
            return 'unanswered';
        }
        if (! $version->articles_confirmed_at) {
            return 'unconfirmed';
        }

        return hash_equals((string) $version->articles_confirmed_hash, $this->fingerprint()) ? 'confirmed' : 'stale';
    }

    public function confirmed(): bool
    {
        return $this->state() === 'confirmed';
    }

    /** Record the confirmation of what the page shows now (logged in the version's activity). */
    public function confirm(User $user): void
    {
        $version = $this->assessment->version;
        $version->forceFill([
            'articles_confirmed_at' => now(),
            'articles_confirmed_by' => $user->id,
            'articles_confirmed_hash' => $this->fingerprint(),
        ])->saveQuietly();

        activity('version')->performedOn($version)->causedBy($user)->withProperties([
            'articles' => array_values(array_map(fn (array $r) => $r['code'], array_filter($this->articles(), fn (array $r) => $r['in_scope'] === true))),
            'standards' => $this->assessment->standards(),
        ])->log('articles_confirmed');
    }
}
