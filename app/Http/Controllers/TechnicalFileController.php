<?php

namespace App\Http\Controllers;

use App\Declaration\DeclarationAccess;
use App\Declaration\TechnicalFile;
use App\Http\Controllers\Concerns\SendsExports;
use App\Models\Document;
use App\Models\ProductVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * "Download the technical file": the technical documentation of RED Annex V as one zip, built on
 * demand (App\Declaration\TechnicalFile), streamed once and deleted; and the category that files
 * an uploaded document under an Annex V heading.
 */
class TechnicalFileController extends Controller
{
    use SendsExports;

    public function download(Request $request, ProductVersion $version): BinaryFileResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        $this->access($version->product);

        $file = TechnicalFile::for($version);
        $path = $this->temporaryFile('redact-tf-');
        try {
            $result = $file->build($path, $request->user());
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }

        activity('report')->causedBy($request->user())->performedOn($version)
            ->withProperties(['kind' => 'technical-file', 'format' => 'zip', 'version' => $version->id,
                'files' => $result['files'], 'skipped' => count($result['skipped']), 'draft' => $file->data->isDraft(), 'counts' => $file->counts()])
            ->log('exported');

        return $this->sendExport($path, $file->fileName(), 'application/zip');
    }

    public function category(Request $request, ProductVersion $version, Document $document): RedirectResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        abort_unless($document->product_version_id === $version->getKey() && $document->id !== $version->certificate_document_id, 404);
        abort_unless(DeclarationAccess::canFile($this->access($version->product), $version), 404);

        $data = $request->validate(['category' => ['nullable', Rule::in(array_keys(TechnicalFile::CATEGORIES))]]);
        $document->forceFill(['category' => $data['category'] ?? null])->save();

        return redirect()->to(route('versions.declaration-file', $version).'#documents')
            ->with('status', __('declaration.file.filed', ['name' => $document->original_name]));
    }
}
