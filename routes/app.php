<?php

use App\Http\Controllers\AssessmentUnitController;
use App\Http\Controllers\ConformityController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DecisionTreeController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\EinfoController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\IdentificationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OrganisationTeamController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RiskController;
use App\Http\Controllers\ScopingController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\VersionController;
use App\Http\Middleware\EnsureOrganisation;
use Illuminate\Support\Facades\Route;

// Signed-in area (the `auth` middleware is applied in web.php).

// Someone without an organisation lands on their invitation or gets their own (EnsureOrganisation).
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard')->middleware(EnsureOrganisation::class);
// "Load the demo" (accounts; sandbox guests use /try/demo): the read-only demo product, one per organisation.
Route::post('/demo', [DemoController::class, 'account'])->name('demo.load')->middleware([EnsureOrganisation::class, 'throttle:10,1']);

// Products and their versions
Route::resource('products', ProductController::class)->except('index')->middlewareFor(['create', 'store'], EnsureOrganisation::class);
Route::post('/products/{product}/versions', [VersionController::class, 'store'])->name('versions.store');
Route::get('/products/{product}/picture', [IdentificationController::class, 'showImage'])->name('products.image');
Route::post('/products/{product}/picture', [IdentificationController::class, 'storeImage'])->name('products.image.store')->middleware('throttle:20,10');
Route::delete('/products/{product}/picture', [IdentificationController::class, 'destroyImage'])->name('products.image.destroy');
Route::put('/versions/{version}/identification', [IdentificationController::class, 'update'])->name('versions.identification');

// Team and invitations
Route::get('/products/{product}/team', [TeamController::class, 'index'])->name('team.index');
// The same page with the invitation set to Reviewer, or with the transfer dialog open (no-script fallbacks).
Route::get('/products/{product}/team/reviewer', [TeamController::class, 'index'])->name('team.reviewer')->defaults('panel', 'reviewer');
Route::get('/products/{product}/team/assessor', [TeamController::class, 'index'])->name('team.assessor')->defaults('panel', 'assessor');
Route::get('/products/{product}/team/transfer', [TeamController::class, 'index'])->name('team.transfer.show')->defaults('panel', 'transfer');
Route::post('/products/{product}/team', [TeamController::class, 'invite'])->name('team.invite')->middleware('throttle:30,60');
Route::post('/products/{product}/team/transfer', [TeamController::class, 'transfer'])->name('team.transfer');
Route::patch('/products/{product}/team/{member}', [TeamController::class, 'update'])->name('team.update');
Route::delete('/products/{product}/team/{member}', [TeamController::class, 'destroy'])->name('team.destroy');
Route::delete('/products/{product}/invitations/{invitation}', [TeamController::class, 'revoke'])->name('team.revoke');
// The organisation invitation waiting for the signed-in address (no link needed), accept or decline by id.
Route::get('/invitation', [InvitationController::class, 'pending'])->name('invitations.pending');
Route::post('/invitation/{invitation}', [InvitationController::class, 'acceptPending'])->name('invitations.accept-pending');
Route::post('/invitation/{invitation}/decline', [InvitationController::class, 'decline'])->name('invitations.decline');
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');

// History of a product (all its versions) and of an organisation (App\History): owner and organisation admins.
// Filters are POSTed and kept in the session (App\Support\SessionFilters), older pages are /before/{activity id}.
Route::get('/products/{product}/history', [HistoryController::class, 'product'])->name('products.history');
Route::get('/products/{product}/history/before/{before}', [HistoryController::class, 'product'])->name('products.history.before')->whereNumber('before');
Route::post('/products/{product}/history', [HistoryController::class, 'productFilter'])->name('products.history.filter');
Route::get('/products/{product}/history.csv', [HistoryController::class, 'productCsv'])->name('products.history.csv')->middleware('throttle:5,1');
Route::get('/organisations/{organisation}/history', [HistoryController::class, 'organisation'])->name('organisations.history');
Route::get('/organisations/{organisation}/history/before/{before}', [HistoryController::class, 'organisation'])->name('organisations.history.before')->whereNumber('before');
Route::post('/organisations/{organisation}/history', [HistoryController::class, 'organisationFilter'])->name('organisations.history.filter');
Route::get('/organisations/{organisation}/history.csv', [HistoryController::class, 'organisationCsv'])->name('organisations.history.csv')->middleware('throttle:5,1');

// Organisation team: everyone in the organisation and their products (admins manage, members read).
Route::get('/team', [OrganisationTeamController::class, 'mine'])->name('organisations.team.mine')->middleware(EnsureOrganisation::class);
Route::prefix('/organisations/{organisation}/team')->name('organisations.team')->group(function () {
    Route::get('/', [OrganisationTeamController::class, 'index']);
    // The invite dialog and one person's dialog open (no-script fallbacks, data-dialog-fetch loads the latter).
    Route::get('/invite', [OrganisationTeamController::class, 'index'])->name('.invite.show')->defaults('panel', 'invite');
    Route::get('/{person}', [OrganisationTeamController::class, 'index'])->name('.person')->whereNumber('person');
    Route::post('/', [OrganisationTeamController::class, 'invite'])->name('.invite')->middleware('throttle:30,60');
    Route::patch('/{person}', [OrganisationTeamController::class, 'update'])->name('.update')->whereNumber('person');
    Route::delete('/{person}', [OrganisationTeamController::class, 'destroy'])->name('.destroy')->whereNumber('person');
    Route::delete('/invitations/{invitation}', [OrganisationTeamController::class, 'revoke'])->name('.revoke');
});

// Assessment workflow of one version
Route::prefix('/versions/{version}')->name('versions.')->group(function () {
    Route::get('/', [VersionController::class, 'show'])->name('show');
    Route::get('/new-version', [VersionController::class, 'show'])->name('show.new-version')->defaults('panel', 'new-version');
    Route::get('/description', [VersionController::class, 'edit'])->name('edit');
    Route::put('/description', [VersionController::class, 'update'])->name('update');
    Route::post('/submit', [VersionController::class, 'submit'])->name('submit');
    Route::post('/withdraw', [VersionController::class, 'withdraw'])->name('withdraw');
    Route::post('/reopen', [VersionController::class, 'reopen'])->name('reopen');

    Route::get('/scoping', [ScopingController::class, 'edit'])->name('scoping');
    Route::put('/scoping', [ScopingController::class, 'update'])->name('scoping.update');

    Route::get('/risks', [RiskController::class, 'index'])->name('risks');
    // The page with one panel open (no-script fallbacks; data-dialog-open / data-dialog-fetch open them in place).
    Route::get('/risks/new', [RiskController::class, 'index'])->name('risks.new')->defaults('panel', 'new');
    Route::get('/risks/library', [RiskController::class, 'index'])->name('risks.library.show')->defaults('panel', 'library');
    Route::get('/risks/matrix', [RiskController::class, 'index'])->name('risks.matrix.show')->defaults('panel', 'matrix');
    Route::get('/risks/{risk}', [RiskController::class, 'edit'])->name('risks.edit')->whereUlid('risk');
    Route::post('/risks', [RiskController::class, 'store'])->name('risks.store');
    Route::put('/risks/matrix', [RiskController::class, 'updateMatrix'])->name('risks.matrix');
    Route::post('/risks/library', [RiskController::class, 'addFromLibrary'])->name('risks.library');
    Route::put('/risks/{risk}', [RiskController::class, 'update'])->name('risks.update');
    Route::delete('/risks/{risk}', [RiskController::class, 'destroy'])->name('risks.destroy');

    Route::post('/inventory-prefill', [InventoryController::class, 'prefill'])->name('inventory.prefill');
    Route::get('/inventory/{list}', [InventoryController::class, 'index'])->name('inventory');
    Route::get('/inventory/{list}/pick', [InventoryController::class, 'pick'])->name('inventory.pick');
    // The list with a panel open (no-script fallbacks), and the mapping view of its relations.
    Route::get('/inventory/{list}/new', [InventoryController::class, 'index'])->name('inventory.new')->defaults('panel', 'new');
    Route::get('/inventory/{list}/prefill', [InventoryController::class, 'index'])->name('inventory.prefill.show')->defaults('panel', 'prefill');
    Route::get('/inventory/{list}/mappings', [InventoryController::class, 'index'])->name('inventory.mappings')->defaults('panel', 'mappings');
    Route::get('/inventory/{list}/mappings/{relation}', [InventoryController::class, 'index'])->name('inventory.mappings.relation')
        ->defaults('panel', 'mappings')->where('relation', '[A-Za-z0-9_.-]{1,60}');
    Route::get('/inventory/{list}/{item}', [InventoryController::class, 'edit'])->name('inventory.edit')->whereUlid('item');
    Route::post('/inventory/{list}', [InventoryController::class, 'store'])->name('inventory.store');
    Route::post('/inventory/{list}/group', [InventoryController::class, 'group'])->name('inventory.group');
    Route::post('/inventory/{list}/none', [InventoryController::class, 'markNone'])->name('inventory.none');
    Route::delete('/inventory/{list}/none', [InventoryController::class, 'undoNone'])->name('inventory.none.undo');
    Route::post('/inventory/{list}/{item}/ungroup', [InventoryController::class, 'ungroup'])->name('inventory.ungroup');
    Route::put('/inventory/{list}/{item}', [InventoryController::class, 'update'])->name('inventory.update');
    Route::delete('/inventory/{list}/{item}', [InventoryController::class, 'destroy'])->name('inventory.destroy');
    Route::put('/links/{relation}', [InventoryController::class, 'updateLinks'])->name('inventory.links');

    Route::get('/evaluate/{standard}', [EvaluationController::class, 'index'])->name('evaluate');
    // Leaving for the decision trees viewer: the way back is kept in the session (DecisionTreeController).
    Route::get('/evaluate/{standard}/trees', [DecisionTreeController::class, 'fromAssessment'])->name('evaluate.trees');
    Route::get('/evaluate/{standard}/{requirement}', [EvaluationController::class, 'show'])->name('evaluate.requirement');
    // One object's walk: "product" or an inventory item.
    Route::get('/evaluate/{standard}/{requirement}/{target}', [EvaluationController::class, 'show'])->name('evaluate.target')
        ->where('target', 'product|[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}');
    Route::put('/evaluate/{standard}/{requirement}', [EvaluationController::class, 'update'])->name('evaluate.update');
    Route::post('/evaluate/{standard}/{requirement}/untie', [EvaluationController::class, 'untie'])->name('evaluate.untie');
    Route::post('/evaluate/{standard}/{requirement}/retie', [EvaluationController::class, 'retie'])->name('evaluate.retie');

    // E.Info: every object, or the incomplete ones only (/missing), one object shown at a time.
    Route::get('/einfo/{standard}', [EinfoController::class, 'index'])->name('einfo');
    Route::get('/einfo/{standard}/missing', [EinfoController::class, 'index'])->name('einfo.missing')->defaults('show', 'missing');
    Route::get('/einfo/{standard}/missing/{object}', [EinfoController::class, 'index'])->name('einfo.missing.object')
        ->defaults('show', 'missing')->where('object', 'product|[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}');
    Route::get('/einfo/{standard}/{object}', [EinfoController::class, 'index'])->name('einfo.object')
        ->where('object', 'product|[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}');
    // The object select of narrow screens without script.
    Route::post('/einfo/{standard}/pick', [EinfoController::class, 'pick'])->name('einfo.pick');
    Route::put('/einfo/{standard}', [EinfoController::class, 'update'])->name('einfo.update');

    Route::get('/conformity', [ConformityController::class, 'edit'])->name('conformity');
    Route::put('/conformity', [ConformityController::class, 'update'])->name('conformity.update');

    // The assessment units of the standard: the manufacturer provides each result with its evidence.
    Route::get('/assessment-units', [AssessmentUnitController::class, 'index'])->name('units');
    Route::post('/assessment-units', [AssessmentUnitController::class, 'record'])->name('units.record');
    // In REDact or outside REDact (an independent test laboratory).
    Route::post('/assessment-units/where', [AssessmentUnitController::class, 'where'])->name('units.where');

    // Review = view and validate, never edit: the evaluations (one read-only evidence panel each,
    // loaded in place by data-dialog-fetch, or /evaluations/{evaluation} without script), the units.
    Route::get('/review', [ReviewController::class, 'index'])->name('review');
    Route::get('/review/evaluations/{evaluation}', [ReviewController::class, 'evidencePanel'])->name('review.evidence')->whereUlid('evaluation');
    Route::get('/review/units', [ReviewController::class, 'index'])->name('review.units')->defaults('view', 'units');
    Route::post('/review/evaluations/{evaluation}', [ReviewController::class, 'reviewEvaluation'])->name('review.evaluation');
    Route::post('/review/units/{result}', [ReviewController::class, 'reviewUnit'])->name('review.unit');
    Route::post('/review/decision', [ReviewController::class, 'decide'])->name('review.decide');
});

// EN 18031 decision trees (viewer)
Route::get('/trees', [DecisionTreeController::class, 'index'])->name('trees.index');
Route::get('/trees/{standard}', [DecisionTreeController::class, 'standard'])->name('trees.standard');
Route::get('/trees/{standard}/{requirement}', [DecisionTreeController::class, 'show'])->name('trees.show');
