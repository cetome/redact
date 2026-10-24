<?php

use App\Http\Controllers\CertificateController;
use App\Http\Controllers\DeclarationController;
use App\Http\Controllers\OrganisationLegalController;
use App\Http\Controllers\TechnicalFileController;
use Illuminate\Support\Facades\Route;

// Last step "Declaration & technical file" (the `auth` middleware is applied in web.php).
// Downloads and the certificate need an account: versions.declaration.*, versions.technical-file.*
// and versions.certificate.* are in GuestSandbox::ACCOUNT_REQUIRED.

Route::prefix('/versions/{version}')->name('versions.')->group(function () {
    Route::get('/declaration', [DeclarationController::class, 'show'])->name('declaration');
    // The technical file view of the step (not under versions.declaration.*: sandbox guests see it).
    Route::get('/declaration/file', [DeclarationController::class, 'show'])->name('declaration-file')->defaults('view', 'file');
    Route::put('/declaration', [DeclarationController::class, 'update'])->name('declaration-data');
    Route::get('/declaration/download.{format}', [DeclarationController::class, 'download'])
        ->where('format', 'docx|pdf')->middleware('throttle:30,1')->name('declaration.download');
    // One official language: the language select posts, the answer is the clean address of that file.
    Route::post('/declaration/download', [DeclarationController::class, 'pick'])->name('declaration.pick');
    Route::get('/declaration/download/{lang}.{format}', [DeclarationController::class, 'download'])
        ->where(['lang' => '[a-z]{2}', 'format' => 'docx|pdf'])->middleware('throttle:30,1')->name('declaration.download.lang');
    Route::get('/declaration/all-languages.zip', [DeclarationController::class, 'allLanguages'])->middleware('throttle:10,1')->name('declaration.all');
    Route::put('/certificate', [CertificateController::class, 'update'])->name('certificate.update')->middleware('throttle:20,10');
    Route::get('/technical-file.zip', [TechnicalFileController::class, 'download'])->middleware('throttle:10,1')->name('technical-file.download');
    Route::patch('/technical-file/documents/{document}', [TechnicalFileController::class, 'category'])->name('technical-file.category');
});

Route::put('/organisations/{organisation}/legal', [OrganisationLegalController::class, 'update'])->name('organisations.legal.update');
