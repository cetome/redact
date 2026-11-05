<?php

namespace App\Billing;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * One plan of the catalogue above Free (Premium, Enterprise, Business...): what it includes. Built-in
 * plans come from config redact.plans, edited in the console; others are made in the console.
 */
final class PlanDefinition
{
    /**
     * @param  ?int  $products  products included, the free one counted (null = no limit)
     * @param  ?int  $teamMembers  per product besides its owner (null = no limit)
     * @param  int  $extraProductAiUnits  AI units each additional product adds
     * @param  bool  $public  offered on the pricing page with online payment (else staff set it)
     * @param  array<string, string>  $names  per locale (custom plans; built-in ones use lang plans.name)
     */
    public function __construct(
        public readonly string $key,
        public readonly ?int $products,
        public readonly ?int $teamMembers,
        public readonly int $aiUnits,
        public readonly string $aiPeriod,
        public readonly int $extraProductAiUnits,
        public readonly bool $support,
        public readonly bool $public,
        public readonly bool $active,
        public readonly bool $builtIn,
        public readonly array $names = [],
    ) {}

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $name = trim((string) ($this->names[$locale] ?? ''));
        if ($name !== '') {
            return $name;
        }
        if (Lang::has('plans.name.'.$this->key, $locale)) {
            return (string) __('plans.name.'.$this->key, [], $locale);
        }

        return trim((string) ($this->names['en'] ?? '')) ?: Str::headline($this->key);
    }

    public function unlimited(): bool
    {
        return $this->products === null;
    }

    /** Products the plan adds to the free allowance. */
    public function slotsBeyond(int $free): int
    {
        return $this->products === null ? 0 : max(0, $this->products - $free);
    }
}
