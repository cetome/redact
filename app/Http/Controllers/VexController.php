<?php

namespace App\Http\Controllers;

use App\Content\ContentPack;
use App\Models\ProductVersion;
use App\Models\VexImport;
use App\Sbom\SbomFeature;
use App\Sbom\Vex\VexImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * VEX statements uploaded on the import page (SbomController::store): preview against the
 * components and Known vulnerabilities (new / changed / same), then record the ticked ones.
 */
class VexController extends Controller
{
    public function show(ProductVersion $version, VexImport $vex, ContentPack $pack, VexImporter $importer): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canView() && $vex->product_version_id === $version->id && $vex->status !== 'discarded' && SbomFeature::checkable($pack), 404);
        $rows = $vex->status === 'preview' ? $importer->plan($version, $vex->statements) : [];

        return view('sbom.vex', [
            'version' => $version,
            'access' => $access,
            'import' => $vex,
            'rows' => $rows,
            'counts' => collect($rows)->countBy('status')->all(),
            'vulnerabilityList' => $pack->lists()[SbomFeature::vulnerabilitiesList()],
            'editable' => $access->canEdit($version) && $vex->status === 'preview',
        ]);
    }

    public function apply(Request $request, ProductVersion $version, VexImport $vex, VexImporter $importer, ContentPack $pack): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($vex->product_version_id === $version->id && $vex->status === 'preview' && SbomFeature::checkable($pack), 404);
        $data = $request->validate([
            'rows' => ['nullable', 'array', 'max:'.((int) config('sbom.import.max_statements', 5000) * 4)],
            'rows.*' => ['string', 'max:40', 'regex:/^\d+-[0-9a-z]+$/'],
        ]);
        $selected = array_values(array_unique(array_map('strval', $data['rows'] ?? [])));
        if ($selected === []) {
            throw ValidationException::withMessages(['rows' => __('sbom.vex.none_ticked')]);
        }

        $summary = $importer->apply($vex, $selected, $request->user());
        $vex->forceFill(['status' => 'imported', 'imported_at' => now(), 'summary' => $summary])->save();
        activity('sbom')->performedOn($version)->causedBy($request->user())
            ->withProperties(['vex' => $vex->id, 'format' => $vex->format] + $summary)
            ->log('vex imported');

        return redirect()->route('versions.inventory', [$version, SbomFeature::vulnerabilitiesList()])
            ->with('status', __('sbom.vex.done', ['recorded' => $summary['recorded'], 'created' => $summary['created']]));
    }

    public function destroy(ProductVersion $version, VexImport $vex): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($vex->product_version_id === $version->id && $vex->status === 'preview', 404);

        // The files of an SBOM with its VEX stay with that SBOM's upload.
        if ($vex->sbom_import_id === null) {
            Storage::disk(SbomController::DISK)->deleteDirectory('sbom/'.$version->id.'/'.$vex->id);
        }
        $vex->forceFill(['status' => 'discarded', 'statements' => [], 'files' => []])->save();

        return redirect()->route('versions.sbom', $version)->with('status', __('sbom.import.discarded'));
    }

    public function download(ProductVersion $version, VexImport $vex, int $file): Response
    {
        $this->allow('view', $version);
        abort_unless($vex->product_version_id === $version->id, 404);
        $entry = $vex->files[$file] ?? null;
        abort_unless(is_array($entry) && Storage::disk(SbomController::DISK)->exists($entry['path']), 404);

        return Storage::disk(SbomController::DISK)->download($entry['path'], $entry['name'], [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
