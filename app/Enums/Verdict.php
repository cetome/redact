<?php

namespace App\Enums;

/**
 * Outcome of an EN 18031 decision tree, as printed in the standard, plus INCONCLUSIVE: not a
 * verdict of EN 18031 (no tree leads to it), set by a person while evidence is missing, always
 * with a justification, and to resolve before the version can be approved.
 */
enum Verdict: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';
    case NotApplicable = 'NOT_APPLICABLE';
    case Inconclusive = 'INCONCLUSIVE';

    /** @return list<self> the verdicts a decision tree can reach (the standard's three) */
    public static function standard(): array
    {
        return [self::Pass, self::Fail, self::NotApplicable];
    }

    /** The label printed in the standard's result boxes. */
    public static function fromTreeResult(string $result): self
    {
        return match ($result) {
            'PASS' => self::Pass,
            'FAIL' => self::Fail,
            'NOT APPLICABLE' => self::NotApplicable,
            default => throw new \InvalidArgumentException("Unknown decision tree result: {$result}"),
        };
    }

    public function label(): string
    {
        return __('verdicts.'.$this->value);
    }
}
