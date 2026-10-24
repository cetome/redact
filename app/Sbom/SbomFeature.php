<?php

namespace App\Sbom;

use App\Content\ContentPack;
use App\Models\ProductVersion;
use App\Models\User;
use App\Support\ProductAccess;

/**
 * Where the bill-of-materials import and the vulnerability check live in the content pack
 * (config/sbom.php: the component list, the vulnerability list and the link between them),
 * and who may use them.
 *
 * Guests (sandbox) may import but not call the public databases: like the AI, an outside
 * service is only used for real accounts (the sandbox has no terms accepted, no rate history).
 */
final class SbomFeature
{
    public static function componentsList(): string
    {
        return (string) config('sbom.lists.components', 'software');
    }

    public static function vulnerabilitiesList(): string
    {
        return (string) config('sbom.lists.vulnerabilities', 'vulnerabilities');
    }

    public static function relation(): string
    {
        return (string) config('sbom.lists.relation', 'vulnerability_component');
    }

    /** The pack has a component list: the import is offered. */
    public static function importable(?ContentPack $pack = null): bool
    {
        $pack ??= app(ContentPack::class);

        return isset($pack->lists()[self::componentsList()]);
    }

    /** The pack also has the vulnerability list and the link: the check is offered. */
    public static function checkable(?ContentPack $pack = null): bool
    {
        $pack ??= app(ContentPack::class);
        $relation = $pack->relations()[self::relation()] ?? null;

        return self::importable($pack) && isset($pack->lists()[self::vulnerabilitiesList()])
            && $relation !== null && $relation['from'] === self::vulnerabilitiesList() && $relation['to'] === self::componentsList();
    }

    public static function isComponentList(string $list): bool
    {
        return $list === self::componentsList() && self::importable();
    }

    /** The Known vulnerabilities list, where VEX files are imported too. */
    public static function isVulnerabilityList(string $list): bool
    {
        return $list === self::vulnerabilitiesList() && self::checkable();
    }

    /** Why this person cannot run a check now (translated), or null when they can. */
    public static function checkRefusal(User $user, ProductVersion $version): ?string
    {
        if (! config('sbom.checks.enabled')) {
            return __('sbom.check.disabled');
        }
        if ($user->is_guest) {
            return __('sbom.check.guest');
        }
        if (! ProductAccess::for($user, $version->product)->canEdit($version)) {
            return __('sbom.check.read_only');
        }

        return null;
    }
}
