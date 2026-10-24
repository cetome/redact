<?php

use App\Http\Controllers\Ai\AssistController;
use App\Http\Controllers\Ai\OrganisationAiController;
use App\Http\Controllers\Ai\ScopingSuggestionController;
use App\Http\Controllers\Ai\SuggestionController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

// AI assistance and product documents (the `auth` middleware is applied in web.php).
// Every AI call is rate-limited per person on top of the unit quota (App\Ai\Quota).

// "Suggest with AI" on New product: the product does not exist yet.
Route::post('/ai/scoping', [ScopingSuggestionController::class, 'forNew'])->name('ai.scoping.new')->middleware('throttle:20,1');

Route::prefix('/versions/{version}')->group(function () {
    // The AI channel: review page, Accept / Reject.
    Route::get('/ai', [SuggestionController::class, 'index'])->name('versions.ai');
    Route::get('/ai/status', [SuggestionController::class, 'status'])->name('ai.status');
    Route::post('/ai/suggestions/accept-group', [SuggestionController::class, 'acceptGroup'])->name('ai.suggestions.accept_group');
    Route::post('/ai/suggestions/{suggestion}/accept', [SuggestionController::class, 'accept'])->name('ai.suggestions.accept');
    Route::post('/ai/suggestions/{suggestion}/reject', [SuggestionController::class, 'reject'])->name('ai.suggestions.reject');

    // Calls to the model.
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/ai/improve', [AssistController::class, 'improve'])->name('ai.improve');
        Route::post('/ai/evaluations/{evaluation}/check', [AssistController::class, 'check'])->name('ai.check');
        Route::post('/ai/evaluations/{evaluation}/suggest-path', [AssistController::class, 'suggestPath'])->name('ai.suggest_path');
        Route::post('/ai/scoping', [ScopingSuggestionController::class, 'forVersion'])->name('ai.scoping');
    });
    Route::post('/ai/draft', [AssistController::class, 'draft'])->name('ai.draft')->middleware('throttle:3,10');

    // Product documents.
    Route::get('/documents', [DocumentController::class, 'index'])->name('versions.documents');
    Route::post('/documents', [DocumentController::class, 'store'])->name('versions.documents.store')->middleware('throttle:30,10');
    Route::get('/documents/{document}', [DocumentController::class, 'download'])->name('versions.documents.download');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('versions.documents.destroy');
});

// An organisation's Settings page: the AI model card (bring your own key or model, App\Ai\OrganisationAi).
Route::get('/organisations/{organisation}/settings', [OrganisationAiController::class, 'show'])->name('organisations.settings');
Route::put('/organisations/{organisation}/settings/ai', [OrganisationAiController::class, 'update'])->name('organisations.settings.ai')->middleware('throttle:20,1');
Route::post('/organisations/{organisation}/settings/ai/test', [OrganisationAiController::class, 'test'])->name('organisations.settings.ai.test')->middleware('throttle:6,1');
