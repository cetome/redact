<?php

namespace App\Http\Controllers;

use App\Models\ProductVersion;
use App\Reports\ExportName;
use App\Workbooks\IdentifierImport;
use App\Workbooks\WorkbookException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * "Import from Excel" of the Product identification panel: models and identifiers read from an
 * xlsx or csv file (IdentifierImport), added to the rows already there; and its template.
 */
class IdentificationImportController extends Controller
{
    public function store(Request $request, ProductVersion $version): RedirectResponse
    {
        $this->allow('edit', $version);
        $this->access($version->product);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv']]);

        $back = redirect()->to(route('versions.edit', $version).'#identification');
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        try {
            $read = IdentifierImport::rows(IdentifierImport::table((string) $file->getRealPath(), $extension));
        } catch (WorkbookException $e) {
            return $back->withErrors(['identification_file' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return $back->withErrors(['identification_file' => __('identification.import.unreadable')]);
        }

        $result = IdentifierImport::apply($version, $read['rows']);
        activity('version')->causedBy($request->user())->performedOn($version)
            ->withProperties(['added' => $result['added'], 'existing' => $result['existing'], 'file' => $file->getClientOriginalName()])
            ->log('identifiers imported');

        $message = trans_choice('identification.import.added', $result['added'], ['count' => $result['added']])
            .' '.trans_choice('identification.import.existing', $result['existing'], ['count' => $result['existing']]);
        if ($read['card_columns'] !== []) {
            $message .= ' '.__('identification.import.card_columns', ['columns' => implode(', ', $read['card_columns'])]);
        }

        return $back->with('status', $message);
    }

    public function template(ProductVersion $version): BinaryFileResponse
    {
        $this->allow('view', $version);
        $this->access($version->product);
        $path = tempnam(sys_get_temp_dir(), 'redact-ids-');
        if ($path === false) {
            abort(500);
        }
        IdentifierImport::template($path);
        $name = ExportName::make([__('identification.title')], 'xlsx');

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ], false, null, false, false);
        $response->headers->set('Content-Disposition', ReportController::disposition($name));
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
