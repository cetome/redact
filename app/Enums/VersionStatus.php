<?php

namespace App\Enums;

enum VersionStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Archived = 'archived';

    public function label(): string
    {
        return __('versions.status.'.$this->value);
    }

    /** Assessment data can only change while the version is a draft. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
