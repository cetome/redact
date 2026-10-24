<?php

namespace App\Http\Controllers;

use App\Content\ContentPack;
use App\Models\ProductVersion;
use App\Models\SbomImport;
use App\Models\VexImport;
use App\Sbom\Component;
use App\Sbom\ComponentImporter;
use App\Sbom\Formats\Spreadsheet;
use App\Sbom\SbomException;
use App\Sbom\SbomFeature;
use App\Sbom\SbomReader;
use App\Sbom\Vex\VexReader;
use App\Vulnerabilities\VulnerabilityChecks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Import a bill of materials": upload (CycloneDX, SPDX, SWID, Excel, CSV), preview against
 * the component list, then create or update the ticked components. The original files stay
 * on the private disk, served only through download() as attachments.
 *
 * The same upload takes VEX files (CycloneDX VEX, OpenVEX, CSAF VEX): VEX alone goes to its
 * own preview (VexController); the VEX an SBOM embeds is reviewed once its components are in.
 */
class SbomController extends Controller
{
    public const DISK = 'local';

    /** Extensions accepted at upload (the content decides the format, see SbomReader). */
    public const EXTENSIONS = ['json', 'cdx', 'xml', 'spdx', 'jsonld', 'swidtag', 'swid', 'zip', 'xlsx', 'csv', 'tsv', 'txt', 'bom'];

    public function create(Request $request, ProductVersion $version, ContentPack $pack): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canEdit($version) && SbomFeature::importable($pack), 404);

        return view('sbom.import', [
            'version' => $version,
            'access' => $access,
            'list' => $pack->lists()[SbomFeature::componentsList()],
            'imports' => SbomImport::query()->where('product_version_id', $version->id)->where('status', '!=', 'discarded')
                ->with('user:id,name')->latest()->limit(10)->get(),
            'vexImports' => VexImport::query()->where('product_version_id', $version->id)->where('status', '!=', 'discarded')
                ->with('user:id,name')->latest()->limit(10)->get(),
            'maxMb' => (int) round(config('sbom.import.max_kb') / 1024),
            'maxFiles' => (int) config('sbom.import.max_files'),
            'maxComponents' => (int) config('sbom.import.max_components'),
            'accept' => '.'.implode(',.', self::EXTENSIONS),
        ]);
    }

    public function store(Request $request, ProductVersion $version, ContentPack $pack, SbomReader $reader): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless(SbomFeature::importable($pack), 404);
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.(int) config('sbom.import.max_files')],
            'files.*' => ['required', 'file', 'max:'.(int) config('sbom.import.max_kb'), 'extensions:'.implode(',', self::EXTENSIONS)],
        ], [], ['files' => __('sbom.import.file'), 'files.*' => __('sbom.import.file')]);

        /** @var list<UploadedFile> $uploads */
        $uploads = array_values((array) $request->file('files'));
        $read = array_map(fn (UploadedFile $f) => ['name' => self::cleanName($f->getClientOriginalName()), 'path' => (string) $f->getRealPath()], $uploads);
        try {
            $vex = (new VexReader)->read($read);
            // Files that are only VEX are not bills of materials.
            $boms = array_values(array_diff_key($read, array_flip($vex['vex_only'])));
            $result = $boms === [] ? null : $reader->read($boms);
        } catch (SbomException $e) {
            throw ValidationException::withMessages(['files' => $e->getMessage()]);
        }

        $id = strtolower((string) Str::ulid());
        $files = [];
        foreach ($uploads as $n => $upload) {
            $path = Storage::disk(self::DISK)->putFileAs('sbom/'.$version->id.'/'.$id, $upload, (string) $n);
            if ($path === false) {
                throw ValidationException::withMessages(['files' => __('sbom.errors.not_saved')]);
            }
            $files[] = [
                'name' => self::cleanName($upload->getClientOriginalName()),
                'path' => $path,
                'size' => (int) $upload->getSize(),
                'sha256' => (string) hash_file('sha256', (string) $upload->getRealPath()),
                'mime' => (string) ($upload->getMimeType() ?: 'application/octet-stream'),
            ];
        }

        $vexImport = null;
        if ($vex['statements'] !== [] && SbomFeature::checkable($pack)) {
            $vexImport = new VexImport([
                'product_version_id' => $version->id,
                'user_id' => $request->user()->id,
                'format' => (string) $vex['format'],
                'files' => $files,
                'statements' => $vex['statements'],
                'statement_count' => count($vex['statements']),
                'status' => 'preview',
            ]);
        }
        if ($result === null && $vexImport !== null) {
            $vexImport->id = $id;
            $vexImport->save();
            activity('sbom')->performedOn($version)->causedBy($request->user())
                ->withProperties(['vex' => $vexImport->id, 'format' => $vexImport->format, 'statements' => $vexImport->statement_count,
                    'files' => array_map(fn ($f) => ['name' => $f['name'], 'size' => $f['size'], 'sha256' => $f['sha256']], $files)])
                ->log('vex uploaded');

            return redirect()->route('versions.vex.show', [$version, $vexImport]);
        }
        if ($result === null) {
            throw ValidationException::withMessages(['files' => __('sbom.errors.empty')]);
        }

        $import = new SbomImport([
            'product_version_id' => $version->id,
            'user_id' => $request->user()->id,
            'format' => $result['format'],
            'kind' => $result['kind'],
            'files' => $files,
            'components' => array_map(fn (Component $c) => $c->toArray(), $result['components']),
            'component_count' => count($result['components']),
            'warnings' => $result['warnings'],
            'status' => 'preview',
        ]);
        $import->id = $id;
        $import->save();
        if ($vexImport !== null) {
            // Reviewed after the components: the statements are matched against them then.
            $vexImport->sbom_import_id = $import->id;
            $vexImport->save();
        }

        activity('sbom')->performedOn($version)->causedBy($request->user())
            ->withProperties(['import' => $import->id, 'format' => $import->format, 'kind' => $import->kind, 'components' => $import->component_count,
                'files' => array_map(fn ($f) => ['name' => $f['name'], 'size' => $f['size'], 'sha256' => $f['sha256']], $files)])
            ->log('sbom uploaded');

        return redirect()->route('versions.sbom.show', [$version, $import]);
    }

    public function show(Request $request, ProductVersion $version, SbomImport $import, ContentPack $pack, ComponentImporter $importer): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canView() && $import->product_version_id === $version->id && $import->status !== 'discarded', 404);
        abort_unless(SbomFeature::importable($pack), 404);

        $components = array_map(fn ($row) => Component::fromArray($row), $import->components);
        $rows = $import->status === 'preview' ? $importer->plan($version, $components) : [];
        $user = $request->user();

        return view('sbom.preview', [
            'version' => $version,
            'access' => $access,
            'import' => $import,
            'list' => $pack->lists()[SbomFeature::componentsList()],
            'rows' => $rows,
            'counts' => collect($rows)->countBy('status')->all(),
            'editable' => $access->canEdit($version) && $import->status === 'preview',
            'checkRefusal' => SbomFeature::checkable($pack) ? SbomFeature::checkRefusal($user, $version) : __('sbom.check.unavailable'),
            'vexImport' => self::embeddedVex($import),
        ]);
    }

    /** The VEX statements the uploaded SBOM embeds, still to review. */
    private static function embeddedVex(SbomImport $import): ?VexImport
    {
        return SbomFeature::checkable() ? VexImport::query()->where('sbom_import_id', $import->id)->where('status', 'preview')->first() : null;
    }

    public function apply(Request $request, ProductVersion $version, SbomImport $import, ComponentImporter $importer, ContentPack $pack): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($import->product_version_id === $version->id && $import->status === 'preview' && SbomFeature::importable($pack), 404);
        $data = $request->validate([
            'rows' => ['nullable', 'array'],
            'rows.*' => ['integer', 'min:0', 'max:'.max(0, $import->component_count - 1)],
            'types' => ['nullable', 'array'],
            'types.*' => ['in:software,hardware'],
            'check' => ['nullable', 'boolean'],
        ]);
        $selected = array_values(array_unique(array_map('intval', $data['rows'] ?? [])));
        if ($selected === []) {
            throw ValidationException::withMessages(['rows' => __('sbom.preview.none_ticked')]);
        }
        $types = array_map('strval', (array) ($data['types'] ?? []));

        $summary = $importer->apply($import, $selected, $types, $request->user());
        $import->forceFill([
            'status' => 'imported',
            'imported_at' => now(),
            'summary' => array_diff_key($summary, ['ids' => true]),
        ])->save();

        activity('sbom')->performedOn($version)->causedBy($request->user())
            ->withProperties(['import' => $import->id, 'format' => $import->format] + array_diff_key($summary, ['ids' => true]))
            ->log('sbom imported');

        $message = __('sbom.preview.done', ['created' => $summary['created'], 'updated' => $summary['updated']]);
        $user = $request->user();
        $checked = false;
        if ($request->boolean('check') && $summary['ids'] !== [] && SbomFeature::checkable($pack) && SbomFeature::checkRefusal($user, $version) === null) {
            VulnerabilityChecks::start($version, $user, 'import', $summary['ids']);
            $checked = true;
        }
        // The VEX of the same file, now that its components are in the list.
        $vexImport = self::embeddedVex($import);
        if ($vexImport !== null) {
            return redirect()->route('versions.vex.show', [$version, $vexImport])
                ->with('status', $message.($checked ? ' '.__('sbom.check.started') : '').' '.__('sbom.vex.review_now'));
        }
        if ($checked) {
            return redirect()->route('versions.vulnerabilities', $version)->with('status', $message.' '.__('sbom.check.started'));
        }

        return redirect()->route('versions.inventory', [$version, SbomFeature::componentsList()])
            ->with('status', $message)->with('inventory.new', $summary['ids']);
    }

    public function destroy(Request $request, ProductVersion $version, SbomImport $import): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($import->product_version_id === $version->id && $import->status === 'preview', 404);

        Storage::disk(self::DISK)->deleteDirectory('sbom/'.$version->id.'/'.$import->id);
        $import->forceFill(['status' => 'discarded', 'components' => [], 'files' => []])->save();
        VexImport::query()->where('sbom_import_id', $import->id)->where('status', 'preview')->get()
            ->each(fn (VexImport $v) => $v->forceFill(['status' => 'discarded', 'statements' => [], 'files' => []])->save());

        return redirect()->route('versions.sbom', $version)->with('status', __('sbom.import.discarded'));
    }

    public function download(ProductVersion $version, SbomImport $import, int $file): Response
    {
        $this->allow('view', $version);
        abort_unless($import->product_version_id === $version->id, 404);
        $this->access($version->product);
        $entry = $import->files[$file] ?? null;
        abort_unless(is_array($entry) && Storage::disk(self::DISK)->exists($entry['path']), 404);

        return Storage::disk(self::DISK)->download($entry['path'], $entry['name'], [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function template(): Response
    {
        return response(Spreadsheet::template(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="redact-components-template.xlsx"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\x00-\x1F\x7F"<>:|?*\/\\\\]/u', '', $name) ?? '', " .\t");

        return mb_substr($name === '' ? 'bom' : $name, -200);
    }
}
