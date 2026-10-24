<?php

namespace App\Http\Controllers;

use App\Declaration\AnnexText;
use App\Declaration\DeclarationAccess;
use App\Declaration\DeclarationData;
use App\Declaration\DeclarationPdf;
use App\Declaration\DeclarationWord;
use App\Declaration\TechnicalFile;
use App\Http\Controllers\Concerns\SendsExports;
use App\Models\ProductVersion;
use App\Reports\ExportName;
use App\Support\NotifiedBodies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

/**
 * The last step, "Declaration & technical file": the EU declaration of conformity (RED Annex VI)
 * and its simplified form (Annex VII), the Notified Body's certificate once issued, and the
 * technical documentation (Annex V) as a checklist and a zip (TechnicalFileController).
 */
class DeclarationController extends Controller
{
    use SendsExports;

    public function show(Request $request, ProductVersion $version): View
    {
        abort_unless(Gate::allows('view', $version), 404);
        $access = $this->access($version->product);
        $data = DeclarationData::for($version);
        $view = $request->route('view') === 'file' ? 'file' : 'declaration';

        return view('declaration.show', [
            'version' => $version,
            'access' => $access,
            'data' => $data,
            'file' => new TechnicalFile($data),
            'view' => $view,
            'canEdit' => DeclarationAccess::canEdit($access, $version),
            'canCertificate' => DeclarationAccess::canRecordCertificate($access, $version),
            'canFile' => DeclarationAccess::canFile($access, $version),
            'orgAdmin' => $access->orgAdmin && ! $access->isReadOnlyProduct(),
            'isGuest' => (bool) $request->user()?->is_guest,
            'nbUnknown' => NotifiedBodies::unknown($version->certificate_body_number ?: $version->notified_body_number),
        ]);
    }

    public function update(Request $request, ProductVersion $version): RedirectResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        abort_unless(DeclarationAccess::canEdit($this->access($version->product), $version), 404);

        $rules = [];
        foreach (DeclarationData::FIELDS as $key => $max) {
            $rules[$key] = ['nullable', 'string', 'max:'.$max];
        }
        $rules['date'] = ['nullable', 'date_format:Y-m-d'];
        $rules['url'] = ['nullable', 'url:http,https', 'max:300'];
        $data = $request->validate($rules + [
            'include_image' => ['nullable', 'boolean'],
            'legislation' => ['nullable', 'array', 'max:20'],
            'legislation.*' => ['nullable', 'string', 'max:200'],
            'standards' => ['nullable', 'array', 'max:80'],
            'standards.*.reference' => ['nullable', 'string', 'max:160'],
            'standards.*.article' => ['required_with:standards.*.reference', Rule::in(DeclarationData::OTHER_ARTICLES)],
        ]);

        $declaration = (array) ($version->declaration ?? []);
        $before = $declaration;
        foreach (array_keys(DeclarationData::FIELDS) as $key) {
            // Saved, even empty: an emptied proposal is a choice, never filled in again.
            $declaration[$key] = trim(str_replace("\r", '', (string) ($data[$key] ?? '')));
        }
        $declaration['include_image'] = $request->boolean('include_image');
        $declaration['legislation'] = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $data['legislation'] ?? []), fn ($v) => $v !== ''));
        $standards = [];
        foreach ($data['standards'] ?? [] as $row) {
            $reference = trim((string) ($row['reference'] ?? ''));
            if ($reference !== '') {
                $standards[] = ['reference' => $reference, 'article' => (string) $row['article']];
            }
        }
        $declaration['standards'] = $standards;

        $version->forceFill(['declaration' => $declaration])->save();

        $changed = array_keys(array_filter($declaration, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            activity('version')->causedBy($request->user())->performedOn($version)
                ->withProperties(['fields' => $changed, 'status' => $version->status->value])
                ->log('declaration saved');
        }

        return redirect()->to(route('versions.declaration', $version).'#declaration-form')->with('status', __('common.saved'));
    }

    /** The language select (Word or PDF button): on to the address of that file. */
    public function pick(Request $request, ProductVersion $version): RedirectResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        $this->access($version->product);
        $data = $request->validate([
            'lang' => ['required', 'string', Rule::in(array_keys(AnnexText::languages()))],
            'format' => ['required', Rule::in(['docx', 'pdf'])],
        ]);

        return redirect()->route('versions.declaration.download.lang', [$version, $data['lang'], $data['format']], 303);
    }

    /**
     * The declaration as Word or PDF in one official language (/download/de.docx, AnnexText::languages();
     * /download.docx: the person's language), built now, streamed once and deleted.
     */
    public function download(Request $request, ProductVersion $version): BinaryFileResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        $format = (string) $request->route('format');
        abort_unless(in_array($format, ['docx', 'pdf'], true), 404);
        $this->access($version->product);
        $asked = (string) $request->route('lang');
        abort_unless($asked === '' || AnnexText::has($asked), 404);
        $lang = AnnexText::pick($asked !== '' ? $asked : App::getLocale());

        $data = DeclarationData::for($version);
        $path = $this->temporaryFile('redact-doc-');
        try {
            $format === 'pdf' ? (new DeclarationPdf($data, $lang))->save($path) : (new DeclarationWord($data, $lang))->save($path);
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }

        $this->logExport($request, $version, $format, [$lang], $data->isDraft());
        $name = ExportName::make([$version->product->name.' '.$version->label, __('declaration.file_name'), strtoupper($lang)], $format);

        return $this->sendExport($path, $name, $format === 'pdf' ? 'application/pdf' : self::DOCX);
    }

    /** "All languages": one zip with the declaration in every official language of the Directive, Word and PDF. */
    public function allLanguages(Request $request, ProductVersion $version): BinaryFileResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        $this->access($version->product);

        $data = DeclarationData::for($version);
        $target = $this->temporaryFile('redact-doc-');
        $files = [];
        $zip = new ZipArchive;
        try {
            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot write the declarations.');
            }
            foreach (array_keys(AnnexText::languages()) as $lang) {
                $files[$lang.'.docx'] = $this->temporaryFile('redact-doc-');
                (new DeclarationWord($data, $lang))->save($files[$lang.'.docx']);
                $zip->addFile($files[$lang.'.docx'], DeclarationWord::fileName($data, $lang));
                $files[$lang.'.pdf'] = $this->temporaryFile('redact-doc-');
                (new DeclarationPdf($data, $lang))->save($files[$lang.'.pdf']);
                $zip->addFile($files[$lang.'.pdf'], DeclarationPdf::fileName($data, $lang));
            }
            if (! $zip->close()) {
                throw new RuntimeException('Cannot write the declarations.');
            }
        } catch (Throwable $e) {
            @unlink($target);
            throw $e;
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        $this->logExport($request, $version, 'zip', array_keys(AnnexText::languages()), $data->isDraft());
        $name = ExportName::make([$version->product->name.' '.$version->label, __('declaration.file_name_all')], 'zip');

        return $this->sendExport($target, $name, 'application/zip');
    }

    /** @param  list<string>  $languages */
    private function logExport(Request $request, ProductVersion $version, string $format, array $languages, bool $draft): void
    {
        activity('report')->causedBy($request->user())->performedOn($version)
            ->withProperties(['kind' => 'declaration', 'format' => $format, 'version' => $version->id, 'languages' => $languages, 'draft' => $draft])
            ->log('exported');
    }
}
