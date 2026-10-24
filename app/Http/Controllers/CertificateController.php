<?php

namespace App\Http\Controllers;

use App\Declaration\DeclarationAccess;
use App\Declaration\TechnicalFile;
use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\ProductVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The Notified Body's certificate (EU-type examination certificate, Module B, or quality
 * system approval, Module H), recorded once the body has issued it: after the review, on the
 * approved version, by its owner or an administrator of the organisation. The PDF is a document
 * of the version (private disk, served by DocumentController as an attachment).
 */
class CertificateController extends Controller
{
    public function update(Request $request, ProductVersion $version, DocumentStore $store): RedirectResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        abort_unless(DeclarationAccess::canRecordCertificate($this->access($version->product), $version), 404);

        $data = $request->validate([
            'certificate_reference' => ['required', 'string', 'max:120'],
            'certificate_issued_on' => ['required', 'date_format:Y-m-d'],
            'certificate_expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:certificate_issued_on'],
            'certificate_body' => ['nullable', 'string', 'max:160'],
            'certificate_body_number' => ['nullable', 'regex:/^\d{4}$/'],
            'certificate_file' => ['nullable', 'file', 'max:'.DocumentStore::maxKilobytes(), 'extensions:pdf'],
            'remove_file' => ['nullable', 'boolean'],
        ], ['certificate_body_number.regex' => __('declaration.nando.four_digits')]);

        $old = $version->certificate_document_id ? Document::query()->find($version->certificate_document_id) : null;
        $document = null;
        if ($request->hasFile('certificate_file')) {
            $document = $store->store($version, $request->file('certificate_file'), $request->user());
            $document->forceFill(['category' => TechnicalFile::CERTIFICATE])->save();
        }

        DB::transaction(function () use ($version, $data, $document, $old, $store, $request) {
            $documentId = $version->certificate_document_id;
            if ($document || $request->boolean('remove_file')) {
                if ($old) {
                    $store->delete($old);
                }
                $documentId = $document?->id;
            }
            $version->forceFill([
                'certificate_reference' => trim($data['certificate_reference']),
                'certificate_issued_on' => $data['certificate_issued_on'],
                'certificate_expires_on' => $data['certificate_expires_on'] ?? null,
                'certificate_body' => filled($data['certificate_body'] ?? null) ? trim($data['certificate_body']) : $version->notified_body,
                'certificate_body_number' => $data['certificate_body_number'] ?? $version->notified_body_number,
                'certificate_document_id' => $documentId,
            ])->save();
        });

        activity('version')->causedBy($request->user())->performedOn($version)
            ->withProperties([
                'certificate' => $version->certificate_reference,
                'issued_on' => $version->certificate_issued_on?->toDateString(),
                'expires_on' => $version->certificate_expires_on?->toDateString(),
                'body_number' => $version->certificate_body_number,
                'file' => $document?->sha256,
            ])
            ->log('certificate recorded');

        return redirect()->to(route('versions.declaration', $version).'#certificate')->with('status', __('declaration.certificate.saved'));
    }
}
