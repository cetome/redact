<?php

namespace App\Sbom\Formats;

use DOMElement;

/** Namespace-agnostic helpers over DOM elements (BOM tools disagree on namespaces and versions). */
final class Xml
{
    /** @return list<DOMElement> */
    public static function children(DOMElement $parent, string $name): array
    {
        $found = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                $found[] = $node;
            }
        }

        return $found;
    }

    public static function child(DOMElement $parent, string $name): ?DOMElement
    {
        return self::children($parent, $name)[0] ?? null;
    }

    public static function text(DOMElement $parent, string ...$path): ?string
    {
        $node = $parent;
        foreach ($path as $name) {
            $node = self::child($node, $name);
            if (! $node) {
                return null;
            }
        }
        $text = trim($node->textContent);

        return $text === '' ? null : $text;
    }

    /** @return list<DOMElement> every descendant (and self) with this local name */
    public static function descendants(DOMElement $root, string $name): array
    {
        $found = $root->localName === $name ? [$root] : [];
        foreach ($root->getElementsByTagNameNS('*', $name) as $node) {
            if ($node !== $root) {
                $found[] = $node;
            }
        }

        return $found;
    }
}
