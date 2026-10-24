<?php

namespace Tests\Feature\Guest;

use App\Admin\DashboardStats;
use App\Admin\UsageAnalytics;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Admin\AdminTestHelpers;
use Tests\TestCase;

/**
 * The console: sandbox figures, guests hidden from the users list by
 * default, page views split between accounts, guests and visitors.
 */
class GuestAdminTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    private function guest(array $attributes = []): User
    {
        return $this->makeUser(array_merge([
            'name' => 'Guest',
            'email' => 'guest-'.strtolower((string) Str::ulid()).'@guest.invalid',
            'email_verified_at' => null,
            'is_guest' => true,
            'guest_expires_at' => now()->addHours(20),
        ], $attributes));
    }

    public function test_the_users_list_hides_guests_unless_asked(): void
    {
        $staff = $this->staff();
        $customer = $this->makeUser(['name' => 'Real Customer']);
        $guest = $this->guest();

        $this->signIn($staff)->get(route('admin.users.index'))->assertOk()
            ->assertSee($customer->email)->assertDontSee($guest->email);
        $this->post(route('admin.users.filter'), ['status' => 'guests']);
        $this->get(route('admin.users.index'))->assertOk()
            ->assertSee($guest->email)->assertDontSee($customer->email);
    }

    public function test_dashboard_counts_sandboxes_from_the_guest_log(): void
    {
        $this->guest();
        $this->guest(['guest_expires_at' => now()->subMinute()]);
        activity('guest')->log('sandbox started');
        activity('guest')->log('sandbox started');
        activity('guest')->log('sandbox kept');
        $old = activity('guest')->log('sandbox expired');
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $figures = (new DashboardStats)->sandboxes();
        $this->assertSame(2, $figures['started_7']);
        $this->assertSame(1, $figures['kept_7']);
        $this->assertSame(0, $figures['expired_7']);
        $this->assertSame(1, $figures['expired_30']);
        $this->assertSame(1, $figures['active']);

        // Guests are not accounts.
        $this->assertSame(0, (new DashboardStats)->kpis()['users']);

        $this->signIn($this->staff())->get(route('admin.dashboard'))->assertOk()->assertSee(__('admin.sandboxes.title'));
    }

    public function test_page_views_are_split_by_audience_even_after_a_purge(): void
    {
        $customer = $this->makeUser();
        $guest = $this->guest();
        $row = fn (?int $user, ?bool $isGuest) => [
            'user_id' => $user, 'guest' => $isGuest, 'method' => 'GET', 'route' => 'dashboard', 'path' => '/dashboard',
            'status' => 200, 'duration_ms' => 5, 'created_at' => now(),
        ];
        // Only these rows: on MariaDB/MySQL the request logger has also recorded the sandbox start above.
        DB::table('request_logs')->delete();
        DB::table('request_logs')->insert([
            $row($customer->id, false),
            $row($customer->id, null),   // written before the column existed
            $row($guest->id, true),
            $row(null, true),            // a purged guest
            $row(null, null),            // a visitor
        ]);

        $this->assertSame(['registered' => 2, 'guest' => 2, 'anonymous' => 1], UsageAnalytics::for(30)->pageViewsByAudience());

        $this->signIn($this->staff())->get(route('admin.usage.index'))->assertOk()->assertSee(__('admin.usage.audience'));
    }
}
