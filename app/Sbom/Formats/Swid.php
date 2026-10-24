<?php

namespace App\Sbom\Formats;

use App\Sbom\Component;
use DOMDocument;

/**
 * ISO/IEC 19770-2 software identification (SWID) tags: one tag per installed software,
 * uploaded as several files or a zip. 2015 schema (SoftwareIdentity, Entity roles) and the
 * older 2009 schema (software_identification_tag). Supplemental and patch tags describe
 * something else (a change to a product) and are left out.
 */
final class Swid
{
    public static function is(DOMDocument $dom): bool
    {
        $name = $dom->documentElement?->localName;

        return in_array($name, ['SoftwareIdentity', 'software_identification_tag'], true)
            || ($dom->documentElement !== null && Xml::descendants($dom->documentElement, 'SoftwareIdentity') !== []);
    }

    /** @return list<Component> */
    public static function parse(DOMDocument $dom): array
    {
        $root = $dom->documentElement;
        if (! $root) {
            return [];
        }
        if ($root->localName === 'software_identification_tag') {
            $component = Component::make(
                name: Xml::text($root, 'product_title'),
                version: Xml::text($root, 'product_version', 'name'),
                supplier: Xml::text($root, 'software_creator', 'name') ?? Xml::text($root, 'tag_creator', 'name'),
                type: 'software',
                ref: Xml::text($root, 'software_id', 'unique_id'),
            );

            return $component ? [$component] : [];
        }

        $out = [];
        foreach (Xml::descendants($root, 'SoftwareIdentity') as $tag) {
            if (in_array(strtolower($tag->getAttribute('supplemental')), ['true', '1'], true)
                || in_array(strtolower($tag->getAttribute('patch')), ['true', '1'], true)) {
                continue;
            }
            $creator = $tagCreator = null;
            foreach (Xml::children($tag, 'Entity') as $entity) {
                $roles = preg_split('/\s+/', strtolower($entity->getAttribute('role'))) ?: [];
                if (in_array('softwarecreator', $roles, true)) {
                    $creator ??= $entity->getAttribute('name');
                }
                if (in_array('tagcreator', $roles, true) || in_array('licensor', $roles, true)) {
                    $tagCreator ??= $entity->getAttribute('name');
                }
            }
            $meta = Xml::child($tag, 'Meta');
            $component = Component::make(
                name: $tag->getAttribute('name'),
                version: $tag->getAttribute('version') ?: null,
                supplier: $creator ?: $tagCreator,
                type: 'software',
                description: $meta?->getAttribute('summary') ?: ($meta?->getAttribute('description') ?: null),
                ref: $tag->getAttribute('tagId') ?: null,
            );
            if ($component) {
                $out[] = $component;
            }
        }

        return $out;
    }
}
