<?php

namespace App\Assessment;

/**
 * What changed in an evaluation since one review of it (see ReviewDiff).
 *
 * `recorded` is false when the evaluation changed but the reviewed state is unknown (a review
 * given before snapshots were kept, with no usable activity log). Each change holds the reviewed
 * value (`old`) and the current one (`new`); unchanged fields are null.
 */
final readonly class ReviewChanges
{
    /**
     * @param  'snapshot'|'activity'|null  $source
     * @param  array{old: string|null, new: string|null}|null  $verdict
     * @param  array{old: string|null, new: string|null}|null  $node
     * @param  array{old: list<array{node: string, answer: string}>, new: list<array{node: string, answer: string}>}|null  $path
     * @param  list<array{type: 'same'|'del'|'ins'|'gap', text: string}>|null  $justification
     */
    public function __construct(
        public bool $recorded,
        public ?string $source = null,
        public ?array $verdict = null,
        public ?array $node = null,
        public ?array $path = null,
        public ?array $justification = null,
    ) {}

    public static function unrecorded(): self
    {
        return new self(false);
    }

    /**
     * Names of the fields that changed, for summaries and tests.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return array_keys(array_filter([
            'verdict' => $this->verdict, 'node' => $this->node, 'path' => $this->path, 'justification' => $this->justification,
        ], fn ($change) => $change !== null));
    }
}
