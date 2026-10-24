<?php

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Word / Excel reports of a version (the `auth` middleware is applied in web.php).

Route::get('/versions/{version}/reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('/versions/{version}/reports/{kind}.{format}', [ReportController::class, 'download'])
    ->where(['kind' => 'assessment|trees|review', 'format' => 'docx|xlsx'])
    ->middleware('throttle:30,1')
    ->name('reports.download');

require __DIR__.'/workbooks.php';
