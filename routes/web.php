<?php

use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeStartController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\PublicProductController;
use App\Http\Controllers\TemplateController;
use App\Models\ContactMessage;
use App\PublicPage\PublicCode;
use Illuminate\Support\Facades\Route;

/*
| One file per area, so features can evolve independently:
|   routes/auth.php     sign-up, sign-in (e-mail link/code, passkeys), account
|   routes/guest.php    guest sandbox (try without an account, keep my work)
|   routes/app.php      products, versions, team, assessment workflow, decision trees
|   routes/ai.php       AI assistance (suggestions, documents)
|   routes/reports.php  Word / Excel exports
|   routes/sbom.php     bills of materials import, known vulnerabilities
|   routes/declaration.php  EU declaration of conformity, certificate, technical file
|   routes/billing.php  plan and billing of an organisation (Stripe webhooks: routes/stripe.php)
|   routes/admin.php    cetome console (staff only)
*/

Route::get('/', [PublicController::class, 'home'])->name('home');
// The home page tool (#start): starts a sandbox (ALTCHA, per-IP limit) or creates the product.
Route::post('/start', HomeStartController::class)->name('home.start')->middleware('throttle:20,60,home-start');
Route::get('/about', [PublicController::class, 'about'])->name('about');
Route::get('/pricing', [PublicController::class, 'pricing'])->name('pricing');
// Free templates: the original workbooks without an account (crawlers 403, 4 downloads per 30 s, counted).
Route::get('/templates', [TemplateController::class, 'index'])->name('templates');
// The picture's version is part of its name (cached for a year); /templates/{file}.webp stays for older pages.
Route::get('/templates/{file}.{v}.webp', [TemplateController::class, 'image'])->name('templates.image.v')->where(['file' => '[a-z0-9-]{1,40}', 'v' => '[0-9a-f]{8}']);
Route::get('/templates/{file}.webp', [TemplateController::class, 'image'])->name('templates.image')->where('file', '[a-z0-9-]{1,40}');
Route::get('/templates/{file}', [TemplateController::class, 'download'])->name('templates.download')->where('file', '[a-z0-9-]{1,40}');
Route::get('/dl', [TemplateController::class, 'legacy'])->name('templates.legacy');
// Contact form (App\Http\Controllers\ContactController: per-IP and per-address limits inside, invisible ALTCHA).
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'send'])->name('contact.send')->middleware('throttle:30,60,contact-send');
Route::get('/contact/altcha', [ContactController::class, 'challenge'])->name('contact.altcha')->middleware('throttle:30,1,contact-altcha');
// The form with a topic chosen (pricing, privacy...: App\Models\ContactMessage::TOPICS).
Route::get('/contact/{topic}', [ContactController::class, 'show'])->name('contact.topic')->whereIn('topic', ContactMessage::TOPICS);
Route::get('/terms', [PublicController::class, 'terms'])->name('legal.terms');
Route::get('/privacy', [PublicController::class, 'privacy'])->name('legal.privacy');
// The legal notice is part of the terms page (old links keep working).
Route::permanentRedirect('/legal', '/terms')->name('legal.notice');

// The previous site's workbook files (/files/…xlsx): permanent redirect to the Free templates page
// (its download page /dl is handled above by TemplateController::legacy).
Route::get('/files/{any}', fn () => redirect('/templates', 301))->where('any', '.*');
// Public product page (App\PublicPage): /verify/{code} is the product's latest published version, an
// earlier one is /verify/{code}/{version}; a language picked on the page is the last segment.
Route::middleware('throttle:120,1')->name('verify.')->group(function () {
    $code = ['code' => PublicCode::PATTERN];
    $lang = implode('|', array_keys(config('redact.locales')));
    Route::get('/verify/{code}', [PublicProductController::class, 'show'])->name('show')->where($code);
    Route::get('/verify/{code}/picture', [PublicProductController::class, 'picture'])->name('picture')->where($code);
    Route::get('/verify/{code}/logo', [PublicProductController::class, 'logo'])->name('logo')->where($code);
    Route::get('/verify/{code}/{lang}', [PublicProductController::class, 'show'])->name('show.lang')->where($code + ['lang' => $lang]);
    Route::get('/verify/{code}/{version}', [PublicProductController::class, 'show'])->name('version')->where($code)->whereUlid('version');
    Route::get('/verify/{code}/{version}/{lang}', [PublicProductController::class, 'show'])->name('version.lang')->where($code + ['lang' => $lang])->whereUlid('version');
    Route::get('/verify/{code}/{version}/documents/{document}', [PublicProductController::class, 'document'])->name('document')->where($code)->whereUlid('version');
    Route::get('/verify/{code}/{version}/bom/{import}/{file}', [PublicProductController::class, 'bom'])->name('bom')->where($code)->whereUlid('version')->whereNumber('file');
    Route::get('/verify/{code}/{version}/vex/{vex}/{file}', [PublicProductController::class, 'vex'])->name('vex')->where($code)->whereUlid('version')->whereNumber('file');
});
Route::get('/.well-known/security.txt', [PublicController::class, 'securityTxt'])->name('security.txt');
// The social card: its version is part of its name, so the networks fetch a new picture again.
Route::get('/images/og-redact.{v}.png', [PublicController::class, 'ogImage'])->name('og.image')->where('v', '[0-9a-f]{8}');
Route::get('/robots.txt', [PublicController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [PublicController::class, 'sitemap'])->name('sitemap');

require __DIR__.'/auth.php';
require __DIR__.'/guest.php';

Route::middleware('auth')->group(function () {
    require __DIR__.'/app.php';
    require __DIR__.'/ai.php';
    require __DIR__.'/reports.php';
    require __DIR__.'/sbom.php';
    require __DIR__.'/declaration.php';
    require __DIR__.'/billing.php';
});

Route::middleware(['auth', 'staff'])->prefix('admin')->name('admin.')->group(__DIR__.'/admin.php');
