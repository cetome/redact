<?php

use App\Http\Controllers\IdentificationImportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\WorkbookController;
use Illuminate\Support\Facades\Route;

// The cetome REDact workbooks: filled templates to download, people's own workbooks to import
// through the one document upload (required by reports.php, inside `auth`), and the Product
// identification from Excel.
// Sandbox guests are sent to "create an account" for all of these (GuestSandbox middleware).

Route::get('/versions/{version}/workbooks/{workbook}.xlsx', [WorkbookController::class, 'download'])
    ->where('workbook', '[a-z0-9_-]{1,40}')
    ->middleware('throttle:30,1')
    ->name('versions.workbooks.download');
Route::get('/versions/{version}/workbooks.zip', [WorkbookController::class, 'zip'])
    ->middleware('throttle:10,1')
    ->name('versions.workbooks.zip');

Route::post('/versions/{version}/import', [ImportController::class, 'store'])
    ->middleware('throttle:20,10')
    ->name('versions.import.store');
Route::get('/versions/{version}/import/{token}', [ImportController::class, 'show'])->name('versions.import.show');
Route::post('/versions/{version}/import/{token}', [ImportController::class, 'apply'])
    ->middleware('throttle:20,10')
    ->name('versions.import.apply');
Route::delete('/versions/{version}/import/{token}', [ImportController::class, 'destroy'])->name('versions.import.destroy');

// Product identification from Excel (xlsx or csv) and its template.
Route::post('/versions/{version}/identification/import', [IdentificationImportController::class, 'store'])
    ->middleware('throttle:20,10')
    ->name('versions.identification.import');
Route::get('/versions/{version}/identification/template.xlsx', [IdentificationImportController::class, 'template'])
    ->middleware('throttle:30,1')
    ->name('versions.identification.template');
