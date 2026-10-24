<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** GET /cron/{key}: runs the scheduler for hosts whose cron only calls a URL (IONOS). */
class HttpCronTest extends TestCase
{
    private const KEY = 'a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R8s9T0';

    protected function tearDown(): void
    {
        // The controller's set_time_limit(120) applies to the whole PHP process: without this, every
        // later test shares those 120 seconds and a slow one (DemoPackTest) is killed mid-run.
        set_time_limit(0);
        parent::tearDown();
    }

    public function test_the_right_key_runs_the_scheduler_without_a_session(): void
    {
        config(['redact.cron_key' => self::KEY]);
        Artisan::shouldReceive('call')->once()->with('schedule:run')->andReturn(0);

        $this->get('/cron/'.self::KEY)->assertOk()->assertSee('ok')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_a_wrong_or_missing_key_is_a_404(): void
    {
        config(['redact.cron_key' => self::KEY]);
        $this->get('/cron/'.str_repeat('x', 40))->assertNotFound();

        config(['redact.cron_key' => null]);
        $this->get('/cron/'.self::KEY)->assertNotFound();
    }
}
