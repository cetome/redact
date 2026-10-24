<?php

namespace App\Enums;

/**
 * Role of a person on one product (all versions of it). Same model as CRAted.
 */
enum ProductRole: string
{
    case Owner = 'owner';
    case Contributor = 'contributor';
    case Reviewer = 'reviewer';
    /** External (e.g. a test laboratory engineer): records the assessment units, nothing else. */
    case Assessor = 'assessor';

    public function label(): string
    {
        return __('roles.'.$this->value.'.label');
    }

    public function help(): string
    {
        return __('roles.'.$this->value.'.help');
    }
}
