<?php

namespace Tests\Feature\Auth;

use App\Auth\SignIn;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The account page in tabs (/account, /account/{tab}): only the selected tab is rendered, every form comes
 * back to its own tab, and the keyboard shortcuts preference.
 */
class AccountTabsTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        Mail::fake();
    }

    private function tab(string $tab): string
    {
        return $tab === 'profile' ? route('account.show') : route('account.tab', $tab);
    }

    private function assertCurrentTab(string|false $html, string $tab): void
    {
        $this->assertMatchesRegularExpression('#href="'.preg_quote($this->tab($tab), '#').'"\s+class="a-tab"\s+aria-current="page"#', (string) $html);
        $this->assertSame(1, preg_match_all('#class="a-tab"\s+aria-current="page"#', (string) $html), 'Exactly one current tab.');
    }

    public function test_each_tab_renders_only_its_own_panel(): void
    {
        $user = $this->makeUser();
        $user->passkeys()->create(['name' => 'Work laptop', 'credential_id' => bin2hex(random_bytes(8)), 'credential' => []]);
        $this->signedIn($user);

        $profile = $this->get('/account')->assertOk()
            ->assertSee('id="profile-heading"', false)->assertSee('name="shortcuts"', false)
            ->assertDontSee('id="passkeys-heading"', false)->assertDontSee('id="delete-heading"', false);
        $this->assertCurrentTab($profile->getContent(), 'profile');

        $security = $this->get('/account/security')->assertOk()
            ->assertSee('Work laptop')->assertSee('id="email-heading"', false)
            ->assertDontSee('id="profile-heading"', false)
            ->assertSee('<span class="a-tab-count">1</span>', false);
        $this->assertCurrentTab($security->getContent(), 'security');

        $this->get('/account/sessions')->assertOk()->assertSee(route('account.sessions.revoke'), false)->assertDontSee('Work laptop');
        $this->get('/account/data')->assertOk()->assertSee(route('account.export'), false)->assertDontSee('id="delete-heading"', false);
        $this->get('/account/delete')->assertOk()->assertSee('a-panel-danger', false)->assertSee('name="confirm"', false)->assertDontSee(route('account.export'), false);
    }

    public function test_an_unknown_tab_shows_the_profile(): void
    {
        $this->signedIn($this->makeUser());

        $this->get('/account/nope')->assertNotFound();
        $this->get('/account?tab[]=security')->assertOk()->assertSee('id="profile-heading"', false);
    }

    public function test_the_profile_comes_back_to_its_tab_also_on_errors(): void
    {
        $this->signedIn($this->makeUser());

        $this->put('/account', ['name' => 'Ada', 'locale' => 'en', 'timezone' => 'UTC', 'shortcuts' => '1'])
            ->assertRedirect($this->tab('profile'))->assertSessionHas('status');

        $this->from($this->tab('profile'))->put('/account', ['name' => '', 'locale' => 'en', 'timezone' => 'UTC'])
            ->assertRedirect($this->tab('profile'))->assertSessionHasErrors('name');
    }

    public function test_security_forms_come_back_to_the_security_tab(): void
    {
        $user = $this->makeUser(['email' => 'me@example.test']);
        $passkey = $user->passkeys()->create(['name' => 'Laptop', 'credential_id' => bin2hex(random_bytes(8)), 'credential' => []]);
        $this->signedIn($user);

        $this->patch("/account/passkeys/{$passkey->id}", ['name' => 'Desk key'])->assertRedirect($this->tab('security'));
        $this->from($this->tab('security'))->patch("/account/passkeys/{$passkey->id}", ['name' => ''])
            ->assertRedirect($this->tab('security'))->assertSessionHasErrors('name');
        $this->from($this->tab('security'))->post('/account/email', ['email' => 'me@example.test'])
            ->assertRedirect($this->tab('security'))->assertSessionHasErrors('email');
        $this->get('/account/email/confirm')->assertRedirect($this->tab('security'));
        $this->delete("/account/passkeys/{$passkey->id}")->assertRedirect($this->tab('security'));
    }

    public function test_sessions_and_delete_come_back_to_their_tabs(): void
    {
        $user = $this->makeUser();
        $this->signedIn($user);

        $this->post('/account/sessions/revoke')->assertRedirect($this->tab('sessions'));

        $this->from($this->tab('delete'))->delete('/account', [])
            ->assertRedirect($this->tab('delete'))->assertSessionHasErrors('confirm');

        $org = Organisation::create(['name' => 'Maker Ltd', 'created_by' => $user->id]);
        DB::table('products')->insert(['id' => (string) Str::ulid(), 'organisation_id' => $org->id, 'owner_id' => $user->id,
            'name' => 'Router X', 'created_at' => now(), 'updated_at' => now()]);
        $this->delete('/account', ['confirm' => '1'])->assertRedirect($this->tab('delete'))->assertSessionHas('error');
        $this->assertNotNull(User::find($user->id));
    }

    public function test_confirming_it_is_you_returns_to_the_tab(): void
    {
        $this->signedIn($this->makeUser(), SignIn::EMAIL_LINK, time() - 3600);

        $this->get('/account/delete')->assertOk()->assertSee(route('account.reconfirm', ['tab' => 'delete']), false);
        $this->get(route('account.reconfirm', ['tab' => 'delete']))->assertRedirect(route('auth.confirm'));

        $this->signedIn($this->makeUser());
        $this->get(route('account.reconfirm', ['tab' => 'sessions']))->assertRedirect($this->tab('sessions'));
        $this->get(route('account.reconfirm'))->assertRedirect($this->tab('profile'));
        $this->get('/account/confirm/bogus')->assertNotFound();
    }

    public function test_the_keyboard_shortcuts_preference(): void
    {
        $user = $this->makeUser();
        $this->assertTrue($user->fresh()->shortcuts_enabled, 'On by default.');
        $this->signedIn($user);

        $this->get('/dashboard')->assertOk()
            ->assertDontSee('data-shortcuts="off"', false)
            ->assertSee('id="shortcuts-help"', false)
            ->assertSee('data-shortcut="g p"', false)
            ->assertSee('data-shortcuts-help', false);

        $form = ['name' => $user->name, 'locale' => 'en', 'timezone' => 'UTC'];
        $this->put('/account', $form + ['shortcuts' => '0'])->assertRedirect($this->tab('profile'));
        $this->assertFalse($user->fresh()->shortcuts_enabled);
        $this->get('/dashboard')->assertOk()->assertSee('data-shortcuts="off"', false);

        // Absent: unchanged (older clients, other forms).
        $this->put('/account', $form)->assertRedirect($this->tab('profile'));
        $this->assertFalse($user->fresh()->shortcuts_enabled);

        $this->put('/account', $form + ['shortcuts' => 'maybe'])->assertSessionHasErrors('shortcuts');

        $this->put('/account', $form + ['shortcuts' => '1']);
        $this->assertTrue($user->fresh()->shortcuts_enabled);
        $this->get('/account')->assertOk()->assertSee('data-shortcut="mod+s"', false);
    }
}
