<?php

namespace App\Reports;

use App\Assessment\Assessment;
use App\Content\ContentPack;
use App\Content\Text;
use App\Models\ProductVersion;
use App\Workbooks\Mapping;
use App\Workbooks\WorkbookExport;
use Illuminate\Support\Carbon;
use RuntimeException;
use ZipArchive;

/**
 * "Your cetome REDact workbooks": the owner's four templates (in the content pack) filled with a
 * version's data, one by one or all in a zip with a notes file.
 */
final class CetomeWorkbooks
{
    public function __construct(private readonly ContentPack $pack) {}

    public function mapping(): Mapping
    {
        return Mapping::fromPack($this->pack);
    }

    /**
     * The workbooks the pack can fill (mapping and template file present), in mapping order.
     *
     * @return list<array<string, mixed>>
     */
    public function available(): array
    {
        return array_values(array_filter(
            $this->mapping()->workbooks(),
            fn (array $workbook) => $this->pack->template((string) $workbook['key']) !== null,
        ));
    }

    /** @return array<string, mixed>|null */
    public function workbook(string $key): ?array
    {
        foreach ($this->available() as $workbook) {
            if ($workbook['key'] === $key) {
                return $workbook;
            }
        }

        return null;
    }

    /** "Smart lock 2.0 - cetome REDact - E.Info.xlsx" */
    public static function fileName(ProductVersion $version, array $workbook): string
    {
        return ExportName::plain([trim($version->product->name.' '.$version->label), 'cetome REDact', (string) ($workbook['name'] ?? $workbook['key'])], 'xlsx');
    }

    /**
     * Fill one workbook into $target.
     *
     * @param  array<string, mixed>  $workbook
     * @return array{applied: list<array{id: string, why: string}>, skipped: list<array{id: string, why: string}>, refused: list<string>, notes: list<string>}
     */
    public function fill(ProductVersion $version, array $workbook, string $target): array
    {
        $template = $this->pack->template((string) $workbook['key']) ?? throw new RuntimeException('No template.');
        $assessment = new Assessment($version, $this->pack);
        $plan = (new WorkbookExport($assessment, $this->mapping()))->build($workbook);
        $now = Carbon::now()->utc();

        $result = (new TemplateFiller)->fill($template, $workbook, $plan['writes'], [
            'REDact version' => (string) config('redact.release', 'dev'),
            'Content pack' => $this->pack->reference(),
            'Generated at' => $now->toIso8601ZuluString(),
            'Product' => (string) $version->product->name,
            'Product version' => (string) $version->label,
            'REDact notes' => $plan['notes'] === [] ? '' : implode(' | ', $plan['notes']),
        ], $target);

        return $result + ['notes' => $plan['notes']];
    }

    /**
     * Fill every available workbook into one zip, with "REDact notes.txt" (fixes applied and
     * left, rows that did not fit).
     *
     * @return list<string> the workbook keys included
     */
    public function zip(ProductVersion $version, string $target): array
    {
        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write the zip.');
        }
        $temporary = [];
        $notes = [__('workbooks.notes_file.title', ['product' => trim($version->product->name.' '.$version->label)]), ''];
        $keys = [];
        try {
            foreach ($this->available() as $workbook) {
                $path = tempnam(sys_get_temp_dir(), 'redact-wb-');
                if ($path === false) {
                    throw new RuntimeException('Cannot write the workbook.');
                }
                $temporary[] = $path;
                $result = $this->fill($version, $workbook, $path);
                $name = self::fileName($version, $workbook);
                $zip->addFile($path, $name);
                $keys[] = (string) $workbook['key'];
                $notes = [...$notes, ...self::notesFor($name, $result)];
            }
            $notes[] = __('workbooks.notes_file.left');
            foreach ($this->mapping()->leftForOwner() as $line) {
                $notes[] = '- '.$line;
            }
            $notes[] = '';
            $notes[] = __('workbooks.notes_file.recalculate');
            $zip->addFromString(__('workbooks.notes_file.name').'.txt', implode("\r\n", $notes)."\r\n");
            if (! $zip->close()) {
                throw new RuntimeException('Cannot write the zip.');
            }
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }

        return $keys;
    }

    /**
     * @param  array{applied: list<array{id: string, why: string}>, skipped: list<array{id: string, why: string}>, refused: list<string>, notes: list<string>}  $result
     * @return list<string>
     */
    public static function notesFor(string $name, array $result): array
    {
        $lines = [$name, str_repeat('=', min(80, mb_strlen($name)))];
        if ($result['applied'] !== []) {
            $lines[] = __('workbooks.notes_file.applied');
            foreach ($result['applied'] as $fix) {
                $lines[] = '- '.$fix['why'];
            }
        }
        if ($result['skipped'] !== []) {
            $lines[] = __('workbooks.notes_file.skipped');
            foreach ($result['skipped'] as $fix) {
                $lines[] = '- '.$fix['why'];
            }
        }
        foreach ($result['notes'] as $note) {
            $lines[] = '- '.$note;
        }
        if ($result['refused'] !== []) {
            $lines[] = '- '.__('workbooks.notes.refused', ['cells' => implode(', ', array_slice($result['refused'], 0, 10))]);
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * What the reports page says of each workbook: label, description, the fixes the mapping
     * applies, and the rows that will not fit for this version.
     *
     * @return list<array{key: string, label: string, description: string, fixes: list<string>}>
     */
    public function overview(): array
    {
        $out = [];
        foreach ($this->available() as $workbook) {
            $out[] = [
                'key' => (string) $workbook['key'],
                'label' => Text::of($workbook['label'] ?? $workbook['key']),
                'description' => Text::of($workbook['description'] ?? ''),
                'fixes' => array_map(fn (array $fix) => Text::of($fix['why'] ?? $fix['id']), (array) ($workbook['fixes'] ?? [])),
            ];
        }

        return $out;
    }
}
