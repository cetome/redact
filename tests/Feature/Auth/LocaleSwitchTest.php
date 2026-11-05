<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The language switch in the headers (LocaleController): nobody is held in a language they did not
 * choose. Signed in it is the account's language (Account > Profile too); otherwise this visit's.
 */
class LocaleSwitchTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    public function test_a_visitor_switches_and_stays_in_that_language_back_on_the_same_page(): void
    {
        $this->withHeader('Accept-Language', 'en')->get(route('pricing'))->assertOk()
            ->assertSee('action="'.route('locale.update').'"', false)->assertSee('lang="fr"', false);

        $this->from(route('pricing'))->post(route('locale.update'), ['locale' => 'fr'])->assertRedirect(route('pricing'));
        // The browser still asks for English: the choice wins.
        $this->withHeader('Accept-Language', 'en')->get(route('pricing'))->assertOk()->assertSee('<html lang="fr"', false);

        $this->post(route('locale.update'), ['locale' => 'de'])->assertSessionHasErrors('locale');
    }

    public function test_signed_in_the_switch_is_the_accounts_language(): void
    {
        $user = $this->makeUser(['locale' => 'en']);
        $this->signedIn($user)->get(route('dashboard'))->assertOk()->assertSee('action="'.route('locale.update').'"', false);

        // A picture loaded after the page (the demo bulb) is never where it goes back to.
        $this->withSession(['_previous' => ['url' => url('/products/x/image')]])
            ->from(route('dashboard'))->post(route('locale.update'), ['locale' => 'fr'])->assertRedirect(route('dashboard'));
        $this->assertSame('fr', $user->fresh()->locale);
        // Another site in the Referer, a backslash trick, another port: ignored, the dashboard instead.
        foreach (['https://evil.example/x', 'http://localhost\@evil.example/', 'http://localhost:8443/account', 'javascript:alert(1)'] as $bad) {
            $this->withHeader('referer', $bad)->post(route('locale.update'), ['locale' => 'en'])->assertRedirect(route('dashboard'));
        }
        // The same site: back there, the query kept, the address rebuilt here.
        $this->withHeader('referer', url('/account/security?tab=keys'))->post(route('locale.update'), ['locale' => 'en'])->assertRedirect(url('/account/security?tab=keys'));
        $this->get(route('account.show'))->assertOk()->assertSee('<option value="en" selected', false);
    }

    public function test_a_sandbox_guest_switches_language_too(): void
    {
        $guest = $this->makeUser(['locale' => 'en', 'is_guest' => true, 'guest_expires_at' => now()->addDay()]);
        $this->signedIn($guest)->from(route('dashboard'))->post(route('locale.update'), ['locale' => 'fr'])->assertRedirect(route('dashboard'));
        $this->assertSame('fr', $guest->fresh()->locale);
        $this->withHeader('Accept-Language', 'en')->get(route('dashboard'))->assertOk()->assertSee('<html lang="fr"', false);
    }
}
