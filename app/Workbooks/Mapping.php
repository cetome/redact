<?php

namespace App\Workbooks;

use App\Content\ContentPack;
use App\Content\Text;

/**
 * The content pack's workbook mapping (templates/mapping.json): which cell of each cetome
 * workbook holds which piece of a version. One mapping read both ways, by WorkbookExport (to
 * fill the templates) and WorkbookImport (to read people's workbooks back).
 *
 * Workbook: key, file, name, label, description, detect (sheet names), blocks, fixes.
 * Blocks: fields | scoping | list | matrix | risks | risk_matrix (see templates/README.md).
 */
final class Mapping
{
    /** @param  array<string, mixed>  $data */
    public function __construct(private readonly array $data) {}

    public static function fromPack(ContentPack $pack): self
    {
        return new self($pack->templateMapping());
    }

    /** @return list<array<string, mixed>> */
    public function workbooks(): array
    {
        return array_values(array_filter((array) ($this->data['workbooks'] ?? []), 'is_array'));
    }

    /** @return array<string, mixed>|null */
    public function workbook(string $key): ?array
    {
        foreach ($this->workbooks() as $workbook) {
            if (($workbook['key'] ?? null) === $key) {
                return $workbook;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(fn (array $w) => (string) $w['key'], $this->workbooks());
    }

    /**
     * A value map (app value => label in the workbook), by name or inline.
     *
     * @return array<string, string>
     */
    public function map(mixed $spec): array
    {
        if (is_string($spec)) {
            $spec = $this->data['values'][$spec] ?? [];
        }

        return is_array($spec) ? array_map('strval', $spec) : [];
    }

    /** The label of an app value ("true"/"false" for booleans). */
    public function label(mixed $spec, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        $key = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $map = $this->map($spec);

        return $map === [] ? $key : ($map[$key] ?? null);
    }

    /** The app value of a label read in a workbook (case and spaces ignored); null when unknown. */
    public function value(mixed $spec, string $label): string|bool|null
    {
        $wanted = self::normalise($label);
        if ($wanted === '') {
            return null;
        }
        foreach ($this->map($spec) as $value => $text) {
            if (self::normalise($text) === $wanted) {
                return match ((string) $value) {
                    'true' => true,
                    'false' => false,
                    default => (string) $value,
                };
            }
        }
        // Booleans also read as TRUE/FALSE or Yes/No
        if ($this->map($spec) !== [] && array_key_exists('true', $this->map($spec))) {
            return match ($wanted) {
                'true', 'yes', 'oui', '1', 'x' => true,
                'false', 'no', 'non', '0' => false,
                default => null,
            };
        }

        return null;
    }

    public static function normalise(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }

    public function nodeFormat(): string
    {
        return (string) ($this->data['nodes']['format'] ?? 'DT.{requirement}.{node}');
    }

    public function notWalked(): string
    {
        return (string) ($this->data['nodes']['not_walked'] ?? 'N/A');
    }

    public function node(string $requirement, string $node): string
    {
        return strtr($this->nodeFormat(), ['{requirement}' => $requirement, '{node}' => $node]);
    }

    /** "DT.ACM-1.DN-4" => "DN-4" when it names this requirement, else null. */
    public function parseNode(string $requirement, string $text): ?string
    {
        $text = preg_replace('/\s+/u', '', $text) ?? '';
        $pattern = '/^'.str_replace(['\{requirement\}', '\{node\}'], [preg_quote($requirement, '/'), '(DN-\d+)'], preg_quote($this->nodeFormat(), '/')).'$/i';
        if (preg_match($pattern, $text, $m) === 1) {
            return strtoupper($m[1]);
        }

        return preg_match('/^DN-\d+$/i', $text) === 1 ? strtoupper($text) : null;
    }

    public function einfoSeparator(): string
    {
        return (string) ($this->data['einfo_text']['separator'] ?? "\n\n");
    }

    public function einfoPrefix(string $code): string
    {
        return strtr((string) ($this->data['einfo_text']['prefix'] ?? '{code}: '), ['{code}' => $code]);
    }

    /** @return list<string> sentences in the current language */
    public function leftForOwner(): array
    {
        return array_values(array_map(fn ($line) => Text::of($line), (array) ($this->data['left_for_the_owner'] ?? [])));
    }

    /**
     * Regular expression of an E.Info pattern of the mapping (written after "E.Info.").
     */
    public static function einfoRegex(string $pattern): string
    {
        return '/^E\.Info\.(?:'.str_replace('/', '\/', $pattern).')$/';
    }

    /**
     * The relation of a links spec: "asset_entity" (the row's item is the "from" side) or
     * "asset_entity<" (the "to" side).
     *
     * @return array{0: string, 1: bool} relation, reverse
     */
    public static function relation(string $spec): array
    {
        return str_ends_with($spec, '<') ? [substr($spec, 0, -1), true] : [$spec, false];
    }
}
