<?php

namespace Tests\Feature\Admin;

use App\Admin\Csv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminCsvAndPruneTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    public function test_csv_cells_cannot_start_a_formula(): void
    {
        $this->assertSame("'=1+2", Csv::cell('=1+2'));
        $this->assertSame("'+33 1 23", Csv::cell('+33 1 23'));
        $this->assertSame("'-5", Csv::cell('-5'));
        $this->assertSame("'@SUM(A1)", Csv::cell('@SUM(A1)'));
        $this->assertSame("'\tx", Csv::cell("\tx"));
        $this->assertSame('plain', Csv::cell('plain'));
        $this->assertSame('', Csv::cell(null));
        $this->assertSame('42', Csv::cell(42));
    }

    public function test_user_export_escapes_formulas(): void
    {
        $this->withoutVite();
        $this->makeUser(['name' => '=HYPERLINK("http://evil.test","x")', 'email' => 'evil@example.test']);

        $response = $this->signIn($this->superAdmin())->get(route('admin.users.export'));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
        $this->assertStringContainsString('evil@example.test', $csv);
    }

    public function test_prune_respects_retention_and_keeps_security_events_for_their_own_period(): void
    {
        config([
            'redact.retention.request_logs_days' => 30,
            'redact.retention.activity_log_days' => 100,
            'redact.retention.security_log_days' => 50,
        ]);

        $log = fn (int $daysAgo) => [
            'method' => 'GET', 'route' => 'home', 'path' => '/', 'status' => 200, 'duration_ms' => 1,
            'created_at' => now()->subDays($daysAgo),
        ];
        DB::table('request_logs')->insert([$log(31), $log(29)]);

        $activity = fn (string $name, int $daysAgo) => [
            'log_name' => $name, 'description' => $name.' '.$daysAgo,
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ];
        DB::table('activity_log')->insert([
            $activity('product', 101), // gone: past activity retention
            $activity('product', 99),  // kept
            $activity('security', 99), // gone: past security retention
            $activity('security', 49), // kept
            array_merge($activity('security', 60), ['log_name' => 'admin']), // not security, within activity retention: kept
        ]);

        $this->artisan('redact:prune-tracking', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, DB::table('request_logs')->count());

        $this->artisan('redact:prune-tracking')->assertSuccessful();

        $this->assertSame(1, DB::table('request_logs')->count());
        $this->assertEqualsCanonicalizing(
            ['product 99', 'security 49', 'security 60'],
            DB::table('activity_log')->pluck('description')->all(),
        );
    }
}
