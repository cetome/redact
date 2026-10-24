<?php

namespace App\Sbom;

/**
 * One component read from a bill of materials, cleaned and capped: what the preview shows
 * and what becomes (or updates) an object of the component list.
 */
final readonly class Component
{
    public function __construct(
        public string $name,
        public ?string $version = null,
        public ?string $supplier = null,
        public string $type = 'software',
        public ?string $purl = null,
        public ?string $cpe = null,
        public ?string $licence = null,
        public ?string $description = null,
        public ?string $ref = null,
        public bool $root = false,
    ) {}

    /** Build from raw values: trims, strips control characters, caps lengths, drops invalid identifiers. */
    public static function make(
        mixed $name,
        mixed $version = null,
        mixed $supplier = null,
        mixed $type = null,
        mixed $purl = null,
        mixed $cpe = null,
        mixed $licence = null,
        mixed $description = null,
        mixed $ref = null,
        bool $root = false,
    ): ?self {
        $name = self::clean($name, 200);
        $purl = Identifiers::purl($purl);
        if ($name === null && $purl !== null) {
            $name = Identifiers::nameFromPurl($purl);
        }
        if ($name === null) {
            return null;
        }
        $version = self::clean($version, 100);
        if ($version === null && $purl !== null) {
            $version = Identifiers::versionFromPurl($purl);
        }

        return new self(
            name: $name,
            version: $version,
            supplier: self::clean($supplier, 300),
            type: self::type($type),
            purl: $purl,
            cpe: Identifiers::cpe($cpe),
            licence: self::clean($licence, 200),
            description: self::clean($description, 2000, true),
            ref: self::clean($ref, 200),
            root: $root,
        );
    }

    public static function clean(mixed $value, int $max, bool $multiline = false): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = $multiline
            ? (preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '')
            : (preg_replace('/\p{C}+/u', ' ', $value) ?? '');
        $value = trim($multiline ? $value : (preg_replace('/\s+/u', ' ', $value) ?? ''));
        if ($value === '' || in_array(strtoupper($value), ['NOASSERTION', 'NONE', 'N/A', 'UNKNOWN', '-'], true)) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /** software | hardware, from the words BOM formats and spreadsheets use. */
    public static function type(mixed $value): string
    {
        $value = mb_strtolower(trim((string) (is_scalar($value) ? $value : '')));
        $value = strtr($value, ['é' => 'e', 'è' => 'e']);

        return in_array($value, ['hardware', 'hw', 'device', 'materiel', 'chip', 'processor', 'soc', 'board', 'module', 'h'], true)
            ? 'hardware' : 'software';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'version' => $this->version,
            'supplier' => $this->supplier,
            'type' => $this->type,
            'purl' => $this->purl,
            'cpe' => $this->cpe,
            'licence' => $this->licence,
            'description' => $this->description,
            'ref' => $this->ref,
            'root' => $this->root ?: null,
        ], fn ($v) => $v !== null);
    }

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        return new self(
            name: (string) ($row['name'] ?? ''),
            version: $row['version'] ?? null,
            supplier: $row['supplier'] ?? null,
            type: ($row['type'] ?? 'software') === 'hardware' ? 'hardware' : 'software',
            purl: $row['purl'] ?? null,
            cpe: $row['cpe'] ?? null,
            licence: $row['licence'] ?? null,
            description: $row['description'] ?? null,
            ref: $row['ref'] ?? null,
            root: (bool) ($row['root'] ?? false),
        );
    }

    /** Same object: name and version, case and spacing ignored. */
    public function key(): string
    {
        return self::keyOf($this->name, $this->version);
    }

    public static function keyOf(?string $name, ?string $version): string
    {
        $norm = fn (?string $v) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $v)) ?? '');

        return $norm($name).'|'.$norm($version);
    }
}
