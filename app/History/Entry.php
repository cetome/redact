<?php

namespace App\History;

use Carbon\CarbonInterface;

/**
 * One line of a customer history (App\History\Feed): who, when, what, in which version or product,
 * with the fields it changed. `others` holds the plain sentences of similar entries folded into
 * this one ("+12 more": a bulk import, a new version's copied items).
 */
final class Entry
{
    /** @var list<string> */
    public array $others = [];

    /**
     * @param  array{kind: 'person'|'support'|'system', name: string, email: ?string, initials: string}  $actor
     * @param  list<array{field: string, old: ?string, new: ?string, diff: ?list<array{type: string, text: string}>}>  $details
     */
    public function __construct(
        public readonly int $id,
        public readonly CarbonInterface $at,
        public readonly string $kind,
        public readonly string $html,
        public readonly string $text,
        public readonly array $actor,
        public readonly ?string $versionId,
        public readonly ?string $versionLabel,
        public readonly ?string $productId,
        public readonly ?string $productName,
        public readonly array $details,
        public readonly ?string $groupKey,
    ) {}

    public function icon(): string
    {
        return Describer::KINDS[$this->kind] ?? 'circle';
    }

    public function count(): int
    {
        return 1 + count($this->others);
    }
}
