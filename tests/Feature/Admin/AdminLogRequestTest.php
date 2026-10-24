<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\LogRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminLogRequestTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Route::middleware('web')->group(function () {
            Route::get('/_test/products/{product}', fn () => 'ok')->name('test.product');
            Route::get('/invitations/{token}', fn () => 'ok')->name('test.invitation');
            Route::get('/login/{token}', fn () => 'ok')->name('test.login');
            Route::get('/_test/public', fn () => 'ok')->name('test.public');
        });
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_it_logs_a_signed_in_request_with_a_hashed_ip_and_no_query_string(): void
    {
        $user = $this->makeUser();
        $ulid = (string) Str::ulid();

        $this->signIn($user)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get('/_test/products/'.$ulid.'?secret=abc')
            ->assertOk();

        $row = DB::table('request_logs')->where('route', 'test.product')->first();
        $this->assertNotNull($row);
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertSame('/_test/products/'.$ulid, $row->path);
        $this->assertSame($ulid, $row->product_id);
        $this->assertSame(200, (int) $row->status);
        $this->assertSame(hash_hmac('sha256', '203.0.113.7', (string) config('app.key')), $row->ip_hash);
        $this->assertSame(LogRequest::hashIp('203.0.113.7'), $row->ip_hash);
        $this->assertStringNotContainsString('203.0.113.7', json_encode($row));
        $this->assertStringNotContainsString('secret', json_encode($row));
    }

    public function test_it_masks_tokens_in_invitation_and_sign_in_links(): void
    {
        $this->get('/invitations/SuperSecretToken123')->assertOk();
        $this->get('/login/AnotherSecretToken456')->assertOk();

        $paths = DB::table('request_logs')->pluck('path')->all();
        $this->assertContains('/invitations/{token}', $paths);
        $this->assertContains('/login/{token}', $paths);
        $this->assertStringNotContainsString('Secret', implode(' ', $paths));
    }

    public function test_anonymous_public_hits_are_logged_without_a_user(): void
    {
        $this->get('/_test/public')->assertOk();

        $row = DB::table('request_logs')->where('route', 'test.public')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
    }

    public function test_health_check_and_unrouted_paths_are_not_logged(): void
    {
        $this->get('/up');
        $this->get('/build/assets/app.js');

        $this->assertSame(0, DB::table('request_logs')->count());
    }

    public function test_a_logging_failure_never_breaks_the_request(): void
    {
        $user = $this->makeUser();
        Schema::drop('request_logs');

        $this->signIn($user)->get('/_test/public')->assertOk();
    }
}
