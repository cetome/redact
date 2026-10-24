<?php

namespace App\Http\Controllers;

use App\Models\ProductVersion;
use App\Reports\CetomeWorkbooks;
use App\Reports\ExcelReport;
use App\Reports\ExportName;
use App\Reports\ReportData;
use App\Reports\ReportKind;
use App\Reports\WordReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * The ready reports of a version: Word documents and an Excel workbook built
 * on the server from the human fields, streamed once and deleted.
 */
class ReportController extends Controller
{
    private const MIME = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function index(Request $request, ProductVersion $version, CetomeWorkbooks $workbooks): View
    {
        abort_unless(Gate::allows('view', $version), 404);
        $access = $this->access($version->product);

        return view('reports.index', [
            'version' => $version,
            'access' => $access,
            'kinds' => ReportKind::cases(),
            // Sandbox guests see the downloads locked: files need an account (GuestSandbox).
            'isGuest' => (bool) $request->user()?->is_guest,
            'workbooks' => $workbooks->overview(),
            'left' => $workbooks->available() === [] ? [] : $workbooks->mapping()->leftForOwner(),
        ]);
    }

    public function download(Request $request, ProductVersion $version, string $kind, string $format): BinaryFileResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        $report = ReportKind::tryFrom($kind);
        abort_unless($report && in_array($format, $report->formats(), true), 404);
        $this->access($version->product);

        $data = ReportData::for($version, $request->user());
        $path = tempnam(sys_get_temp_dir(), 'redact-report-');
        if ($path === false) {
            abort(500);
        }

        try {
            $format === 'docx'
                ? (new WordReport($data, $report))->save($path)
                : (new ExcelReport($data, $report))->save($path);
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }

        activity('report')
            ->causedBy($request->user())
            ->performedOn($version)
            ->withProperties(['kind' => $report->value, 'format' => $format, 'version' => $version->id])
            ->log('exported');

        $name = ExportName::make([$version->product->name.' '.$version->label, $report->fileContent()], $format);

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => self::MIME[$format],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ], false, null, false, false);
        $response->headers->set('Content-Disposition', self::disposition($name));
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /** attachment; an ASCII fallback, then the exact name as RFC 5987 filename*. */
    public static function disposition(string $name): string
    {
        $fallback = preg_replace('/[^\x20-\x7E]|["\\\\%]/u', '_', $name) ?: 'report';

        return 'attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($name);
    }
}
