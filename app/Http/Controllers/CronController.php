<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * GET /cron/{key}: the background tasks ("php artisan schedule:run") for hosts whose cron can only
 * call a URL (IONOS shared hosting). The key is REDACT_CRON_KEY in .env; without it, or with any
 * other key, the address answers 404 like a page that does not exist. Outside the web middleware
 * (routes/cron.php): no session, no cookie, no request log.
 */
class CronController extends Controller
{
    public function __invoke(string $key): Response
    {
        $expected = (string) config('redact.cron_key');
        abort_if($expected === '' || ! hash_equals($expected, $key), 404);

        // The queue slices run up to ~35 s: finish them even if the cron client hangs up.
        ignore_user_abort(true);
        @set_time_limit(120);

        Artisan::call('schedule:run');

        return response('ok', 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
