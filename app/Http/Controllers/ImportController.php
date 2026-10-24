<?php

namespace App\Http\Controllers;

use App\Content\ContentPack;
use App\Content\Text;
use App\Declaration\TechnicalFile;
use App\Documents\DocumentStore;
use App\Models\ProductVersion;
use App\Workbooks\ImportApplier;
use App\Workbooks\Mapping;
use App\Workbooks\WorkbookException;
use App\Workbooks\WorkbookImport;
use App\Workbooks\XlsxPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The one upload of a version's documents (Documents column of the product card,
 * documents/_panel): any files. cetome REDact workbooks go to the exact importer
 * (WorkbookImport, no AI): a preview of what would be created or changed, then the ticked
 * parts are applied. Any other document (PDF, Word, Excel, CSV, text, Markdown) is stored as
 * a version document (DocumentStore, with its technical-file category), read by the AI
 * "Draft from documentation".
 *
 * The plan read from the workbooks is kept in the cache for two hours, bound to the person and
 * the version; nothing is written to the version before "Apply".
 */
class ImportController extends Controller
{
    public const MAX_FILES = 10;

    public const MAX_KB = 20 * 1024;

    private const TTL_MINUTES = 120;

    public function store(Request $request, ProductVersion $version, ContentPack $pack, DocumentStore $documents): RedirectResponse
    {
        $this->allow('edit', $version);
        $this->access($version->product);
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => ['required', 'file', 'max:'.max(self::MAX_KB, DocumentStore::maxKilobytes())],
            // Where the other documents go in the technical file (RED Annex V), optional.
            'category' => ['nullable', Rule::in(array_keys(TechnicalFile::CATEGORIES))],
        ]);

        $import = new WorkbookImport($pack, Mapping::fromPack($pack));
        $workbooks = 0;
        $stored = [];
        $errors = [];
        foreach ($request->file('files') as $file) {
            $name = DocumentStore::cleanName($file->getClientOriginalName(), strtolower($file->getClientOriginalExtension()) ?: 'bin');
            $path = (string) $file->getRealPath();
            $extension = strtolower($file->getClientOriginalExtension());
            if ($extension === 'xlsx' || $extension === 'xlsm') {
                try {
                    $package = XlsxPackage::open($path);
                    $isCetome = $import->detect($package) !== null;
                    $package->close();
                    if ($isCetome) {
                        if ($file->getSize() > self::MAX_KB * 1024) {
                            throw WorkbookException::tooLarge();
                        }
                        $import->read($path, $name);
                        $workbooks++;

                        continue;
                    }
                } catch (WorkbookException $e) {
                    $errors[] = $name.': '.$e->getMessage();

                    continue;
                }
            }
            // Not a cetome workbook: a document for the AI (accounts only, the AI being theirs).
            if ($request->user()->is_guest) {
                $errors[] = $name.': '.__('import.errors.guest_documents');

                continue;
            }
            try {
                $stored[] = $documents->store($version, $file, $request->user(), $request->input('category') ?: null)->original_name;
            } catch (ValidationException $e) {
                $errors[] = $name.': '.collect($e->errors())->flatten()->first();
            }
        }

        $status = [];
        if ($stored !== []) {
            $status[] = trans_choice('import.documents_added', count($stored), ['count' => count($stored)]);
        }
        if ($workbooks === 0) {
            $redirect = back()->with('status', implode(' ', $status ?: [__('import.nothing')]));

            return $errors === [] ? $redirect : $redirect->withErrors(['files' => $errors]);
        }

        $token = Str::random(40);
        Cache::put($this->cacheKey($token), [
            'user' => $request->user()->id,
            'version' => $version->id,
            'plan' => $import->plan(),
            'documents' => $stored,
            'errors' => $errors,
        ], now()->addMinutes(self::TTL_MINUTES));

        return redirect()->route('versions.import.show', [$version, $token])->with('status', implode(' ', $status) ?: null);
    }

    public function show(Request $request, ProductVersion $version, string $token, ContentPack $pack): View
    {
        $this->allow('edit', $version);
        $access = $this->access($version->product);
        $stored = $this->stored($request, $version, $token);
        $plan = $stored['plan'];
        $mapping = Mapping::fromPack($pack);

        $files = [];
        foreach ($plan['files'] as $file) {
            $files[] = ['name' => $file['name'], 'label' => Text::of($mapping->workbook($file['workbook'])['label'] ?? $file['workbook'])];
        }

        return view('import.show', [
            'version' => $version,
            'access' => $access,
            'token' => $token,
            'files' => $files,
            'preview' => (new ImportApplier($version, $pack))->preview($plan),
            'warnings' => $plan['warnings'],
            'fileErrors' => $stored['errors'],
            'documents' => $stored['documents'],
        ]);
    }

    public function apply(Request $request, ProductVersion $version, string $token, ContentPack $pack): RedirectResponse
    {
        $this->allow('edit', $version);
        $this->access($version->product);
        $data = $request->validate([
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => ['string', 'in:'.implode(',', ImportApplier::PARTS)],
        ]);
        $stored = $this->stored($request, $version, $token);

        $counts = (new ImportApplier($version, $pack))->apply($stored['plan'], array_values($data['parts']), $request->user());
        Cache::forget($this->cacheKey($token));

        $total = array_sum(array_diff_key($counts, ['skipped' => true]));
        $message = trans_choice('import.applied', $total, ['count' => $total]);
        if ($counts['skipped'] > 0) {
            $message .= ' '.trans_choice('import.skipped', $counts['skipped'], ['count' => $counts['skipped']]);
        }

        return redirect()->route('versions.edit', $version)->with('status', $message);
    }

    public function destroy(Request $request, ProductVersion $version, string $token): RedirectResponse
    {
        $this->allow('edit', $version);
        $this->stored($request, $version, $token);
        Cache::forget($this->cacheKey($token));

        return redirect()->route('versions.edit', $version)->with('status', __('import.cancelled'));
    }

    /** @return array{user: int|string, version: string, plan: array<string, mixed>, documents: list<string>, errors: list<string>} */
    private function stored(Request $request, ProductVersion $version, string $token): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);
        $stored = Cache::get($this->cacheKey($token));
        abort_unless(is_array($stored) && ($stored['user'] ?? null) === $request->user()->id && ($stored['version'] ?? null) === $version->id, 404);

        return $stored;
    }

    private function cacheKey(string $token): string
    {
        return 'workbook-import:'.$token;
    }
}
