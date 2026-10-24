<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\ReportController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exports built on the server (declaration, technical file): a temporary file streamed once as an
 * attachment with nosniff and no cache, then deleted, like the reports.
 */
trait SendsExports
{
    protected const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    protected function temporaryFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            abort(500);
        }

        return $path;
    }

    protected function sendExport(string $path, string $name, string $mime): BinaryFileResponse
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
