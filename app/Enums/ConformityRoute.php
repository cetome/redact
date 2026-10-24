<?php

namespace App\Enums;

/**
 * Conformity assessment procedure of the Radio Equipment Directive (Annexes II, III, IV).
 */
enum ConformityRoute: string
{
    /** Internal production control: self-assessment with harmonised standards. */
    case ModuleA = 'module_a';
    /** EU-type examination by a Notified Body, then conformity to type. */
    case ModuleBC = 'module_b_c';
    /** Full quality assurance, approved by a Notified Body. */
    case ModuleH = 'module_h';

    public function label(): string
    {
        return __('identification.routes.'.$this->value.'.label');
    }

    public function help(): string
    {
        return __('identification.routes.'.$this->value.'.help');
    }

    public function needsNotifiedBody(): bool
    {
        return $this !== self::ModuleA;
    }
}
