<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsureSessionIsValid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminUserActionsTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_disabling_a_user_revokes_their_sessions_and_is_logged(): void
    {
        $staff = $this->staff();
        $customer = $this->makeUser();
        DB::table('login_tokens')->insert([
            'user_id' => $customer->id, 'purpose' => 'login', 'token_hash' => str_repeat('1', 64),
            'code_hash' => str_repeat('2', 64), 'bind_hash' => str_repeat('3', 64), 'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $this->signIn($staff)->post(route('admin.users.disable', $customer))->assertRedirect();

        $customer->refresh();
        $this->assertFalse($customer->active);
        $this->assertSame(1, $customer->session_epoch);
        $this->assertNotNull(DB::table('login_tokens')->where('user_id', $customer->id)->value('used_at'));

        $entry = Activity::query()->where('log_name', 'admin')->where('description', 'user disabled')->first();
        $this->assertNotNull($entry);
        $this->assertSame($staff->id, (int) $entry->causer_id);
        $this->assertSame((string) $customer->id, (string) $entry->subject_id);

        // A session opened before the action no longer works.
        $this->actingAs($customer)->withSession([
            EnsureSessionIsValid::SIGNED_IN_AT => time(),
            EnsureSessionIsValid::EPOCH => 0,
        ])->get(route('account.show'))->assertRedirect(route('login'));

        $this->signIn($staff)->post(route('admin.users.enable', $customer))->assertRedirect();
        $this->assertTrue($customer->fresh()->active);
    }

    public function test_revoking_sessions_deletes_stored_sessions(): void
    {
        config(['session.driver' => 'database']);
        $customer = $this->makeUser();
        DB::table('sessions')->insert([
            'id' => 'abc', 'user_id' => $customer->id, 'ip_address' => '198.51.100.1', 'user_agent' => 'UA',
            'payload' => '', 'last_activity' => time(),
        ]);

        $this->signIn($this->staff())->post(route('admin.users.sessions.revoke', $customer))->assertRedirect();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $customer->id)->count());
        $this->assertSame(1, $customer->fresh()->session_epoch);
    }

    public function test_staff_cannot_manage_other_staff_or_roles(): void
    {
        $staff = $this->staff();
        $colleague = $this->staff();

        $this->signIn($staff)->post(route('admin.users.disable', $colleague))->assertNotFound();
        $this->signIn($staff)->post(route('admin.users.roles', $colleague), ['role' => 'staff', 'action' => 'revoke'])->assertNotFound();
        $this->assertTrue($colleague->fresh()->hasRole('staff'));
    }

    public function test_nobody_can_disable_themselves(): void
    {
        $admin = $this->superAdmin();

        $this->signIn($admin)->post(route('admin.users.disable', $admin))->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->active);
    }

    public function test_a_super_admin_cannot_revoke_their_own_super_admin_role(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin(); // another one exists, so only the self-protection applies

        $this->signIn($admin)
            ->post(route('admin.users.roles', $admin), ['role' => 'super-admin', 'action' => 'revoke'])
            ->assertSessionHas('error', __('admin.users.errors.self_super_admin'));

        $this->assertTrue($admin->fresh()->hasRole('super-admin'));
    }

    public function test_a_super_admin_grants_and_revokes_staff(): void
    {
        $admin = $this->superAdmin();
        $customer = $this->makeUser();

        $this->signIn($admin)->post(route('admin.users.roles', $customer), ['role' => 'staff', 'action' => 'grant'])->assertRedirect();
        $this->assertTrue($customer->fresh()->hasRole('staff'));

        $this->signIn($admin)->post(route('admin.users.roles', $customer), ['role' => 'staff', 'action' => 'revoke'])->assertRedirect();
        $this->assertFalse($customer->fresh()->hasRole('staff'));
        $this->assertSame(2, Activity::query()->where('log_name', 'admin')->where('description', 'like', 'role %')->count());

        $this->signIn($admin)->post(route('admin.users.roles', $customer), ['role' => 'owner', 'action' => 'grant'])->assertSessionHasErrors('role');
    }

    public function test_session_ips_are_shown_to_super_admins_only(): void
    {
        config(['session.driver' => 'database']);
        $customer = $this->makeUser();
        DB::table('sessions')->insert([
            'id' => 'abc', 'user_id' => $customer->id, 'ip_address' => '198.51.100.23', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
            'payload' => '', 'last_activity' => time(),
        ]);

        $this->signIn($this->staff())->get(route('admin.users.show', $customer))->assertOk()->assertSee('Firefox')->assertDontSee('198.51.100.23');
        $this->signIn($this->superAdmin())->get(route('admin.users.show', $customer))->assertOk()->assertSee('198.51.100.23');
    }
}
