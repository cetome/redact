<?php

namespace App\Declaration;

use App\Enums\VersionStatus;
use App\Models\ProductVersion;
use App\Support\ProductAccess;

/**
 * Who may change the Declaration step. The declaration is written while the version is a draft
 * (the editors, as any step) and completed once it is approved: the assessment is then locked,
 * but the owner and the organisation's administrators still fill the declaration and record the
 * Notified Body's certificate. Nothing changes while the version is in review or archived.
 */
final class DeclarationAccess
{
    public static function canEdit(ProductAccess $access, ProductVersion $version): bool
    {
        return match ($version->status) {
            VersionStatus::Draft => $access->canEdit($version),
            VersionStatus::Approved => $access->canManage(),
            default => false,
        };
    }

    /** The certificate exists only once the Notified Body issued it, after the review. */
    public static function canRecordCertificate(ProductAccess $access, ProductVersion $version): bool
    {
        return $version->status === VersionStatus::Approved
            && (bool) $version->conformity_route?->needsNotifiedBody()
            && $access->canManage();
    }

    /** Filing documents under the Annex V headings: same rule as the declaration. */
    public static function canFile(ProductAccess $access, ProductVersion $version): bool
    {
        return self::canEdit($access, $version);
    }
}
