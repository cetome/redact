<?php

namespace App\Http\Controllers;

use App\Ai\Availability;
use App\Ai\Features\DraftFromDocuments;
use App\Declaration\TechnicalFile;
use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Product documents of a version (datasheets, manuals, architecture), read by
 * "Draft from documentation". Private disk only; served through this
 * controller as an attachment with nosniff.
 */
class DocumentController extends Controller
{
    public function index(Request $request, ProductVersion $version): View
    {
        $this->allow('view', $version);
        $this->access($version->product);

        return view('documents.index', ['version' => $version]);
    }

    public function store(Request $request, ProductVersion $version, DocumentStore $store): RedirectResponse
    {
        // Documents only feed the AI, which a sandbox guest has not got.
        abort_if($request->user()->is_guest, 404);
        $this->allow('edit', $version);
        $request->validate([
            'document' => ['required', 'file', 'max:'.DocumentStore::maxKilobytes(), 'extensions:'.implode(',', array_keys(DocumentStore::KINDS))],
            'category' => ['nullable', Rule::in(array_keys(TechnicalFile::CATEGORIES))],
        ]);

        $document = $store->store($version, $request->file('document'), $request->user(), $request->input('category') ?: null);

        return back()->with('status', $document->extracted_text
            ? __('ai.documents.uploaded', ['name' => $document->original_name])
            : __('ai.documents.uploaded_no_text', ['name' => $document->original_name]));
    }

    public function download(ProductVersion $version, Document $document): StreamedResponse
    {
        $this->allow('view', $version);
        abort_unless($document->product_version_id === $version->getKey(), 404);
        $this->access($version->product);
        abort_unless(Storage::disk(DocumentStore::DISK)->exists($document->path), 404);

        $response = Storage::disk(DocumentStore::DISK)->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);

        return $response;
    }

    public function destroy(Request $request, ProductVersion $version, Document $document, DocumentStore $store): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($document->product_version_id === $version->getKey(), 404);

        $name = $document->original_name;
        $store->delete($document);

        return back()->with('status', __('ai.documents.deleted', ['name' => $name]));
    }

    /**
     * Data for the documents panel (documents/_panel.blade.php).
     *
     * @return array<string, mixed>
     */
    public static function panelData(ProductVersion $version): array
    {
        $user = request()->user();
        $documents = $version->documents()->orderBy('created_at')
            ->get(['id', 'product_version_id', 'uploaded_by', 'original_name', 'mime', 'size', 'created_at']);

        return [
            'documents' => $documents,
            'uploaders' => User::query()->whereIn('id', $documents->pluck('uploaded_by')->filter()->unique())->pluck('name', 'id'),
            'canEdit' => $user && ! $user->is_guest && Gate::forUser($user)->allows('edit', $version),
            'isGuest' => (bool) $user?->is_guest,
            'maxDocuments' => DocumentStore::maxCount(),
            'maxMb' => (int) (DocumentStore::maxKilobytes() / 1024),
            'aiReason' => $user ? Availability::reason($user, $version) : null,
            'draftUnits' => DraftFromDocuments::units($version),
            'draftRunning' => DraftFromDocuments::running($version),
        ];
    }
}
