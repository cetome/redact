<?php

namespace App\Http\Controllers;

use App\Models\ProductVersion;
use App\Reports\CetomeWorkbooks;
use App\Reports\ExportName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * "Your cetome REDact workbooks": the owner's Excel templates filled with the version, built on
 * the server when asked (TemplateFiller), streamed once and deleted. Same access as the other
 * reports; logged as a report export (activity log "report", counted by the usage statistics).
 */
class WorkbookController extends Controller
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function download(Request $request, ProductVersion $version, string $workbook, CetomeWorkbooks $workbooks): BinaryFileResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        $definition = $workbooks->workbook($workbook);
        abort_unless($definition !== null, 404);
        $this->access($version->product);

        $path = $this->temporary();
        try {
            $result = $workbooks->fill($version, $definition, $path);
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }
        $this->log($request, $version, 'workbook:'.$workbook, 'xlsx', [
            'fixes' => array_column($result['applied'], 'id'),
            'notes' => count($result['notes']),
        ]);

        return $this->send($path, CetomeWorkbooks::fileName($version, $definition), self::XLSX);
    }

    public function zip(Request $request, ProductVersion $version, CetomeWorkbooks $workbooks): BinaryFileResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        abort_if($workbooks->available() === [], 404);
        $this->access($version->product);

        $path = $this->temporary();
        try {
            $keys = $workbooks->zip($version, $path);
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }
        $this->log($request, $version, 'workbooks', 'zip', ['workbooks' => $keys]);

        $name = ExportName::plain([trim($version->product->name.' '.$version->label), __('workbooks.zip_name')], 'zip');

        return $this->send($path, $name, 'application/zip');
    }

    private function temporary(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'redact-workbook-');
        if ($path === false) {
            abort(500);
        }

        return $path;
    }

    /** @param  array<string, mixed>  $extra */
    private function log(Request $request, ProductVersion $version, string $kind, string $format, array $extra): void
    {
        activity('report')
            ->causedBy($request->user())
            ->performedOn($version)
            ->withProperties(['kind' => $kind, 'format' => $format, 'version' => $version->id, ...$extra])
            ->log('exported');
    }

    private function send(string $path, string $name, string $mime): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ], false, null, false, false);
        $response->headers->set('Content-Disposition', ReportController::disposition($name));
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
