<?php

namespace App\Declaration;

use App\Assessment\ReviewRights;
use App\Enums\ConformityRoute;
use App\Enums\VersionStatus;
use App\Models\Document;
use App\Models\ProductVersion;
use App\Models\SbomImport;
use App\Models\User;
use App\Models\VexImport;
use App\Models\VulnerabilityCheck;
use App\Models\VulnerabilityFinding;
use App\Reports\CetomeWorkbooks;
use App\Reports\ExcelReport;
use App\Reports\ExportName;
use App\Reports\ReportData;
use App\Reports\ReportKind;
use App\Reports\WordReport;
use App\Sbom\SbomFeature;
use App\Sbom\Vex\VexStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * The technical documentation of a version (Directive 2014/53/EU, Annex V), as one zip built on
 * demand: one numbered folder per point of Annex V, an index (Word) and a README listing every
 * item with its source and status (present, missing, draft, not applicable).
 *
 * checklist() is what the Declaration step shows; build() writes the zip. What REDact produces
 * (the RED reports, the declaration, the product description) is generated at that moment; what
 * it does not (drawings, test reports of 3.1(a), 3.1(b), 3.2, user manual) comes from the
 * documents of the version, filed under a heading by their category.
 */
final class TechnicalFile
{
    /** The SHA-256 checksum of every file of the zip, at its root (sha256sum format). */
    public const SUMS = 'SHA256SUMS.txt';

    /** Annex V points, in order: a, a(i), a(ii), a(iii), b to i, then documents not filed yet. */
    public const HEADINGS = ['a', 'a_i', 'a_ii', 'a_iii', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'other'];

    /** Categories a person can give a document, and the Annex V point each one files it under. */
    public const CATEGORIES = [
        'general' => 'a', 'photographs' => 'a_i', 'software' => 'a_ii', 'user_information' => 'a_iii',
        'drawings' => 'b', 'descriptions' => 'c', 'standards' => 'd', 'calculations' => 'g',
        'test_reports' => 'h', 'article_10' => 'i', 'other' => 'other',
    ];

    /** The category of the Notified Body's certificate (recorded on the step, not chosen). */
    public const CERTIFICATE = 'certificate';

    /** @var list<array<string, mixed>>|null */
    private ?array $items = null;

    /** @var Collection<int, Document>|null */
    private ?Collection $documents = null;

    public function __construct(public readonly DeclarationData $data) {}

    public static function for(ProductVersion $version): self
    {
        return new self(DeclarationData::for($version));
    }

    public function version(): ProductVersion
    {
        return $this->data->version();
    }

    /** "01 (a) General description/(i) Photographs and illustrations" */
    public static function folder(string $heading): string
    {
        $own = __('declaration.file.folders.'.$heading);

        return str_starts_with($heading, 'a_') ? __('declaration.file.folders.a').'/'.$own : $own;
    }

    /** @return Collection<int, Document> */
    public function documents(): Collection
    {
        return $this->documents ??= $this->version()->documents()->orderBy('created_at')
            ->get(['id', 'product_version_id', 'original_name', 'mime', 'size', 'path', 'category', 'created_at']);
    }

    /** The heading a document is filed under. */
    public function headingOf(Document $document): string
    {
        if ($document->category === self::CERTIFICATE || $document->id === $this->version()->certificate_document_id) {
            return 'f';
        }

        return self::CATEGORIES[$document->category ?? ''] ?? 'other';
    }

    // ---- Checklist ------------------------------------------------------------------------------

    /**
     * Every item of the technical file: its Annex V heading, label, source, status, the file it
     * becomes in the zip and where to fix it.
     *
     * @return list<array{heading: string, label: string, source: string, status: string, file: string|null, note: string, fix: array{href: string, label: string}|null, make: array<string, mixed>}>
     */
    public function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }
        $data = $this->data;
        $version = $this->version();
        $assessment = $data->assessment;
        $product = $version->product;
        $items = [];
        $add = function (string $heading, string $label, string $source, string $status, ?string $file, array $make, ?array $fix = null, string $note = '') use (&$items) {
            $items[] = ['heading' => $heading, 'label' => $label, 'source' => $source, 'status' => $status,
                'file' => $file === null ? null : self::folder($heading).'/'.$file, 'note' => $note, 'fix' => $fix, 'make' => $make];
        };
        $fix = fn (string $href, string $label) => ['href' => $href, 'label' => $label];
        $generated = __('declaration.file.source.generated');
        $uploaded = __('declaration.file.source.uploaded');
        $upload = $fix(route('versions.declaration-file', $version).'#documents', __('declaration.file.fix.upload'));

        // (a) General description: the product, its identifiers and software versions.
        $described = filled($product->description) && $version->identification()->isNotEmpty();
        $add('a', __('declaration.file.items.description'), $generated, $described ? 'present' : 'missing',
            __('declaration.file.names.description').'.docx', ['type' => 'description'],
            $described ? null : $fix($assessment->stepUrl('description'), __('declaration.file.fix.description')));

        // (a)(i) Photographs: the product picture.
        $picture = $data->imagePath();
        $add('a_i', __('declaration.file.items.picture'), __('declaration.file.source.product'), $picture ? 'present' : 'missing',
            $picture ? __('declaration.file.names.picture').'.png' : null, ['type' => 'path', 'path' => $picture],
            $picture ? null : $fix($assessment->stepUrl('description').'#picture-title', __('declaration.file.fix.picture')));

        // (a)(ii) Software and firmware versions: the components of the inventory, the bills of materials.
        $software = $data->softwareComponents();
        $versions = collect($data->identifiers())->whereIn('kind', ['software_version', 'fvin'])->isNotEmpty();
        $componentsHref = SbomFeature::importable($assessment->pack)
            ? route('versions.inventory', [$version, SbomFeature::componentsList()])
            : $assessment->stepUrl('description');
        $add('a_ii', __('declaration.file.items.software'), __('declaration.file.source.inventory'), $software !== [] || $versions ? 'present' : 'missing',
            null, ['type' => 'none'], $software !== [] || $versions ? null : $fix($componentsHref, __('declaration.file.fix.software')),
            __('declaration.file.notes.in_description'));
        $imports = SbomImport::query()->where('product_version_id', $version->id)->orderBy('created_at')->get();
        $sbomFiles = 0;
        foreach ($imports as $import) {
            foreach ((array) $import->files as $n => $file) {
                $sbomFiles++;
                $add('a_ii', __('declaration.file.items.sbom', ['name' => $file['name']]), __('declaration.file.source.sbom'), 'present',
                    __('declaration.file.names.sbom').'/'.self::safe($import->created_at->format('Ymd').' '.$n.' '.$file['name']),
                    ['type' => 'disk', 'path' => $file['path']]);
            }
        }
        if ($sbomFiles === 0 && SbomFeature::importable($assessment->pack)) {
            $add('a_ii', __('declaration.file.items.sbom_none'), __('declaration.file.source.sbom'), 'na', null, ['type' => 'none'],
                Route::has('versions.sbom') ? $fix(route('versions.sbom', $version), __('declaration.file.fix.sbom')) : null);
        }

        // (d) Standards applied: the parts of EN 18031 (assessed), the others (entered, not assessed).
        $standardsHref = route('versions.declaration', $version).'#standards';
        $add('d', __('declaration.file.items.standards'), $generated, $data->assessedStandards() !== [] ? 'present' : 'missing',
            __('declaration.file.names.standards').'.docx', ['type' => 'standards'],
            $data->assessedStandards() !== [] ? null : $fix($assessment->stepUrl('scoping'), __('declaration.file.fix.scoping')));
        foreach (['3.1a', '3.1b', '3.2'] as $article) {
            $entered = $data->otherStandardsFor($article) !== [];
            $add('d', __('declaration.file.items.other_standards', ['article' => DeclarationData::articleLabel($article)]), __('declaration.file.source.entered'),
                $entered ? 'present' : 'missing', null, ['type' => 'none'], $entered ? null : $fix($standardsHref, __('declaration.file.fix.standards')),
                __('declaration.file.notes.not_assessed'));
        }

        // (e) The EU declaration of conformity and its simplified form.
        $declarationStatus = $data->isDraft() || $data->missing() !== [] ? 'draft' : 'present';
        foreach (['docx' => 'declaration', 'pdf' => 'declaration_pdf'] as $extension => $item) {
            $add('e', __('declaration.file.items.'.$item), $generated, $declarationStatus,
                __('declaration.file.names.declaration').'.'.$extension, ['type' => 'declaration', 'format' => $extension],
                $declarationStatus === 'present' ? null : $fix(route('versions.declaration', $version), __('declaration.file.fix.declaration')),
                (string) $data->watermark());
        }
        $add('e', __('declaration.file.items.simplified'), $generated, $data->stored('url') !== null ? 'present' : 'draft',
            __('declaration.file.names.simplified').'.txt', ['type' => 'simplified'],
            $data->stored('url') !== null ? null : $fix(route('versions.declaration', $version).'#simplified', __('declaration.file.fix.url')));

        // (f) The EU-type examination certificate (Module B), or the quality system approval (Module H).
        $route = $version->conformity_route;
        if ($route === null) {
            $add('f', __('declaration.file.items.certificate'), __('declaration.file.source.notified_body'), 'missing', null, ['type' => 'none'],
                $fix($assessment->stepUrl('conformity'), __('declaration.file.fix.route')));
        } elseif ($route === ConformityRoute::ModuleA) {
            $add('f', __('declaration.file.items.certificate'), __('declaration.file.source.notified_body'), 'na', null, ['type' => 'none'], null,
                __('declaration.file.notes.module_a'));
        } else {
            $certificate = $version->certificate_document_id ? $this->documents()->firstWhere('id', $version->certificate_document_id) : null;
            $add('f', __('declaration.file.items.certificate'), __('declaration.file.source.notified_body'), $certificate ? 'present' : 'missing',
                $certificate ? self::safe($certificate->original_name) : null, ['type' => 'document', 'id' => $certificate?->id],
                $certificate ? null : $fix(route('versions.declaration', $version).'#certificate', __('declaration.file.fix.certificate')),
                $route === ConformityRoute::ModuleH ? __('declaration.file.notes.module_h') : '');
        }

        // (g) Examinations carried out: the RED assessment.
        $base = trim($product->name.' '.$version->label);
        foreach ([ReportKind::Assessment, ReportKind::Trees] as $kind) {
            foreach ($kind->formats() as $format) {
                $add('g', $kind->label().' ('.__('reports.formats.'.$format).')', $generated, 'present',
                    ExportName::make([$base, $kind->fileContent()], $format), ['type' => 'report', 'kind' => $kind->value, 'format' => $format]);
            }
        }
        $risks = $version->riskEntries()->count();
        $add('g', __('declaration.file.items.risks'), __('declaration.file.source.report'), $risks > 0 ? 'present' : 'missing', null, ['type' => 'none'],
            $risks > 0 ? null : $fix($assessment->stepUrl('risks'), __('declaration.file.fix.risks')), __('declaration.file.notes.in_assessment'));
        $justified = $data->openItems() === [] || collect($data->openItems())->whereIn('key', ['fail', 'inconclusive', 'unjustified', 'remaining'])->isEmpty();
        $add('g', __('declaration.file.items.trees'), __('declaration.file.source.report'), $justified ? 'present' : 'draft', null, ['type' => 'none'],
            $justified ? null : $fix($assessment->stepUrl('evaluate'), __('declaration.file.fix.trees')), __('declaration.file.notes.in_trees'));
        $einfoDone = true;
        foreach ($assessment->workingStandards() as $standard) {
            $p = $assessment->einfoProgress($standard);
            $einfoDone = $einfoDone && $p['done'] === $p['total'];
        }
        $add('g', __('declaration.file.items.einfo'), __('declaration.file.source.report'), $einfoDone ? 'present' : 'draft', null, ['type' => 'none'],
            $einfoDone ? null : $fix($assessment->stepUrl('einfo'), __('declaration.file.fix.einfo')), __('declaration.file.notes.in_assessment'));
        foreach ($this->workbooks() as $workbook) {
            $add('g', __('declaration.file.items.workbook', ['name' => (string) ($workbook['name'] ?? $workbook['key'])]), $generated, 'present',
                __('declaration.file.names.workbooks').'/'.CetomeWorkbooks::fileName($version, $workbook), ['type' => 'workbook', 'key' => (string) $workbook['key']]);
        }
        if (SbomFeature::checkable($assessment->pack)) {
            $checked = VulnerabilityCheck::query()->where('product_version_id', $version->id)->whereNotNull('finished_at')->exists();
            $add('g', __('declaration.file.items.vulnerabilities'), __('declaration.file.source.vulnerabilities'), $checked ? 'present' : 'missing',
                __('declaration.file.names.vulnerabilities').'.csv', ['type' => 'vulnerabilities'],
                $checked || ! Route::has('versions.vulnerabilities') ? null : $fix(route('versions.vulnerabilities', $version), __('declaration.file.fix.vulnerabilities')));
        }
        // The VEX documents imported (the manufacturer's analysis of each vulnerability), as received.
        foreach (VexImport::query()->where('product_version_id', $version->id)->whereNotNull('imported_at')->orderBy('created_at')->get() as $vex) {
            foreach ((array) $vex->files as $n => $file) {
                $add('g', __('declaration.file.items.vex', ['name' => $file['name']]), __('declaration.file.source.vex'), 'present',
                    __('declaration.file.names.vex').'/'.self::safe($vex->created_at->format('Ymd').' '.$n.' '.$file['name']),
                    ['type' => 'disk', 'path' => $file['path']]);
            }
        }

        // (h) Test reports: the review (reviewers' verdicts and assessment units), the laboratories' reports.
        // A version approved by self-review only (no reviewer on the product) says so.
        $reviewed = in_array($version->status, [VersionStatus::InReview, VersionStatus::Approved], true);
        // Assessment units performed outside REDact: "Performed externally (independent test laboratory)".
        $reviewNote = __($version->units_external ? 'declaration.file.notes.review_external' : 'declaration.file.notes.review')
            .(ReviewRights::selfApproved($version) ? ' · '.__('review.self_reviewed') : '');
        foreach (ReportKind::Review->formats() as $format) {
            $add('h', ReportKind::Review->label().' ('.__('reports.formats.'.$format).')', $generated, $version->status === VersionStatus::Approved ? 'present' : ($reviewed ? 'draft' : 'missing'),
                ExportName::make([$base, ReportKind::Review->fileContent()], $format), ['type' => 'report', 'kind' => ReportKind::Review->value, 'format' => $format],
                $reviewed ? null : $fix($assessment->stepUrl('review'), __('declaration.file.fix.review')), $reviewNote);
        }

        // Uploaded documents, filed by their category.
        $filed = array_fill_keys(self::HEADINGS, 0);
        foreach ($this->documents() as $document) {
            $heading = $this->headingOf($document);
            if ($heading === 'f') {
                continue; // the certificate, listed above
            }
            $filed[$heading]++;
            $add($heading, $document->original_name, $uploaded, 'present', self::safe($document->original_name), ['type' => 'document', 'id' => $document->id]);
        }

        // What only the person can supply: missing until a document is filed there.
        foreach (['a_iii', 'b', 'c', 'i'] as $heading) {
            if ($filed[$heading] === 0) {
                $add($heading, __('declaration.file.items.needed_'.$heading), __('declaration.file.source.yours'), 'missing', null, ['type' => 'none'], $upload,
                    __('declaration.file.notes.not_produced'));
            }
        }
        if ($filed['h'] === 0) {
            $add('h', __('declaration.file.items.needed_h'), __('declaration.file.source.yours'), 'missing', null, ['type' => 'none'], $upload,
                __('declaration.file.notes.not_assessed'));
        }

        usort($items, fn (array $x, array $y) => array_search($x['heading'], self::HEADINGS, true) <=> array_search($y['heading'], self::HEADINGS, true));

        return $this->items = $items;
    }

    /**
     * Per heading: its status (missing > draft > present > n/a) and items.
     *
     * @return list<array{heading: string, status: string, items: list<array<string, mixed>>}>
     */
    public function checklist(): array
    {
        $rows = [];
        foreach (self::HEADINGS as $heading) {
            $items = array_values(array_filter($this->items(), fn (array $i) => $i['heading'] === $heading));
            $statuses = array_column($items, 'status');
            $status = match (true) {
                $items === [] => 'na',
                in_array('missing', $statuses, true) => 'missing',
                in_array('draft', $statuses, true) => 'draft',
                in_array('present', $statuses, true) => 'present',
                default => 'na',
            };
            if ($heading === 'other' && $items === []) {
                continue;
            }
            $rows[] = ['heading' => $heading, 'status' => $status, 'items' => $items];
        }

        return $rows;
    }

    /** @return array{present: int, missing: int, draft: int, na: int} headings per status */
    public function counts(): array
    {
        $counts = ['present' => 0, 'missing' => 0, 'draft' => 0, 'na' => 0];
        foreach ($this->checklist() as $row) {
            $counts[$row['status']]++;
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> the cetome workbooks the pack can fill (CetomeWorkbooks), none when that service is absent */
    private function workbooks(): array
    {
        if (! class_exists(CetomeWorkbooks::class)) {
            return [];
        }
        try {
            return app(CetomeWorkbooks::class)->available();
        } catch (Throwable) {
            return [];
        }
    }

    // ---- Zip ------------------------------------------------------------------------------------

    /** "REDact - Smart lock 1.0 - Technical documentation.zip" */
    public function fileName(): string
    {
        return ExportName::make([trim($this->version()->product->name.' '.$this->version()->label), __('declaration.file.zip_name')], 'zip');
    }

    /**
     * Write the zip to $target.
     *
     * @return array{files: int, skipped: list<string>}
     */
    public function build(string $target, User $by): array
    {
        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write the technical file.');
        }
        $temporary = [];
        $skipped = [];
        $sums = [];
        $files = 0;
        $report = null;
        $workbooks = null;
        $root = self::safe(trim($this->version()->product->name.' '.$this->version()->label).' - '.__('declaration.file.zip_name'));

        try {
            foreach (self::HEADINGS as $heading) {
                $zip->addEmptyDir($root.'/'.self::folder($heading));
            }
            foreach ($this->items() as $item) {
                if ($item['file'] === null) {
                    continue;
                }
                $make = $item['make'];
                $path = null;
                try {
                    $path = match ($make['type']) {
                        'path' => is_string($make['path'] ?? null) && is_file($make['path']) ? $make['path'] : null,
                        'disk' => $make['path'] !== '' && Storage::disk('local')->exists($make['path']) ? Storage::disk('local')->path($make['path']) : null,
                        'document' => $this->documentPath($make['id'] ?? null),
                        default => $this->generate($make, $by, $temporary, $report, $workbooks),
                    };
                } catch (Throwable $e) {
                    report($e);
                    $path = null;
                }
                if ($path === null) {
                    $skipped[] = $item['label'];

                    continue;
                }
                $zip->addFile($path, $root.'/'.$item['file']);
                $sums[$item['file']] = hash_file('sha256', $path);
                $files++;
            }

            $index = $this->temporary($temporary);
            (new TechnicalFileIndex($this))->save($index);
            $indexName = __('declaration.file.names.index').'.docx';
            $zip->addFile($index, $root.'/'.$indexName);
            $sums[$indexName] = hash_file('sha256', $index);
            $readme = $this->readme($skipped);
            $zip->addFromString($root.'/README.txt', $readme);
            $sums['README.txt'] = hash('sha256', $readme);
            // An auditor checks that nothing changed: "sha256sum -c SHA256SUMS.txt" in the folder.
            ksort($sums, SORT_STRING);
            $zip->addFromString($root.'/'.self::SUMS, implode('', array_map(fn (string $file, string $hash) => $hash.'  '.$file."\n", array_keys($sums), $sums)));
            if (! $zip->close()) {
                throw new RuntimeException('Cannot write the technical file.');
            }
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }

        return ['files' => $files, 'skipped' => $skipped];
    }

    private function documentPath(?string $id): ?string
    {
        $document = $id ? $this->documents()->firstWhere('id', $id) : null;

        return $document && Storage::disk('local')->exists($document->path) ? Storage::disk('local')->path($document->path) : null;
    }

    /**
     * @param  array<string, mixed>  $make
     * @param  list<string>  $temporary
     */
    private function generate(array $make, User $by, array &$temporary, ?ReportData &$report, ?CetomeWorkbooks &$workbooks): ?string
    {
        if ($make['type'] === 'none') {
            return null;
        }
        $path = $this->temporary($temporary);
        switch ($make['type']) {
            case 'description':
                $this->descriptionDocument($path);
                break;
            case 'standards':
                $this->standardsDocument($path);
                break;
            case 'declaration':
                ($make['format'] ?? 'docx') === 'pdf' ? (new DeclarationPdf($this->data))->save($path) : (new DeclarationWord($this->data))->save($path);
                break;
            case 'simplified':
                file_put_contents($path, str_replace("\n", "\r\n", $this->data->simplified())."\r\n");
                break;
            case 'report':
                $report ??= ReportData::for($this->version(), $by);
                $kind = ReportKind::from((string) $make['kind']);
                $make['format'] === 'docx' ? (new WordReport($report, $kind))->save($path) : (new ExcelReport($report, $kind))->save($path);
                break;
            case 'workbook':
                $workbooks ??= app(CetomeWorkbooks::class);
                $definition = $workbooks->workbook((string) $make['key']);
                if ($definition === null) {
                    return null;
                }
                $workbooks->fill($this->version(), $definition, $path);
                break;
            case 'vulnerabilities':
                file_put_contents($path, $this->vulnerabilitiesCsv());
                break;
            default:
                return null;
        }

        return $path;
    }

    /** @param  list<string>  $temporary */
    private function temporary(array &$temporary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'redact-tf-');
        if ($path === false) {
            throw new RuntimeException('Cannot write the technical file.');
        }
        $temporary[] = $path;

        return $path;
    }

    /** A name a file system accepts, without folders. */
    public static function safe(string $name): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', ' .');

        return mb_substr($name !== '' ? $name : 'file', 0, 150);
    }

    /** @param  list<string>  $skipped */
    public function readme(array $skipped = []): string
    {
        $version = $this->version();
        $lines = [
            __('declaration.file.readme.title', ['product' => trim($version->product->name.' '.$version->label)]),
            str_repeat('=', 72),
            __('declaration.file.readme.lead'),
            __('declaration.file.readme.generated', ['date' => Carbon::now()->locale(app()->getLocale())->isoFormat('D MMMM YYYY, HH:mm'), 'status' => $version->status->label()]),
            '',
        ];
        if ($this->data->isDraft()) {
            $lines[] = $this->data->watermark().':';
            foreach ($this->data->openItems() as $open) {
                $lines[] = '  - '.$open['label'];
            }
            $lines[] = '';
        }
        foreach ($this->checklist() as $row) {
            $lines[] = '['.mb_strtoupper(__('declaration.file.status.'.$row['status'])).'] '.self::folder($row['heading']);
            $lines[] = '    '.__('declaration.file.points.'.$row['heading']);
            foreach ($row['items'] as $item) {
                $lines[] = '    - ['.__('declaration.file.status.'.$item['status']).'] '.$item['label'].' · '.$item['source']
                    .($item['file'] ? ' · '.basename((string) $item['file']) : '')
                    .($item['note'] !== '' ? ' · '.$item['note'] : '');
            }
            $lines[] = '';
        }
        if ($skipped !== []) {
            $lines[] = __('declaration.file.readme.skipped');
            foreach ($skipped as $label) {
                $lines[] = '  - '.$label;
            }
            $lines[] = '';
        }
        $lines[] = __('declaration.file.readme.checksums', ['file' => self::SUMS]);
        $lines[] = '';
        $lines[] = __('declaration.file.readme.legal');

        return implode("\r\n", $lines)."\r\n";
    }

    // ---- Generated documents ----------------------------------------------------------------------

    private function descriptionDocument(string $path): void
    {
        $data = $this->data;
        $version = $this->version();
        $product = $version->product;
        $doc = new WordDoc(__('declaration.file.names.description'), __('declaration.word.footer', ['product' => $product->name, 'version' => $version->label]));
        $doc->title(__('declaration.file.names.description'));
        $doc->muted(__('declaration.file.annex_ref', ['point' => '(a)']));
        $doc->heading(__('declaration.file.doc.product'));
        $doc->facts(array_filter([
            __('declaration.file.doc.name') => (string) $product->name,
            __('declaration.file.doc.version') => (string) $version->label,
            __('declaration.file.doc.reference') => (string) $product->reference,
            __('declaration.file.doc.manufacturer') => $data->manufacturerName(),
            __('declaration.file.doc.description') => (string) $product->description,
            __('declaration.file.doc.intended_use') => (string) $version->intended_use,
        ], fn ($v) => $v !== ''));

        $doc->heading(__('declaration.file.doc.identifiers'));
        $table = $doc->table();
        $doc->headerRow($table, [__('identification.kind') => 30, __('identification.value') => 40, __('identification.notes') => 30]);
        foreach ($data->identifiers() as $identifier) {
            $doc->row($table, [[$identifier['label'], 30], [$identifier['value'], 40], [$identifier['notes'], 30]]);
        }
        if ($data->identifiers() === []) {
            $doc->row($table, [[__('declaration.file.doc.none'), 100]]);
        }
        $doc->spacer();

        $image = $data->imagePath();
        if ($image !== null) {
            $doc->heading(__('declaration.file.doc.picture'));
            [$w, $h] = WordDoc::imageSize($image, 260);
            $doc->section->addImage($image, ['width' => $w, 'height' => $h, 'spaceAfter' => 120]);
        }

        $doc->heading(__('declaration.file.doc.software'));
        $doc->muted(__('declaration.file.points.a_ii'));
        $table = $doc->table();
        $doc->headerRow($table, [__('declaration.file.doc.component') => 50, __('declaration.file.doc.component_version') => 25, __('declaration.file.doc.component_type') => 25]);
        $rows = $data->softwareComponents();
        foreach ($data->identifiers() as $identifier) {
            if (in_array($identifier['kind'], ['software_version', 'fvin'], true)) {
                $rows[] = ['name' => $identifier['label'], 'version' => $identifier['value'], 'type' => ''];
            }
        }
        foreach ($rows as $row) {
            $doc->row($table, [[$row['name'], 50], [$row['version'], 25], [$row['type'], 25]]);
        }
        if ($rows === []) {
            $doc->row($table, [[__('declaration.file.doc.none'), 100]]);
        }

        $doc->save($path);
    }

    private function standardsDocument(string $path): void
    {
        $data = $this->data;
        $version = $this->version();
        $doc = new WordDoc(__('declaration.file.names.standards'), __('declaration.word.footer', ['product' => $version->product->name, 'version' => $version->label]));
        $doc->title(__('declaration.file.names.standards'));
        $doc->muted(__('declaration.file.annex_ref', ['point' => '(d)']));
        $doc->text(__('declaration.file.points.d'), ['size' => 9]);

        $doc->heading(__('declaration.file.doc.assessed'));
        $table = $doc->table();
        $doc->headerRow($table, [__('declaration.word.reference') => 35, __('declaration.word.requirement') => 30, __('declaration.file.doc.applied') => 35]);
        foreach ($data->assessedStandards() as $standard) {
            $doc->row($table, [[$standard['reference'], 35], [$standard['article'], 30], [__('declaration.file.doc.applied_redact'), 35]]);
        }
        if ($data->assessedStandards() === []) {
            $doc->row($table, [[__('declaration.file.doc.none'), 100]]);
        }
        $doc->spacer();
        if ($data->citedWithRestrictions()) {
            $doc->muted(__('declaration.standards.restrictions'));
        }

        $doc->heading(__('declaration.file.doc.entered'));
        $doc->muted(__('declaration.file.notes.not_assessed'));
        $table = $doc->table();
        $doc->headerRow($table, [__('declaration.word.reference') => 60, __('declaration.word.requirement') => 40]);
        foreach ($data->otherStandards() as $standard) {
            $doc->row($table, [[$standard['reference'], 60], [$standard['article'] === 'other' ? __('declaration.standards.groups.other') : DeclarationData::articleLabel($standard['article']), 40]]);
        }
        if ($data->otherStandards() === []) {
            $doc->row($table, [[__('declaration.file.doc.none'), 100]]);
        }

        $doc->save($path);
    }

    /** The known vulnerabilities of the components, one row per finding (CSV, formulas neutralised). */
    public function vulnerabilitiesCsv(): string
    {
        $findings = VulnerabilityFinding::query()->where('product_version_id', $this->version()->id)
            ->with('component:id,code,name')->orderBy('key')->get();
        $rows = [[
            __('declaration.file.csv.component'), __('declaration.file.csv.id'), __('declaration.file.csv.aliases'), __('declaration.file.csv.title'),
            __('declaration.file.csv.cvss'), __('declaration.file.csv.severity'), __('declaration.file.csv.exploited'), __('declaration.file.csv.status'),
            __('declaration.file.csv.reason'), __('declaration.file.csv.fixed'), __('declaration.file.csv.sources'),
            __('declaration.file.csv.vex_status'), __('declaration.file.csv.vex_detail'),
        ]];
        // The manufacturer's VEX analysis on its finding; the statements no look-up found get a row of their own.
        $statements = VexStatus::of($this->version()->id);
        $shown = [];
        foreach ($findings as $finding) {
            $vex = VexStatus::forFinding($finding);
            if ($vex) {
                $shown[$vex->id] = true;
            }
            $rows[] = [
                trim(($finding->component->code ?? '').' '.($finding->component->name ?? '')),
                $finding->key,
                implode(' ', $finding->aliases ?? []),
                (string) $finding->title,
                $finding->score() === null ? '' : number_format($finding->score(), 1, '.', ''),
                (string) $finding->severity,
                $finding->exploited ? __('common.yes') : __('common.no'),
                $finding->status,
                (string) $finding->reason,
                implode(' ', $finding->fixed ?? []),
                implode(' ', $finding->sources ?? []),
                $vex ? __('sbom.vex.status.'.$vex->status) : '',
                $vex ? $vex->explanation() : '',
            ];
        }
        foreach ($statements->reject(fn ($s) => isset($shown[$s->id])) as $s) {
            $rows[] = [
                trim(($s->component->code ?? '').' '.($s->component->name ?? '')), $s->vulnerability_key, implode(' ', $s->aliases ?? []),
                '', '', '', '', '', '', '', __('sbom.vex.source', ['file' => $s->source_file]),
                __('sbom.vex.status.'.$s->status), $s->explanation(),
            ];
        }
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new RuntimeException('Cannot write the vulnerabilities.');
        }
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'".$v : (string) $v, $row), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
