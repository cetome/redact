<?php

use App\Http\Controllers\SbomController;
use App\Http\Controllers\VexController;
use App\Http\Controllers\VulnerabilityController;
use Illuminate\Support\Facades\Route;

// Bills of materials (SBOM / HBOM) and known vulnerabilities (the `auth` middleware is applied in web.php).

Route::get('/sbom/template.xlsx', [SbomController::class, 'template'])->name('sbom.template');

Route::prefix('/versions/{version}')->name('versions.')->group(function () {
    Route::get('/sbom', [SbomController::class, 'create'])->name('sbom');
    Route::post('/sbom', [SbomController::class, 'store'])->name('sbom.store')->middleware('throttle:20,10');
    Route::get('/sbom/{import}', [SbomController::class, 'show'])->name('sbom.show');
    Route::post('/sbom/{import}/apply', [SbomController::class, 'apply'])->name('sbom.apply');
    Route::delete('/sbom/{import}', [SbomController::class, 'destroy'])->name('sbom.destroy');
    Route::get('/sbom/{import}/files/{file}', [SbomController::class, 'download'])->name('sbom.download')->whereNumber('file');

    // VEX statements, uploaded with the bills of materials (SbomController::store).
    Route::get('/vex/{vex}', [VexController::class, 'show'])->name('vex.show');
    Route::post('/vex/{vex}/apply', [VexController::class, 'apply'])->name('vex.apply');
    Route::delete('/vex/{vex}', [VexController::class, 'destroy'])->name('vex.destroy');
    Route::get('/vex/{vex}/files/{file}', [VexController::class, 'download'])->name('vex.download')->whereNumber('file');

    Route::get('/vulnerabilities', [VulnerabilityController::class, 'index'])->name('vulnerabilities');
    Route::post('/vulnerabilities/check', [VulnerabilityController::class, 'check'])->name('vulnerabilities.check')->middleware('throttle:10,10');
    Route::get('/vulnerabilities/status', [VulnerabilityController::class, 'status'])->name('vulnerabilities.status');
    Route::post('/vulnerabilities/decide', [VulnerabilityController::class, 'decide'])->name('vulnerabilities.decide');
    Route::put('/vulnerabilities/components/{item}', [VulnerabilityController::class, 'identifiers'])->name('vulnerabilities.identifiers');
});
