<?php

use App\Http\Controllers\CronController;
use Illuminate\Support\Facades\Route;

/*
| HTTP cron (hosts whose cron only calls a URL, e.g. IONOS): GET https://<host>/cron/<REDACT_CRON_KEY>
| every minute. Outside the `web` group on purpose (no session, cookies or request log). Loaded by
| bootstrap/app.php.
*/

Route::get('/cron/{key}', CronController::class)->name('cron')
    ->where('key', '[A-Za-z0-9]{32,128}')
    ->middleware('throttle:10,1');
